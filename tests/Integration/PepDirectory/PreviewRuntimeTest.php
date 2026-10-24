<?php

declare(strict_types=1);

namespace Tests\Integration\PepDirectory;

use App\Services\PepDirectory\ProcessPreviewCompiler;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Actual subprocess proof in the explicitly selected isolated test image only. */
final class PreviewRuntimeTest extends TestCase
{
    protected function setUp(): void
    {
        if (getenv('PEP_PREVIEW_RUNTIME') === false) {
            self::markTestSkipped('Explicit PEP_PREVIEW_RUNTIME=isolated required before interpreter execution.');
        }
        if (getenv('PEP_PREVIEW_RUNTIME') !== 'isolated') {
            throw new RuntimeException('Only the isolated preview runtime is authorized.');
        }
    }

    public function test_php_compiles_synthetic_capture_through_real_python(): void
    {
        $request = json_decode(file_get_contents(__DIR__.'/../../Fixtures/PepDirectory/preview-compiler/request.json'), true, 32, JSON_THROW_ON_ERROR);
        $before = $request;
        $body = '<html><body><table><tr><td>Directora Jurídica</td><td>María García</td></tr></table></body></html>';
        $result = (new ProcessPreviewCompiler('/usr/bin/python3'))->compile($request['declaration'], $body, $request['metadata']);

        self::assertSame($before, $request);
        self::assertSame('incomplete', $result->validity, 'Unreviewed inventory must not become qualified.');
        self::assertSame('synthetic-preview', $result->wire['source']);
        self::assertCount(1, $result->wire['positions']);
        self::assertSame('Synthetic unit', $result->wire['positions'][0]['unit']);
        self::assertSame('Directora Jurídica', $result->wire['positions'][0]['role']);
        self::assertSame('María García', $result->wire['positions'][0]['person']);
        self::assertSame('configured-html-v1:'.hash('sha256', $result->canonicalBytes), $result->revision);
        self::assertSame($result->revision, $result->wire['revision']);
        self::assertSame(hash('sha256', $result->resultBytes), $result->resultSha256);
        self::assertSame($result->wire, json_decode($result->resultBytes, true, 32, JSON_THROW_ON_ERROR));
    }
}
