<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Contracts\HostResolver;
use App\Services\Scraper\DTOs\SiteValidationResult;
use DOMDocument;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\CssSelector\CssSelectorConverter;
use Throwable;

final class SiteUrlValidator
{
    private const MAX_REDIRECTS = 5;

    private const MAX_BODY_BYTES = 2_000_000;

    private const ARTICLE_SAMPLE_SIZE = 3;

    public function __construct(private readonly HostResolver $hostResolver) {}

    public function validate(string $url, ?string $selectorLinks = null, ?string $selectorArticle = null): SiteValidationResult
    {
        try {
            [$response, $resolvedUrl] = $this->fetchFollowingSafeRedirects($url);
            if (! $response->successful()) {
                return new SiteValidationResult(false, "La portada respondió HTTP {$response->status()}.", $resolvedUrl);
            }

            $body = $this->boundedBody($response);
            if ($this->looksLikeChallenge($body, $response)) {
                return new SiteValidationResult(false, 'La portada presenta un bloqueo o desafío anti-bot.', $resolvedUrl);
            }

            $candidates = $this->articleCandidates($body, $resolvedUrl, $selectorLinks);
            if ($candidates === []) {
                return new SiteValidationResult(false, $selectorLinks === null || trim($selectorLinks) === ''
                    ? 'No se encontraron enlaces de artículos del mismo sitio.'
                    : 'El selector de enlaces configurado no encontró enlaces de artículos válidos.', $resolvedUrl);
            }

            foreach (array_slice($candidates, 0, self::ARTICLE_SAMPLE_SIZE) as $candidate) {
                try {
                    [$articleResponse, $articleUrl] = $this->fetchFollowingSafeRedirects($candidate);
                } catch (ConnectionException) {
                    continue;
                }
                if (! $articleResponse->successful()) {
                    continue;
                }

                $articleBody = $this->boundedBody($articleResponse);
                if (! $this->looksLikeChallenge($articleBody, $articleResponse) && $this->hasMeaningfulArticle($articleBody, $selectorArticle)) {
                    return new SiteValidationResult(true, 'Validación completada: se encontró y leyó contenido periodístico real.', $articleUrl, count($candidates));
                }
            }

            return new SiteValidationResult(false, $selectorArticle === null || trim($selectorArticle) === ''
                ? 'Se encontraron enlaces, pero la muestra no contiene título y contenido periodístico suficiente.'
                : 'El selector de artículo configurado no encontró contenido periodístico suficiente en la muestra.', $resolvedUrl, count($candidates));
        } catch (ConnectionException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return new SiteValidationResult(false, $exception->getMessage());
        }
    }

    /** @return array{Response, string} */
    private function fetchFollowingSafeRedirects(string $url): array
    {
        for ($redirects = 0; $redirects <= self::MAX_REDIRECTS; $redirects++) {
            $address = $this->safeAddress($url);
            $curlAddress = str_contains($address, ':') ? "[{$address}]" : $address;
            $host = (string) parse_url($url, PHP_URL_HOST);
            $port = (int) (parse_url($url, PHP_URL_PORT) ?: (parse_url($url, PHP_URL_SCHEME) === 'https' ? 443 : 80));
            if (! defined('CURLOPT_RESOLVE')) {
                throw new RuntimeException('El servidor no puede fijar la resolución DNS de forma segura.');
            }
            $response = Http::withHeaders([
                'Accept' => 'text/html,application/xhtml+xml',
                'User-Agent' => 'SIMO Site Validator/1.0',
            ])->connectTimeout(3)->timeout(8)->withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$curlAddress}"]],
                'progress' => static function (int $downloadTotal, int $downloadedBytes): void {
                    if ($downloadTotal > self::MAX_BODY_BYTES || $downloadedBytes > self::MAX_BODY_BYTES) {
                        throw new RuntimeException('La respuesta excede el tamaño máximo permitido.');
                    }
                },
            ])->get($url);

            if (! $response->redirect()) {
                return [$response, $url];
            }

            $location = $response->header('Location');
            if (! is_string($location) || $location === '') {
                throw new RuntimeException('El sitio respondió con una redirección sin destino válido.');
            }
            if ($redirects === self::MAX_REDIRECTS) {
                throw new RuntimeException('El sitio excedió el límite de redirecciones.');
            }

            $url = $this->absoluteUrl($location, $url);
        }

        throw new RuntimeException('No se pudo resolver la redirección del sitio.');
    }

    private function safeAddress(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (! in_array($scheme, ['http', 'https'], true) || $host === '' || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('La URL debe ser HTTP/HTTPS pública y no puede incluir credenciales.');
        }
        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            throw new RuntimeException('La URL apunta a un destino local o reservado.');
        }

        $addresses = $this->hostResolver->resolve($host);
        if ($addresses === []) {
            throw new RuntimeException('No se pudo resolver el dominio de forma segura.');
        }
        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw new RuntimeException('La URL resuelve a una red privada, local o reservada.');
            }
        }

        return $addresses[0];
    }

    private function boundedBody(Response $response): string
    {
        $contentLength = $response->header('Content-Length');
        if (is_string($contentLength) && ctype_digit($contentLength) && (int) $contentLength > self::MAX_BODY_BYTES) {
            throw new RuntimeException('La respuesta excede el tamaño máximo permitido.');
        }
        $body = $response->body();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new RuntimeException('La respuesta excede el tamaño máximo permitido.');
        }

        return $body;
    }

    private function looksLikeChallenge(string $body, Response $response): bool
    {
        $haystack = strtolower($response->header('Server').' '.$body);
        foreach (['you are being redirected', 'sucuri', 'cf-chl-', 'cloudflare ray id', 'captcha', 'access denied', 'enable javascript and cookies'] as $marker) {
            if (str_contains($haystack, $marker)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function articleCandidates(string $html, string $baseUrl, ?string $selector): array
    {
        $document = $this->parseHtml($html);
        $baseHost = strtolower((string) parse_url($baseUrl, PHP_URL_HOST));
        $candidates = [];
        $anchors = $selector === null || trim($selector) === ''
            ? $document->getElementsByTagName('a')
            : $this->selectorNodes($document, $selector, 'enlaces');
        foreach ($anchors as $anchor) {
            $href = trim($anchor->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:')) {
                continue;
            }
            $candidate = $this->absoluteUrl($href, $baseUrl);
            $host = strtolower((string) parse_url($candidate, PHP_URL_HOST));
            $segments = array_values(array_filter(explode('/', trim((string) parse_url($candidate, PHP_URL_PATH), '/'))));
            if (($host === $baseHost || str_ends_with($host, '.'.$baseHost)) && count($segments) >= 1) {
                $candidates[] = $candidate;
            }
        }

        return array_values(array_unique($candidates));
    }

    private function hasMeaningfulArticle(string $html, ?string $selector): bool
    {
        $document = $this->parseHtml($html);
        $title = trim($document->getElementsByTagName('title')->item(0)?->textContent ?? '');
        $nodes = $selector === null || trim($selector) === ''
            ? $document->getElementsByTagName('article')
            : $this->selectorNodes($document, $selector, 'artículo');
        if ($nodes->count() === 0) {
            return false;
        }
        $content = '';
        foreach ($nodes as $node) {
            $content .= ' '.$node->textContent;
        }
        $text = preg_replace('/\s+/u', ' ', strip_tags($content)) ?? '';

        return mb_strlen($title) >= 10 && mb_strlen(trim($text)) >= 250;
    }

    private function selectorNodes(DOMDocument $document, string $selector, string $kind): \DOMNodeList
    {
        try {
            $xpath = (new CssSelectorConverter)->toXPath($selector);

            return (new \DOMXPath($document))->query($xpath) ?: throw new RuntimeException('El selector de '.$kind.' no pudo evaluarse.');
        } catch (Throwable $exception) {
            throw new RuntimeException('El selector de '.$kind.' configurado es inválido: '.$exception->getMessage(), previous: $exception);
        }
    }

    private function parseHtml(string $html): DOMDocument
    {
        $previous = libxml_use_internal_errors(true);
        $document = new DOMDocument;
        $document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $document;
    }

    private function absoluteUrl(string $location, string $baseUrl): string
    {
        if (filter_var($location, FILTER_VALIDATE_URL) !== false) {
            return $location;
        }
        $parts = parse_url($baseUrl);
        $origin = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }
        if (str_starts_with($location, '//')) {
            return ($parts['scheme'] ?? 'https').':'.$location;
        }
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }
        $basePath = str_replace('\\', '/', $parts['path'] ?? '/');
        $directory = str_ends_with($basePath, '/')
            ? rtrim($basePath, '/')
            : rtrim(dirname($basePath), '/');

        return $origin.($directory === '' ? '' : $directory).'/'.$location;
    }
}
