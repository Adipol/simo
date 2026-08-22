<?php

declare(strict_types=1);

namespace Tests\Feature\Scraper;

use App\Contracts\HostResolver;
use App\Services\Scraper\SiteUrlValidator;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SiteValidationLifecycleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(HostResolver::class, fn (): HostResolver => new class implements HostResolver
        {
            public function resolve(string $host): array
            {
                return match ($host) {
                    'private.test' => ['127.0.0.1'],
                    default => ['93.184.216.34'],
                };
            }
        });
    }

    public function test_timeout_is_rethrown_and_antibot_content_fails_validation(): void
    {
        Http::fake(fn () => throw new ConnectionException('Tiempo de espera agotado.'));
        $this->expectException(ConnectionException::class);

        try {
            app(SiteUrlValidator::class)->validate('https://timeout.test/');
        } finally {
            Http::swap(new Factory);
            Http::fake(['https://blocked.test/' => Http::response('<title>You are being redirected...</title><p>Sucuri</p>', 200)]);
            $blocked = app(SiteUrlValidator::class)->validate('https://blocked.test/');
            $this->assertFalse($blocked->valid);
            $this->assertStringContainsString('anti-bot', $blocked->diagnostic);
        }
    }

    public function test_redirect_to_private_address_is_rejected_before_fetch(): void
    {
        Http::fake([
            'https://news.test/' => Http::response('', 302, ['Location' => 'http://private.test/admin']),
            '*' => Http::response('must not be fetched'),
        ]);

        $result = app(SiteUrlValidator::class)->validate('https://news.test/');

        $this->assertFalse($result->valid);
        $this->assertStringContainsString('privada', $result->diagnostic);
        Http::assertSentCount(1);
    }

    public function test_configured_selectors_must_match_the_homepage_and_article_sample(): void
    {
        Http::fake([
            'https://news.test/' => Http::response('<html><div class="articles"><a href="/politica/article-one">Nota</a></div></html>'),
            'https://news.test/politica/article-one' => Http::response($this->articleHtml()),
        ]);

        $result = app(SiteUrlValidator::class)->validate('https://news.test/', '.missing a', 'article');
        $this->assertFalse($result->valid);
        $this->assertStringContainsString('selector de enlaces', $result->diagnostic);

        $result = app(SiteUrlValidator::class)->validate('https://news.test/', '.articles a', '.missing');
        $this->assertFalse($result->valid);
        $this->assertStringContainsString('selector de artículo', $result->diagnostic);

        $result = app(SiteUrlValidator::class)->validate('https://news.test/', '[', 'article');
        $this->assertFalse($result->valid);
        $this->assertStringContainsString('selector de enlaces configurado es inválido', $result->diagnostic);
    }

    public function test_unreachable_article_candidate_does_not_prevent_later_candidate_validation(): void
    {
        Http::fake(function (Request $request): PromiseInterface {
            return match ($request->url()) {
                'https://news.test/' => Http::response(
                    '<a href="/politica/unreachable">Unavailable</a><a href="/politica/article-two">Available</a>'
                ),
                'https://news.test/politica/unreachable' => throw new ConnectionException('Connection failed.'),
                'https://news.test/politica/article-two' => Http::response($this->articleHtml()),
                default => Http::response('', 404),
            };
        });

        $result = app(SiteUrlValidator::class)->validate('https://news.test/');

        $this->assertTrue($result->valid, $result->diagnostic);
        $this->assertSame('https://news.test/politica/article-two', $result->resolvedUrl);
    }

    public function test_relative_article_link_resolves_beneath_directory_url(): void
    {
        Http::fake([
            'https://news.test/politica/' => Http::response('<a href="articles/article-one">Article</a>'),
            'https://news.test/politica/articles/article-one' => Http::response($this->articleHtml()),
            '*' => Http::response('', 404),
        ]);

        $result = app(SiteUrlValidator::class)->validate('https://news.test/politica/');

        $this->assertTrue($result->valid, $result->diagnostic);
        $this->assertSame('https://news.test/politica/articles/article-one', $result->resolvedUrl);
    }

    public function test_root_level_article_link_validates_successfully(): void
    {
        Http::fake([
            'https://news.test/' => Http::response('<a href="/article-slug">Article</a>'),
            'https://news.test/article-slug' => Http::response($this->articleHtml()),
        ]);

        $result = app(SiteUrlValidator::class)->validate('https://news.test/');

        $this->assertTrue($result->valid, $result->diagnostic);
        $this->assertSame('https://news.test/article-slug', $result->resolvedUrl);
    }

    private function articleHtml(): string
    {
        return '<html><head><title>Una noticia política verificable</title></head><body><article>'.str_repeat('Contenido periodístico relevante y verificable. ', 10).'</article></body></html>';
    }
}
