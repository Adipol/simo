<?php

declare(strict_types=1);

namespace Tests\Feature\Scraper;

use App\Contracts\HostResolver;
use App\Enums\SiteValidationStatus;
use App\Jobs\ValidateScraperSite;
use App\Models\Pais;
use App\Models\SitioWeb;
use App\Services\Scraper\SiteUrlValidator;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class SiteValidationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Pais::query()->updateOrCreate(['codigo' => 'BO'], ['nombre' => 'Bolivia', 'activo' => true]);
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

    public function test_successful_validation_activates_requested_site(): void
    {
        $site = SitioWeb::factory()->create([
            'url' => 'https://news.test/',
            'activo' => false,
            'activation_requested' => true,
            'validation_status' => SiteValidationStatus::Pending,
            'validation_token' => '11111111-1111-4111-8111-111111111111',
        ]);
        Http::fake([
            'https://news.test/' => Http::response('<html><a href="/politica/article-one">Nota</a></html>'),
            'https://news.test/politica/article-one' => Http::response($this->articleHtml()),
        ]);

        (new ValidateScraperSite($site->id, '11111111-1111-4111-8111-111111111111'))->handle(app(SiteUrlValidator::class));

        $site->refresh();
        $this->assertSame(SiteValidationStatus::Valid, $site->validation_status);
        $this->assertTrue($site->activo);
        $this->assertNotNull($site->validated_at);
    }

    public function test_withdrawal_during_validation_keeps_successful_site_inactive(): void
    {
        $site = SitioWeb::factory()->create([
            'url' => 'https://news.test/',
            'activo' => false,
            'activation_requested' => true,
            'validation_status' => SiteValidationStatus::Pending,
            'validation_token' => '16161616-1616-4616-8616-161616161616',
        ]);
        $homepageRequested = false;
        Http::fake(function () use ($site, &$homepageRequested): PromiseInterface {
            if (! $homepageRequested) {
                $homepageRequested = true;
                SitioWeb::query()->whereKey($site->id)->update(['activation_requested' => false]);

                return Http::response('<html><a href="/politica/article-one">Nota</a></html>');
            }

            return Http::response($this->articleHtml());
        });

        (new ValidateScraperSite($site->id, (string) $site->validation_token))->handle(app(SiteUrlValidator::class));

        $site->refresh();
        $this->assertSame(SiteValidationStatus::Valid, $site->validation_status, (string) $site->validation_diagnostic);
        $this->assertFalse($site->activation_requested);
        $this->assertFalse($site->activo);
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

    public function test_stale_job_cannot_overwrite_a_newer_validation_request(): void
    {
        Http::fake();
        $site = SitioWeb::factory()->create([
            'activo' => false,
            'validation_status' => SiteValidationStatus::Pending,
            'validation_token' => '22222222-2222-4222-8222-222222222222',
        ]);

        (new ValidateScraperSite($site->id, '33333333-3333-4333-8333-333333333333'))->handle(app(SiteUrlValidator::class));

        $this->assertSame(SiteValidationStatus::Pending, $site->refresh()->validation_status);
        Http::assertNothingSent();
    }

    public function test_stale_job_cannot_claim_a_newer_pending_validation(): void
    {
        Http::fake();
        $site = SitioWeb::factory()->create([
            'activo' => false,
            'validation_status' => SiteValidationStatus::Pending,
            'validation_token' => '44444444-4444-4444-8444-444444444444',
        ]);

        (new ValidateScraperSite($site->id, '33333333-3333-4333-8333-333333333333'))->handle(app(SiteUrlValidator::class));

        $site->refresh();
        $this->assertSame(SiteValidationStatus::Pending, $site->validation_status);
        $this->assertSame('44444444-4444-4444-8444-444444444444', $site->validation_token);
        Http::assertNothingSent();
    }

    public function test_duplicate_same_token_delivery_cannot_claim_validating_work(): void
    {
        $site = SitioWeb::factory()->create([
            'url' => 'https://news.test/',
            'activo' => false,
            'activation_requested' => true,
            'validation_status' => SiteValidationStatus::Validating,
            'validation_token' => '55555555-5555-4555-8555-555555555555',
        ]);
        Http::fake();

        (new ValidateScraperSite($site->id, '55555555-5555-4555-8555-555555555555'))->handle(app(SiteUrlValidator::class));

        $site->refresh();
        $this->assertSame(SiteValidationStatus::Validating, $site->validation_status);
        $this->assertFalse($site->activo);
        Http::assertNothingSent();
    }

    public function test_transient_exception_resets_claim_to_pending_and_rethrows(): void
    {
        $site = SitioWeb::factory()->create([
            'url' => 'https://news.test/',
            'validation_status' => SiteValidationStatus::Pending,
            'validation_token' => '88888888-8888-4888-8888-888888888888',
        ]);
        Http::fake(fn () => throw new ConnectionException('Temporary connection failure.'));

        try {
            (new ValidateScraperSite($site->id, (string) $site->validation_token))->handle(app(SiteUrlValidator::class));
            $this->fail('The transient exception was not rethrown.');
        } catch (ConnectionException $exception) {
            $this->assertSame('Temporary connection failure.', $exception->getMessage());
        }

        $site->refresh();
        $this->assertSame(SiteValidationStatus::Pending, $site->validation_status);
        $this->assertNull($site->validation_started_at);
    }

    public function test_retry_after_transient_exception_can_claim_and_succeed(): void
    {
        $site = SitioWeb::factory()->create([
            'url' => 'https://news.test/',
            'activo' => false,
            'activation_requested' => true,
            'validation_status' => SiteValidationStatus::Pending,
            'validation_token' => '99999999-9999-4999-8999-999999999999',
        ]);
        Http::fake(['*' => Http::sequence()
            ->pushFailedConnection('Temporary connection failure.')
            ->push('<html><a href="/politica/article-one">Nota</a></html>')
            ->push($this->articleHtml())]);
        $job = new ValidateScraperSite($site->id, (string) $site->validation_token);

        try {
            $job->handle(app(SiteUrlValidator::class));
        } catch (ConnectionException) {
        }
        $job->handle(app(SiteUrlValidator::class));

        $site->refresh();
        $this->assertSame(SiteValidationStatus::Valid, $site->validation_status);
        $this->assertTrue($site->activo);
    }

    public function test_success_cannot_be_overwritten_by_failed_callback(): void
    {
        $site = SitioWeb::factory()->create([
            'activo' => true,
            'validation_status' => SiteValidationStatus::Valid,
            'validation_token' => 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
            'validation_diagnostic' => 'Validation succeeded.',
        ]);

        (new ValidateScraperSite($site->id, (string) $site->validation_token))->failed(new RuntimeException('Late failure.'));

        $site->refresh();
        $this->assertSame(SiteValidationStatus::Valid, $site->validation_status);
        $this->assertTrue($site->activo);
        $this->assertSame('Validation succeeded.', $site->validation_diagnostic);
    }

    public function test_timeout_failure_finalizes_same_token_validating_site(): void
    {
        $site = SitioWeb::factory()->create([
            'activo' => true,
            'validation_status' => SiteValidationStatus::Validating,
            'validation_token' => 'dddddddd-dddd-4ddd-8ddd-dddddddddddd',
        ]);
        $job = new ValidateScraperSite($site->id, (string) $site->validation_token);

        $this->assertTrue($job->failOnTimeout);
        $job->failed(new RuntimeException('Job timed out.'));

        $site->refresh();
        $this->assertSame(SiteValidationStatus::Failed, $site->validation_status);
        $this->assertFalse($site->activo);
        $this->assertStringContainsString('Job timed out.', (string) $site->validation_diagnostic);
    }

    public function test_stale_token_failed_callback_cannot_mutate_new_generation(): void
    {
        $site = SitioWeb::factory()->create([
            'validation_status' => SiteValidationStatus::Pending,
            'validation_token' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
            'validation_diagnostic' => 'New generation pending.',
        ]);

        (new ValidateScraperSite($site->id, 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'))->failed(new RuntimeException('Stale failure.'));

        $site->refresh();
        $this->assertSame(SiteValidationStatus::Pending, $site->validation_status);
        $this->assertSame('New generation pending.', $site->validation_diagnostic);
    }

    public function test_stale_token_retry_cannot_resume_newer_validating_work(): void
    {
        Http::fake();
        $site = SitioWeb::factory()->create([
            'activo' => false,
            'validation_status' => SiteValidationStatus::Validating,
            'validation_token' => '66666666-6666-4666-8666-666666666666',
        ]);

        (new ValidateScraperSite($site->id, '77777777-7777-4777-8777-777777777777'))->handle(app(SiteUrlValidator::class));

        $site->refresh();
        $this->assertSame(SiteValidationStatus::Validating, $site->validation_status);
        $this->assertSame('66666666-6666-4666-8666-666666666666', $site->validation_token);
        Http::assertNothingSent();
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
