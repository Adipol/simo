"""Internal POSIX capture CLI. Not wired to PHP, persistence or the monitor.

Only the CLI owns the process deadline; capture_url is a synchronous primitive.
No redirects, proxies, cookies, credentials, retries or TLS downgrade.
"""
import base64
import codecs
from datetime import datetime, timezone
from email.message import Message
import hashlib
import http.client
import ipaddress
import json
import re
import signal
import socket
import ssl
import sys
from urllib.parse import quote, urlsplit

MAX_BODY = 1_048_576
MAX_INPUT = 4096
TIMEOUT = 8
# Conservative special-purpose exclusions, also on older ipaddress releases.
SPECIAL = tuple(ipaddress.ip_network(value) for value in (
    "192.0.0.0/24", "192.88.99.0/24", "64:ff9b::/96", "64:ff9b:1::/48",
    "2001::/23", "2002::/16", "fec0::/10",
))


def _need(condition: bool) -> None:
    if not condition:
        raise ValueError("capture refused")


def _public(value: str):
    address = ipaddress.ip_address(value)
    _need(address.is_global and not any((address.is_private, address.is_loopback,
          address.is_link_local, address.is_multicast, address.is_reserved, address.is_unspecified)))
    _need(not getattr(address, "ipv4_mapped", None) and not getattr(address, "scope_id", None))
    _need(not any(address in network for network in SPECIAL if address.version == network.version))
    return address


def capture_url(url: str) -> dict:
    """Server-owned URL only. Caller must use the deadline-owning CLI in production."""
    _need(isinstance(url, str) and len(url.encode("utf-8")) <= 2048
          and not any(character.isspace() or ord(character) < 32 or ord(character) == 127 for character in url))
    parsed = urlsplit(url)
    _need(parsed.scheme in ("http", "https") and parsed.hostname is not None
          and parsed.username is None and parsed.password is None and not parsed.fragment)
    host = parsed.hostname.encode("idna").decode("ascii")
    _need("%" not in host and "\\" not in host)
    port = parsed.port if parsed.port is not None else (443 if parsed.scheme == "https" else 80)
    _need(1 <= port <= 65535)
    answers = socket.getaddrinfo(host, port, type=socket.SOCK_STREAM)
    _need(0 < len(answers) <= 32)
    addresses = []
    for family, kind, _, _, address in answers:
        _need(family in (socket.AF_INET, socket.AF_INET6) and kind == socket.SOCK_STREAM)
        addresses.append(_public(address[0]))
    pinned = addresses[0]

    def connect(address, timeout=TIMEOUT, source_address=None):
        _need(address == (host, port) and source_address is None)
        sock = socket.create_connection((str(pinned), port), timeout=timeout)
        try:
            _need(_public(sock.getpeername()[0]) == pinned)
            return sock
        except Exception:
            sock.close()
            raise

    connection = (http.client.HTTPSConnection(host, port, timeout=TIMEOUT, context=ssl.create_default_context())
                  if parsed.scheme == "https" else http.client.HTTPConnection(host, port, timeout=TIMEOUT))
    response = None
    try:
        # Pin before TLS: HTTPSConnection still verifies/SNIs the original hostname.
        connection._create_connection = connect
        connection.connect()
        target = quote(parsed.path or "/", safe="/%:@!$&'()*+,;=-._~")
        if parsed.query:
            target += "?" + quote(parsed.query, safe="/%?:@!$&'()*+,;=-._~")
        connection.request("GET", target, headers={"Accept-Encoding": "identity", "User-Agent": "SIMO-PEP-capture/1"})
        response = connection.getresponse()
        _need(100 <= response.status <= 599 and not 300 <= response.status < 400)
        _need(response.getheader("Content-Encoding", "identity").strip().lower() == "identity")
        length = response.getheader("Content-Length")
        _need(length is None or (re.fullmatch(r"[0-9]{1,10}", length) is not None and int(length) <= MAX_BODY))
        content_type = response.getheader("Content-Type", "")
        _need(len(content_type) <= 512)
        message = Message()
        message["Content-Type"] = content_type
        encoding = message.get_content_charset() or "utf-8"
        _need(re.fullmatch(r"[A-Za-z0-9_.-]{1,64}", encoding) is not None)
        _need(codecs.lookup(encoding)._is_text_encoding)
        body = bytearray()
        while True:
            chunk = response.read(min(65536, MAX_BODY + 1 - len(body)))
            if not chunk:
                break
            body.extend(chunk)
            _need(len(body) <= MAX_BODY)
        _need(length is None or len(body) == int(length))
        return {"format_version": 1, "requested_url": url, "final_url": url,
                "status": response.status, "encoding": encoding,
                "captured_at": datetime.now(timezone.utc).isoformat(),
                "body_base64": base64.b64encode(body).decode("ascii"),
                "content_sha256": hashlib.sha256(body).hexdigest(),
                "transport": {"adapter": f"public-{parsed.scheme}-pinned-v1", "peer_ip": str(pinned),
                              "tls_verified": parsed.scheme == "https"}}
    finally:
        try:
            if response is not None:
                response.close()
        finally:
            connection.close()


def _object(pairs: list) -> dict:
    result = {}
    for key, value in pairs:
        _need(key not in result)
        result[key] = value
    return result


def main() -> int:
    if sys.argv[1:] == ["--help"]:
        print('Internal POSIX capture CLI: stdin {"url":"http(s)://public-host/path"}; JSON stdout.\n'
              '8-second process deadline; 1 MiB identity body; no redirects/proxies/private IPs; HTTPS verifies TLS.\n'
              'HTTP is unencrypted. Not connected to the application or monitor. Requires explicit network authorization.')
        return 0
    try:
        _need(not sys.argv[1:])
        # Default OS signal action terminates even while native DNS code is blocked.
        # Do not replace this with a Python handler that may run only after DNS returns.
        signal.signal(signal.SIGALRM, signal.SIG_DFL)
        signal.setitimer(signal.ITIMER_REAL, TIMEOUT)
        raw = sys.stdin.buffer.read(MAX_INPUT + 1)
        _need(len(raw) <= MAX_INPUT)
        request = json.loads(raw, object_pairs_hook=_object)
        _need(type(request) is dict and set(request) == {"url"} and type(request["url"]) is str)
        result = capture_url(request["url"])
        print(json.dumps(result, sort_keys=True, separators=(",", ":")), flush=True)
        return 0
    except (ValueError, OSError, http.client.HTTPException, LookupError):
        print("capture: request refused or transport failed", file=sys.stderr, flush=True)
        return 2
    finally:
        signal.setitimer(signal.ITIMER_REAL, 0)


if __name__ == "__main__":
    raise SystemExit(main())
