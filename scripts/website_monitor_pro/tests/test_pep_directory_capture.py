"""No DNS/HTTP: synthetic sockets and responses; CLI probes have no valid destination."""
import base64
import hashlib
import io
import json
from pathlib import Path
import signal
import socket
import ssl
import subprocess
import sys
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import pep_directory_capture as capture

PUBLIC = "93.184.216.34"
URL = "http://directory.example/people?unit=1"
SCRIPT = Path(capture.__file__)


class FakeSocket:
    def __init__(self, address):
        self.address = address
        self.closed = False

    def getpeername(self):
        return self.address

    def close(self):
        self.closed = True


class FakeResponse:
    def __init__(self, body=b"<html>People</html>", status=200, headers=None):
        self.body = io.BytesIO(body)
        self.status = status
        self.headers = {"Content-Type": "text/html; charset=utf-8", **(headers or {})}
        self.reads = 0

    def getheader(self, key, default=None):
        return self.headers.get(key, default)

    def read(self, size):
        self.reads += 1
        return self.body.read(size)

    def close(self):
        self.body.close()


class FakeConnection:
    def __init__(self, response, host, port, **options):
        self.response, self.host, self.port = response, host, port
        self.options, self.requests = options, []
        self.sock = None
        self.closed = False

    def connect(self):
        self.sock = self._create_connection((self.host, self.port), self.options["timeout"], None)

    def request(self, method, target, **options):
        self.requests.append((method, target, options))

    def getresponse(self):
        return self.response

    def close(self):
        self.closed = True
        if self.sock:
            self.sock.close()


class CaptureTest(unittest.TestCase):
    def setUp(self):
        self.response = FakeResponse()
        self.connections = []
        self.sockets = []

        def connection(host, port, **options):
            result = FakeConnection(self.response, host, port, **options)
            self.connections.append(result)
            return result

        def connect(address, **options):
            result = FakeSocket(address)
            self.sockets.append(result)
            return result

        self.resolve = self.enterContext(patch.object(capture.socket, "getaddrinfo", return_value=[
            (socket.AF_INET, socket.SOCK_STREAM, socket.IPPROTO_TCP, "", (PUBLIC, 80)),
        ]))
        self.connect = self.enterContext(patch.object(capture.socket, "create_connection", side_effect=connect))
        self.http = self.enterContext(patch.object(capture.http.client, "HTTPConnection", side_effect=connection))
        self.https = self.enterContext(patch.object(capture.http.client, "HTTPSConnection", side_effect=connection))

    def test_http_capture_is_pinned_bounded_and_explicitly_unencrypted(self):
        result = capture.capture_url(URL)
        self.assertEqual(result["requested_url"], URL)
        self.assertEqual(result["final_url"], URL)
        self.assertEqual(result["status"], 200)
        self.assertEqual(result["encoding"], "utf-8")
        body = base64.b64decode(result["body_base64"], validate=True)
        self.assertEqual(body, b"<html>People</html>")
        self.assertEqual(result["content_sha256"], hashlib.sha256(body).hexdigest())
        self.assertEqual(result["transport"], {"adapter": "public-http-pinned-v1", "peer_ip": PUBLIC, "tls_verified": False})
        self.assertEqual(self.resolve.call_count, 1)
        self.assertEqual(self.connect.call_args.args, ((PUBLIC, 80),))
        self.assertEqual(self.connections[0].host, "directory.example")
        self.assertEqual(self.connections[0].requests, [("GET", "/people?unit=1", {"headers": {"Accept-Encoding": "identity", "User-Agent": "SIMO-PEP-capture/1"}})])
        self.assertTrue(self.connections[0].closed)
        self.assertTrue(self.sockets[0].closed)
        self.assertTrue(self.response.body.closed)

    def test_https_keeps_original_hostname_and_certificate_verification(self):
        result = capture.capture_url(URL.replace("http:", "https:"))
        connection = self.connections[0]
        self.assertEqual(connection.host, "directory.example")
        self.assertEqual(connection.port, 443)
        self.assertTrue(connection.options["context"].check_hostname)
        self.assertEqual(connection.options["context"].verify_mode, ssl.CERT_REQUIRED)
        self.assertTrue(result["transport"]["tls_verified"])
        self.assertEqual(result["transport"]["adapter"], "public-https-pinned-v1")
        self.http.assert_not_called()

    def test_invalid_tls_never_downgrades_to_http(self):
        self.https.side_effect = ssl.SSLCertVerificationError("synthetic invalid certificate")
        with self.assertRaises(ssl.SSLCertVerificationError):
            capture.capture_url(URL.replace("http:", "https:"))
        self.http.assert_not_called()
        self.connect.assert_not_called()

    def test_private_special_and_mixed_dns_answers_are_refused_before_connect(self):
        for address in ["127.0.0.1", "10.0.0.1", "169.254.169.254", "172.16.0.1", "192.168.1.1", "100.64.0.1", "192.0.0.8", "192.88.99.1", "198.18.0.1", "224.0.0.1", "::1", "fc00::1", "fe80::1", "ff02::1", "fec0::1", "feff:ffff:ffff:ffff:ffff:ffff:ffff:ffff", "::ffff:93.184.216.34", "64:ff9b::7f00:1", "2002:7f00:1::1"]:
            with self.subTest(address=address):
                self.resolve.return_value = [(socket.AF_INET, socket.SOCK_STREAM, 6, "", (PUBLIC, 80)),
                                             (socket.AF_INET6 if ":" in address else socket.AF_INET, socket.SOCK_STREAM, 6, "", (address, 80))]
                with self.assertRaisesRegex(ValueError, "^capture refused$"):
                    capture.capture_url(URL)
                self.connect.assert_not_called()
                self.http.assert_not_called()

    def test_invalid_url_credentials_controls_and_schemes_refuse_before_dns(self):
        for url in ["file:///etc/passwd", "ftp://directory.example/a", "http://user:pass@directory.example/a", "http://directory.example/a#fragment", "http://directory.example/\r\nX: y", "http://directory.example:0/a", "http://[fe80::1%25eth0]/a", "http://directory.example/" + "x" * 2048]:
            with self.subTest(url=url):
                with self.assertRaises(ValueError):
                    capture.capture_url(url)
                self.resolve.assert_not_called()
                self.connect.assert_not_called()

    def test_unexpected_connected_peer_refuses_before_http_request(self):
        sock = FakeSocket(("127.0.0.1", 80))
        self.connect.side_effect = lambda *args, **kwargs: sock
        with self.assertRaisesRegex(ValueError, "^capture refused$"):
            capture.capture_url(URL)
        self.assertTrue(sock.closed)
        self.assertEqual(self.connections[0].requests, [])
        self.assertTrue(self.connections[0].closed)

    def test_redirect_is_refused_without_following_location(self):
        self.response = FakeResponse(status=302, headers={"Location": "http://127.0.0.1/private"})
        with self.assertRaisesRegex(ValueError, "^capture refused$"):
            capture.capture_url(URL)
        self.assertEqual(len(self.connections), 1)
        self.assertEqual(len(self.connections[0].requests), 1)
        self.assertEqual(self.response.reads, 0)
        self.assertTrue(self.connections[0].closed)

    def test_body_limit_and_declared_length_failures_close_resources(self):
        for body, headers in [(b"x" * (capture.MAX_BODY + 1), {}), (b"x", {"Content-Length": str(capture.MAX_BODY + 1)}), (b"x", {"Content-Length": "2"}), (b"x", {"Content-Length": "-1"}), (b"x", {"Content-Encoding": "gzip"})]:
            with self.subTest(headers=headers, size=len(body)):
                self.response = FakeResponse(body=body, headers=headers)
                with self.assertRaisesRegex(ValueError, "^capture refused$"):
                    capture.capture_url(URL)
                self.assertTrue(self.connections[-1].closed)
                self.assertTrue(self.response.body.closed)

    def test_exact_body_boundary_and_http_error_are_retained(self):
        self.response = FakeResponse(body=b"x" * capture.MAX_BODY, status=503, headers={"Content-Length": str(capture.MAX_BODY)})
        result = capture.capture_url(URL)
        self.assertEqual(len(base64.b64decode(result["body_base64"])), capture.MAX_BODY)
        self.assertEqual(result["status"], 503)

    def test_proxy_environment_does_not_change_pinned_destination(self):
        with patch.dict("os.environ", {"HTTP_PROXY": "http://127.0.0.1:1234", "HTTPS_PROXY": "http://127.0.0.1:1234"}):
            capture.capture_url(URL)
        self.assertEqual(self.connect.call_args.args, ((PUBLIC, 80),))


class CaptureCliTest(unittest.TestCase):
    def test_invalid_frames_have_exact_error_and_no_capture_output(self):
        for data in [b"{}", b"[]", b'{"url":true}', b'{"url":"file:///etc/passwd"}', b'{"url":"http://directory.example/","raw_body":"forged"}', b'{"url":"x","url":"y"}', b"x" * (capture.MAX_INPUT + 1)]:
            with self.subTest(data=data[:80]):
                result = subprocess.run([sys.executable, "-B", str(SCRIPT)], input=data, capture_output=True, timeout=3)
                self.assertEqual((result.returncode, result.stdout, result.stderr), (2, b"", b"capture: request refused or transport failed\n"))

    def test_stalled_stdin_is_terminated_by_real_process_deadline(self):
        with subprocess.Popen([sys.executable, "-B", str(SCRIPT)], stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE) as process:
            try:
                process.wait(timeout=capture.TIMEOUT + 3)
                self.assertEqual(process.returncode, -signal.SIGALRM)
                self.assertEqual(process.stdout.read(), b"")
                self.assertEqual(process.stderr.read(), b"")
            finally:
                if process.poll() is None:
                    process.kill()
                    process.wait(timeout=1)


if __name__ == "__main__":
    unittest.main()
