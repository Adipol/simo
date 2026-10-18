"""Regression tests for PEP monitor outbound URL and image safety."""

import os
import socket
import sys
import types
from email.message import Message
from unittest.mock import MagicMock, patch

import pytest

sys.path.insert(0, os.path.join(os.path.dirname(__file__), ".."))

import pep_monitor


PUBLIC_DNS = [
    (socket.AF_INET, socket.SOCK_STREAM, 6, "", ("93.184.216.34", 443)),
]


class FakeResponse:
    def __init__(self, status=200, headers=None, chunks=(), raw=None):
        self.status_code = status
        self.headers = headers or {}
        self._chunks = chunks
        self.raw = raw or types.SimpleNamespace(_original_response=None)
        self.closed = False

    def raise_for_status(self): return None

    @property
    def content(self):
        raise AssertionError("streamed downloads must not access response.content")

    def iter_content(self, chunk_size):
        yield from self._chunks

    def close(self):
        self.closed = True


def _image(url="https://images.example/photo.png"):
    return {"src": url, "src_absoluto": url, "mime_hint": "image/png"}


def _session(get_responses, head_headers=None):
    session = MagicMock()
    session.head.return_value = FakeResponse(
        headers=head_headers or {"Content-Type": "image/png"}
    )
    session.get.side_effect = get_responses
    return session


@pytest.fixture
def public_dns():
    with patch("socket.getaddrinfo", return_value=PUBLIC_DNS):
        yield


@pytest.mark.parametrize(
    "url",
    [
        "http://example.com/source",
        "https://example.com/source",
        "http://8.8.8.8/source",
        "https://[2606:4700:4700::1111]/source",
    ],
)
def test_public_http_urls_are_accepted(url):
    with patch("socket.getaddrinfo", return_value=PUBLIC_DNS):
        pep_monitor.validate_public_http_url(url)


@pytest.mark.parametrize("url", ["ftp://example.com/a", "file:///etc/passwd", "data:text/plain,x"])
def test_unsupported_url_schemes_are_rejected(url):
    with pytest.raises(pep_monitor.OutboundUrlError):
        pep_monitor.validate_public_http_url(url)


@pytest.mark.parametrize(
    "url",
    [
        "http://127.0.0.1/a",
        "http://10.0.0.1/a",
        "http://169.254.1.1/a",
        "http://224.0.0.1/a",
        "http://192.0.2.1/a",
        "http://0.0.0.0/a",
        "http://[::1]/a",
        "http://[fc00::1]/a",
        "http://[fe80::1]/a",
        "http://[ff00::1]/a",
        "http://[::]/a",
        "http://[2001:db8::1]/a",
    ],
)
def test_non_global_literal_destinations_are_rejected(url):
    with pytest.raises(pep_monitor.OutboundUrlError):
        pep_monitor.validate_public_http_url(url)


@pytest.mark.parametrize("resolved_ip", ["127.0.0.1", "10.0.0.8", "::1", "fe80::1"])
def test_hostname_resolving_to_any_non_global_address_is_rejected(resolved_ip):
    addresses = PUBLIC_DNS + [
        (socket.AF_INET6, socket.SOCK_STREAM, 6, "", (resolved_ip, 443, 0, 0))
        if ":" in resolved_ip
        else (socket.AF_INET, socket.SOCK_STREAM, 6, "", (resolved_ip, 443))
    ]
    with patch("socket.getaddrinfo", return_value=addresses):
        with pytest.raises(pep_monitor.OutboundUrlError):
            pep_monitor.validate_public_http_url("https://example.com/source")


def test_hostname_resolution_failure_is_rejected():
    with patch("socket.getaddrinfo", side_effect=socket.gaierror("not found")):
        with pytest.raises(pep_monitor.OutboundUrlError):
            pep_monitor.validate_public_http_url("https://missing.example/source")


def test_localhost_hostname_is_rejected():
    localhost = [
        (socket.AF_INET, socket.SOCK_STREAM, 6, "", ("127.0.0.1", 80)),
    ]
    with patch("socket.getaddrinfo", return_value=localhost):
        with pytest.raises(pep_monitor.OutboundUrlError):
            pep_monitor.validate_public_http_url("http://localhost/source")


def test_add_fuente_rejects_url_before_database_access():
    db = object.__new__(pep_monitor.DatabaseManager)
    db._ensure_connection = MagicMock()
    db.cursor = MagicMock()

    with patch("socket.getaddrinfo", return_value=PUBLIC_DNS + [
        (socket.AF_INET, socket.SOCK_STREAM, 6, "", ("127.0.0.1", 443))
    ]):
        with pytest.raises(pep_monitor.OutboundUrlError):
            db.add_fuente("https://example.com", "Name", "BO", "Org")

    db._ensure_connection.assert_not_called()
    db.cursor.execute.assert_not_called()


@pytest.mark.parametrize("url", ["https://user@images.example/a", "https://user:pass@images.example/a", "https://\ud800.example/a"])
def test_invalid_authority_rejects_before_dns_database_or_network(url):
    db = object.__new__(pep_monitor.DatabaseManager)
    db._ensure_connection, db.cursor = MagicMock(), MagicMock()
    session = MagicMock()
    with patch("socket.getaddrinfo") as resolve:
        for action in (lambda: db.add_fuente(url, "Name", "BO", "Org"),
                       lambda: pep_monitor._request_image_url(session, "HEAD", url, 15)):
            with pytest.raises(pep_monitor.OutboundUrlError):
                action()
    assert not (resolve.called or db._ensure_connection.called or db.cursor.execute.called or session.head.called)


def test_cli_add_validates_dns_once_before_persistence():
    db = object.__new__(pep_monitor.DatabaseManager)
    db._ensure_connection, db.cursor, db.close = MagicMock(), MagicMock(), MagicMock()
    db.cursor.fetchone.return_value = {"id": 1}
    argv = ["pep_monitor.py", "add", "https://example.com/source", "--nombre", "Name", "--pais", "BO", "--organismo", "Org"]
    with (
        patch.object(sys, "argv", argv),
        patch("socket.getaddrinfo", return_value=PUBLIC_DNS) as resolve,
        patch.object(pep_monitor, "DatabaseManager", return_value=db),
        pytest.raises(SystemExit) as exit_info,
    ):
        pep_monitor.main()
    assert (exit_info.value.code, resolve.call_count, db._ensure_connection.call_count, db.cursor.execute.call_count, db.close.call_count) == (0, 1, 1, 1, 1)


@pytest.mark.parametrize(
    ("url", "target", "host", "dns_host", "public_url"),
    [
        ("https://images.example/photo.png", "https://93.184.216.34:443/photo.png", "images.example", "images.example", "https://images.example/photo.png"),
        ("https://bücher.example/photo.png", "https://93.184.216.34:443/photo.png", "xn--bcher-kva.example", "xn--bcher-kva.example", "https://xn--bcher-kva.example/photo.png"),
        ("https://[2606:4700:4700::1111]:8443/photo.png", "https://[2606:4700:4700::1111]:8443/photo.png", "[2606:4700:4700::1111]:8443", None, "https://[2606:4700:4700::1111]:8443/photo.png"),
    ],
)
def test_https_transport_is_isolated_and_preserves_identity(url, target, host, dns_host, public_url):
    captured = {}
    def fake_send(adapter, request, **kwargs):
        pool = adapter.poolmanager.connection_pool_kw
        captured.update(target=request.url, host=request.headers["Host"], headers=request.headers,
                        identity=(pool.get("server_hostname"), pool.get("assert_hostname")), proxies=kwargs["proxies"])
        return FakeResponse()
    session = pep_monitor.create_http_session()
    session.headers["X-Test"] = "preserved"; session.cookies.set("sid", "cookie"); session.auth = ("user", "pass")
    before = list(session.adapters.items())
    with (
        patch("socket.getaddrinfo", return_value=PUBLIC_DNS) as resolve,
        patch.object(pep_monitor.HTTPAdapter, "send", new=fake_send),
        patch.object(pep_monitor._PinnedIPAdapter, "close", autospec=True) as close,
    ):
        response = pep_monitor._request_image_url(session, "GET", url, 15, stream=True)
        assert list(session.adapters.items()) == before and close.call_count == 0
        response.close(); response.close()
    assert ([call.args[0] for call in resolve.call_args_list] or [None]) == [dns_host]
    assert (captured["target"], captured["host"], captured["identity"], captured["proxies"], response.url) == (target, host, (dns_host or pep_monitor.urlparse(url).hostname,) * 2, {}, public_url)
    assert "sid=cookie" in captured["headers"]["Cookie"] and "Authorization" in captured["headers"] and captured["headers"]["X-Test"] == "preserved" and close.call_count == 1


def test_redirect_revalidates_and_repins_to_the_new_host():
    targets = []; dns = {"images.example": "93.184.216.34", "cdn.example": "142.250.72.14"}
    def resolve(host, port, **kwargs): return [(socket.AF_INET, socket.SOCK_STREAM, 6, "", (dns[host], port))]
    def fake_send(adapter, request, **kwargs):
        targets.append((request.url, request.headers["Host"], id(adapter), dict(request.headers)))
        return FakeResponse(302, {"Location": "https://cdn.example/final.png"}) if len(targets) == 1 else FakeResponse()
    session = pep_monitor.create_http_session()
    session.headers["Proxy-Authorization"] = "Basic proxy"
    session.cookies.set("sid", "cookie")
    session.auth = ("user", "pass")
    before = list(session.adapters.items())
    with (
        patch("socket.getaddrinfo", side_effect=resolve) as resolver,
        patch.object(pep_monitor.HTTPAdapter, "send", new=fake_send),
        patch.object(pep_monitor._PinnedIPAdapter, "close", autospec=True) as close,
    ):
        response = pep_monitor._request_image_url(session, "GET", "https://images.example/start.png", 30, stream=True)
        assert close.call_count == 1
        response.close()
    assert [call.args[0] for call in resolver.call_args_list] == ["images.example", "cdn.example"]
    assert [target[:2] for target in targets] == [("https://93.184.216.34:443/start.png", "images.example"),
                                                   ("https://142.250.72.14:443/final.png", "cdn.example")]
    assert targets[0][2] != targets[1][2] and close.call_count == 2 and list(session.adapters.items()) == before
    credentials = {"Authorization", "Proxy-Authorization", "Cookie"}
    assert credentials <= targets[0][3].keys()
    assert credentials.isdisjoint(targets[1][3])


def test_pinned_send_tries_all_validated_addresses_without_reresolving():
    addresses = [
        (socket.AF_INET, socket.SOCK_STREAM, 6, "", ("93.184.216.34", 443)),
        (socket.AF_INET, socket.SOCK_STREAM, 6, "", ("93.184.216.35", 443)),
    ]
    targets = []
    def fake_send(adapter, request, **kwargs):
        targets.append(request.url)
        if len(targets) == 1:
            raise pep_monitor.requests.ConnectionError("first address failed")
        return FakeResponse()
    session = pep_monitor.create_http_session()
    with (
        patch("socket.getaddrinfo", return_value=addresses) as resolve,
        patch.object(pep_monitor.HTTPAdapter, "send", new=fake_send),
        patch.object(pep_monitor._PinnedIPAdapter, "close", autospec=True) as close,
    ):
        response = pep_monitor._request_image_url(session, "GET", "https://images.example/a", 30)
        response.close()
    assert resolve.call_count == 1
    assert targets == ["https://93.184.216.34:443/a", "https://93.184.216.35:443/a"]
    assert close.call_count == 2


def test_pinned_send_failure_closes_transport_without_mutating_session():
    session = pep_monitor.create_http_session()
    before = list(session.adapters.items())
    with (
        patch("socket.getaddrinfo", return_value=PUBLIC_DNS),
        patch.object(pep_monitor.HTTPAdapter, "send", side_effect=pep_monitor.requests.RequestException("boom")),
        patch.object(pep_monitor._PinnedIPAdapter, "close", autospec=True) as close,
        pytest.raises(pep_monitor.requests.RequestException),
    ):
        pep_monitor._request_image_url(session, "GET", "https://images.example/a", 30)
    assert close.call_count == 1 and list(session.adapters.items()) == before


@pytest.mark.parametrize(
    ("head_headers", "chunks"),
    [
        ({"Content-Type": "image/png"}, [b"1234", b"5678"]),
        ({"Content-Type": "image/png", "Content-Length": "2"}, [b"1234", b"5678"]),
    ],
)
def test_oversized_stream_is_rejected_without_trusting_content_length(
    public_dns, head_headers, chunks
):
    response = FakeResponse(headers={"Content-Type": "image/png"}, chunks=chunks)
    session = _session([response], head_headers)

    analyzed, metadata = pep_monitor.comparar_imagenes_cascada(
        [_image()], [], session, max_image_bytes=7
    )

    assert analyzed == []
    assert metadata == []
    assert response.closed is True


@pytest.mark.parametrize(("chunks", "limit"), [([b"123", b"456"], 6), ([b"12"], 6)])
def test_exact_and_under_limit_streams_succeed(public_dns, chunks, limit):
    body = b"".join(chunks)
    response = FakeResponse(headers={"Content-Type": "image/png"}, chunks=chunks)
    session = _session([response])

    analyzed, metadata = pep_monitor.comparar_imagenes_cascada(
        [_image()], [], session, max_image_bytes=limit
    )

    assert analyzed[0]["bytes"] == body
    assert metadata[0]["content_length"] == len(body)


def test_public_redirect_to_private_destination_is_rejected(public_dns):
    redirect = FakeResponse(
        status=302,
        headers={"Location": "http://127.0.0.1/private.png"},
    )
    session = _session([redirect])

    analyzed, metadata = pep_monitor.comparar_imagenes_cascada(
        [_image()], [], session
    )

    assert analyzed == []
    assert metadata == []
    assert session.get.call_count == 1


def test_head_redirect_to_private_destination_is_rejected(public_dns):
    session = MagicMock()
    session.head.return_value = FakeResponse(
        status=302,
        headers={"Location": "http://[::1]/private.png"},
    )

    analyzed, metadata = pep_monitor.comparar_imagenes_cascada(
        [_image()], [], session
    )

    assert analyzed == []
    assert metadata == []
    session.get.assert_not_called()


def test_image_redirect_count_is_bounded(public_dns):
    redirects = [
        FakeResponse(
            status=302,
            headers={"Location": f"https://images.example/{index}.png"},
        )
        for index in range(6)
    ]
    session = _session(redirects)

    analyzed, metadata = pep_monitor.comparar_imagenes_cascada(
        [_image()], [], session
    )

    assert analyzed == []
    assert metadata == []
    assert session.get.call_count == 6


def test_primary_html_uses_pinned_request_and_rejects_private_redirect(public_dns):
    redirect = FakeResponse(302, {"Location": "http://127.0.0.1/private"})
    targets = []
    def fake_send(adapter, request, **kwargs): targets.append(request.url); return redirect
    with pep_monitor.create_http_session() as session:
        monitor = object.__new__(pep_monitor.PEPMonitor)
        monitor.http = session
        with patch.object(pep_monitor.HTTPAdapter, "send", new=fake_send):
            html, method = monitor._obtener_html_raw({"url": "https://example.com/source", "tipo": "html"})
    assert (html, method) == ("", "error_http")
    assert targets == ["https://93.184.216.34:443/source"] and redirect.closed


def test_pdf_uses_pinned_request_and_rejects_oversized_stream():
    response = FakeResponse(chunks=[b"1234", b"5678"])
    pdfplumber = types.SimpleNamespace(open=MagicMock())
    session = MagicMock()
    with (
        patch.dict(sys.modules, {"pdfplumber": pdfplumber}),
        patch.object(pep_monitor.config, "PDF_MAX_BYTES", 7),
        patch.object(pep_monitor, "_request_public_url", return_value=response) as fetch,
    ):
        lines, method = pep_monitor.limpiar_pdf("https://example.com/file.pdf", session)
    assert (lines, method) == ([], "error_pdf")
    fetch.assert_called_once_with(session, "GET", "https://example.com/file.pdf", pep_monitor.config.PDF_TIMEOUT, stream=True)
    assert not pdfplumber.open.called and response.closed


def test_playwright_blocks_service_workers_and_fulfills_via_pinned_fetch():
    url = "https://example.com/app"
    response = FakeResponse(headers={"Content-Type": "text/html", "Content-Length": "99", "Content-Encoding": "gzip",
                                     "Transfer-Encoding": "chunked", "Connection": "keep-alive"}, chunks=[b"safe"])
    route = MagicMock()
    request = types.SimpleNamespace(method="GET", url=url, headers={})
    manager, runtime = MagicMock(), MagicMock()
    manager.__enter__.return_value = runtime
    browser = runtime.chromium.launch.return_value
    context = browser.new_context.return_value
    page = context.new_page.return_value
    page.content.return_value = "<html>rendered</html>"
    page.goto.side_effect = lambda *args, **kwargs: context.route.call_args.args[1](route, request)
    sync_api = types.SimpleNamespace(sync_playwright=MagicMock(return_value=manager))
    with (
        patch.dict(sys.modules, {"playwright": types.SimpleNamespace(), "playwright.sync_api": sync_api}),
        patch.object(pep_monitor, "_request_public_url", return_value=response) as fetch,
    ):
        html, method = pep_monitor.obtener_html_js(url, MagicMock())
    assert (html, method) == ("<html>rendered</html>", "js_playwright")
    assert browser.new_context.call_args.kwargs["service_workers"] == "block"
    assert fetch.call_args.kwargs["stream"] is True
    assert route.fulfill.call_args.kwargs == {"status": 200, "headers": {"Content-Type": "text/html"}, "body": b"safe"}
    route.continue_.assert_not_called()
    assert response.closed and context.close.called and browser.close.called


@pytest.mark.parametrize(("method", "body"), [("POST", b'{"id": 7}'), ("OPTIONS", None)])
def test_playwright_proxies_body_methods_via_pinned_fetch(method, body):
    response = FakeResponse(chunks=[b"safe"])
    route = MagicMock()
    request = types.SimpleNamespace(method=method, url="https://example.com/api", headers={}, post_data_buffer=body)
    session = MagicMock()
    with patch.object(pep_monitor, "_request_public_url", return_value=response) as fetch:
        pep_monitor._playwright_route_handler(route, request, session)
    fetch.assert_called_once_with(session, method, request.url, pep_monitor.config.REQUEST_TIMEOUT,
                                  stream=True, headers={}, data=body)
    assert route.fulfill.call_args.kwargs["body"] == b"safe"
    assert response.closed and not route.abort.called and not route.continue_.called


def test_playwright_aborts_private_destination():
    route = MagicMock()
    request = types.SimpleNamespace(method="GET", url="http://127.0.0.1/private", headers={})
    with pep_monitor.create_http_session() as session:
        pep_monitor._playwright_route_handler(route, request, session)
    route.abort.assert_called_once_with()
    assert not route.fulfill.called and not route.continue_.called


@pytest.mark.parametrize(("status", "next_method"), [(301, "GET"), (302, "GET"), (303, "GET"), (307, "POST"), (308, "POST")])
def test_post_redirect_method_and_body_semantics(status, next_method):
    sent = []
    def fake_send(adapter, request, **kwargs):
        sent.append((request.method, request.body, dict(request.headers)))
        return FakeResponse(status, {"Location": "/next"}) if len(sent) == 1 else FakeResponse()
    payload = b'{"id": 7}'
    with (
        pep_monitor.create_http_session() as session,
        patch("socket.getaddrinfo", return_value=PUBLIC_DNS),
        patch.object(pep_monitor.HTTPAdapter, "send", new=fake_send),
    ):
        response = pep_monitor._request_public_url(session, "POST", "https://example.com/start", 20,
                                                   headers={"Host": "stale", "Content-Length": "999", "Content-Type": "application/json"}, data=payload)
        response.close()
    assert sent[0][0:2] == ("POST", payload) and sent[0][2]["Content-Length"] == str(len(payload))
    assert sent[0][2]["Host"] == "example.com" and sent[1][0] == next_method
    assert sent[1][1] == (payload if next_method == "POST" else None)
    assert ("Content-Type" in sent[1][2]) is (next_method == "POST")


def test_same_origin_redirect_carries_response_cookie_to_next_request():
    sent_cookies = []
    cookie_headers = Message()
    cookie_headers["Set-Cookie"] = "sid=redirect-cookie; Path=/"
    raw = types.SimpleNamespace(_original_response=types.SimpleNamespace(msg=cookie_headers))

    def fake_send(adapter, request, **kwargs):
        sent_cookies.append(request.headers.get("Cookie"))
        return FakeResponse(302, {"Location": "/next"}, raw=raw) if len(sent_cookies) == 1 else FakeResponse()

    with (
        pep_monitor.create_http_session() as session,
        patch("socket.getaddrinfo", return_value=PUBLIC_DNS),
        patch.object(pep_monitor.HTTPAdapter, "send", new=fake_send),
    ):
        response = pep_monitor._request_public_url(session, "GET", "https://example.com/start", 20)
        response.close()

    assert sent_cookies == [None, "sid=redirect-cookie"]
