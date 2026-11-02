<?php

declare(strict_types=1);

namespace Tests\Feature\Scraper;

use App\Services\Scraper\DeepSeekTitleClient;
use App\Services\Scraper\DeepSeekTitleSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DeepSeekTitleClientTest extends TestCase
{
    private const TITLE = 'La jueza  presentó su renuncia';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.deepseek_title_gate' => [
            'enabled' => true, 'api_key' => 'fake-server-key', 'model' => 'deepseek-flash',
            'activation_after_id' => '0', 'timeout' => 20, 'batch_size' => 1,
        ]]);
        Http::preventStrayRequests();
    }

    private function envelope(array $changes = []): array
    {
        return ['model' => 'deepseek-flash-actual', 'choices' => [[
            'finish_reason' => 'stop',
            'message' => ['content' => json_encode(['resultados' => [array_replace([
                'id' => 17, 'decision' => 'relevant', 'evidencia' => 'jueza  presentó',
                'motivo' => 'La jueza presentó su renuncia.',
            ], $changes)]], JSON_THROW_ON_ERROR)],
        ]]];
    }

    public static function decisions(): array
    {
        return [['relevant'], ['doubtful'], ['unrelated']];
    }

    #[DataProvider('decisions')]
    public function test_valid_decision_and_title_only_request(string $decision): void
    {
        Http::fake(['*' => Http::response($this->envelope(['decision' => $decision]))]);
        $result = app(DeepSeekTitleClient::class)->classify(17, self::TITLE);

        $this->assertSame($decision, $result->status);
        $this->assertSame($decision, $result->decision);
        $this->assertSame('jueza  presentó', $result->evidence);
        $this->assertSame('deepseek-flash-actual', $result->model);
        $this->assertSame('9d6de7068451427546b895d9c6727d603e771c001e5e6815b2d8dc6186da1ae4', $result->promptVersion);
        $this->assertNull($result->errorCode);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $data = $request->data();
            $this->assertSame([
                'model' => 'deepseek-flash',
                'messages' => [
                    ['role' => 'system', 'content' => file_get_contents(resource_path('prompts/scraper-title-gate.txt'))],
                    ['role' => 'user', 'content' => json_encode(['id' => 17, 'titulo' => self::TITLE], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
                ],
                'thinking' => ['type' => 'disabled'], 'response_format' => ['type' => 'json_object'],
                'stream' => false, 'max_tokens' => 6500,
            ], $data);

            return $request->url() === 'https://api.deepseek.com/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer fake-server-key');
        });
    }

    public static function invalidResults(): array
    {
        return [
            'string id' => [['id' => '17']], 'bool id' => [['id' => true]],
            'float id' => [['id' => 17.0]], 'wrong id' => [['id' => 18]],
            'unknown decision' => [['decision' => 'discard']], 'missing evidence' => [['evidencia' => null]],
            'normalized spaces' => [['evidencia' => 'jueza presentó']], 'empty evidence' => [['evidencia' => '']],
            'invented evidence' => [['evidencia' => 'ministro']], 'empty reason' => [['motivo' => '  ']],
            'long reason' => [['motivo' => str_repeat('á', 161)]], 'extra key' => [['extra' => 'secret']],
        ];
    }

    #[DataProvider('invalidResults')]
    public function test_invalid_result_fails_open_without_raw_content(array $changes): void
    {
        // Preserve float encoding so 17.0 cannot accidentally become an integer.
        $body = $this->envelope($changes);
        if (isset($changes['id']) && is_float($changes['id'])) {
            $body['choices'][0]['message']['content'] = str_replace('"id":17', '"id":17.0', $body['choices'][0]['message']['content']);
        }
        Http::fake(['*' => Http::response($body)]);
        $result = app(DeepSeekTitleClient::class)->classify(17, self::TITLE);
        $this->assertSame('invalid_response', $result->status);
        $this->assertNull($result->decision);
        $this->assertNull($result->evidence);
        $this->assertNull($result->reason);
        $this->assertSame('invalid_response', $result->errorCode);
        Http::assertSentCount(1);
    }

    public static function malformedEnvelopes(): array
    {
        return [
            ['json'], ['root_list'], ['extra_root'], ['two_results'], ['missing_key'],
            ['two_choices'], ['length'], ['missing_finish'], ['content_array'], ['missing_model'],
            ['oversize'], ['long_evidence'],
        ];
    }

    #[DataProvider('malformedEnvelopes')]
    public function test_rejects_malformed_or_truncated_responses(string $case): void
    {
        $body = $this->envelope();
        $content = $body['choices'][0]['message']['content'];
        switch ($case) {
            case 'json': $body = '{not-json'; break;
            case 'root_list': $body['choices'][0]['message']['content'] = '['.$content.']'; break;
            case 'extra_root': $body['choices'][0]['message']['content'] = substr($content, 0, -1).',"extra":1}'; break;
            case 'two_results':
                $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
                $decoded['resultados'][] = $decoded['resultados'][0];
                $body['choices'][0]['message']['content'] = json_encode($decoded, JSON_THROW_ON_ERROR); break;
            case 'missing_key': $body = $this->envelope(['motivo' => null]); break;
            case 'two_choices': $body['choices'][] = $body['choices'][0]; break;
            case 'length': $body['choices'][0]['finish_reason'] = 'length'; break;
            case 'missing_finish': unset($body['choices'][0]['finish_reason']); break;
            case 'content_array': $body['choices'][0]['message']['content'] = []; break;
            case 'missing_model': unset($body['model']); break;
            case 'oversize': $body = str_repeat('x', 262145); break;
            case 'long_evidence': $body = $this->envelope(['evidencia' => str_repeat('á', 181)]); break;
        }
        Http::fake(['*' => Http::response($body)]);
        $this->assertSame('invalid_response', app(DeepSeekTitleClient::class)->classify(17, self::TITLE)->status);
        Http::assertSentCount(1);
    }

    public static function failures(): array
    {
        return [[401], [429], [500], [302]];
    }

    #[DataProvider('failures')]
    public function test_http_failure_is_sanitized_and_not_retried(int $status): void
    {
        Http::fake(['*' => Http::response('fake-server-key provider private body', $status)]);
        $result = app(DeepSeekTitleClient::class)->classify(17, self::TITLE);
        $this->assertSame('technical_error', $result->status);
        $this->assertSame('http_'.$status, $result->errorCode);
        $this->assertNull($result->decision);
        $this->assertNull($result->model);
        $this->assertStringNotContainsString('fake-server-key', json_encode($result, JSON_THROW_ON_ERROR));
        Http::assertSentCount(1);
    }

    public function test_connection_failure_is_sanitized(): void
    {
        Http::fake(['*' => Http::failedConnection('fake-server-key sensitive URL')]);
        $result = app(DeepSeekTitleClient::class)->classify(17, self::TITLE);
        $this->assertSame('technical_error', $result->status);
        $this->assertSame('transport_error', $result->errorCode);
        $this->assertNull($result->reason);
    }

    public static function badSettings(): array
    {
        return [
            ['enabled', false], ['enabled', 'true'], ['api_key', null], ['api_key', "key\nheader"],
            ['activation_after_id', null], ['activation_after_id', -1], ['activation_after_id', true],
            ['activation_after_id', '1.0'], ['activation_after_id', '99999999999999999999999'],
            ['timeout', 0], ['timeout', 21], ['model', []], ['batch_size', 2],
        ];
    }

    #[DataProvider('badSettings')]
    public function test_unready_configuration_never_sends(string $key, string|int|bool|array|null $value): void
    {
        config(['services.deepseek_title_gate.'.$key => $value]);
        $this->assertFalse(app(DeepSeekTitleSettings::class)->configured());
        $result = app(DeepSeekTitleClient::class)->classify(17, self::TITLE);
        $this->assertSame('technical_error', $result->status);
        $this->assertSame('not_configured', $result->errorCode);
        Http::assertNothingSent();
    }

    public function test_transport_options_are_bounded_and_headers_reject_oversize(): void
    {
        Http::fake(function (Request $request, array $options): \GuzzleHttp\Promise\PromiseInterface {
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($options['stream']);
            $this->assertSame(20, $options['timeout']);
            $this->assertSame(20, $options['connect_timeout']);
            $this->assertSame(20, $options['read_timeout']);
            $this->assertTrue($options['verify'] ?? true);
            try {
                $options['on_headers'](new \GuzzleHttp\Psr7\Response(200, ['Content-Length' => '262145']));
                $this->fail('Oversized declared response was accepted.');
            } catch (\LengthException) {
                $this->addToAssertionCount(1);
            }

            return Http::response($this->envelope());
        });
        $this->assertSame('relevant', app(DeepSeekTitleClient::class)->classify(17, self::TITLE)->status);
        Http::assertSentCount(1);
    }

    public function test_wrapped_header_limit_is_invalid_not_an_exception(): void
    {
        Http::fake(static function (): never {
            throw new \GuzzleHttp\Exception\RequestException(
                'private transport details',
                new \GuzzleHttp\Psr7\Request('POST', 'https://api.deepseek.com/chat/completions'),
                previous: new \LengthException('private header details'),
            );
        });
        $result = app(DeepSeekTitleClient::class)->classify(17, self::TITLE);
        $this->assertSame('invalid_response', $result->status);
        $this->assertSame('invalid_response', $result->errorCode);
    }

    public function test_unicode_limits_and_failure_persistence_mapping(): void
    {
        $title = str_repeat('á', 180);
        Http::fake(['*' => Http::sequence()
            ->push($this->envelope(['evidencia' => $title, 'motivo' => str_repeat('ñ', 160)]))
            ->push('private', 401)]);
        $result = app(DeepSeekTitleClient::class)->classify(17, $title);
        $this->assertSame('relevant', $result->status);
        $this->assertSame($title, $result->evidence);
        $attributes = app(DeepSeekTitleClient::class)->classify(17, self::TITLE)->toPersistenceAttributes();
        $this->assertSame('technical_error', $attributes['deepseek_title_status']);
        $this->assertSame('http_401', $attributes['deepseek_title_error_code']);
        $this->assertNull($attributes['deepseek_title_reason']);
        $this->assertNull($attributes['deepseek_title_evidence']);
        $this->assertCount(7, $attributes);
    }

    public function test_explicit_zero_boundary_is_configured_not_verified_healthy(): void
    {
        $settings = app(DeepSeekTitleSettings::class);
        $this->assertTrue($settings->configured());
        $this->assertSame(0, $settings->activationAfterId);
        $this->assertSame(20, $settings->timeout);
        Http::assertNothingSent();
    }
}
