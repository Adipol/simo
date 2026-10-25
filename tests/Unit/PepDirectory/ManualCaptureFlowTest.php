<?php

declare(strict_types=1);

namespace Tests\Unit\PepDirectory;

use App\Exceptions\PepDirectory\CaptureException;
use App\Models\User;
use App\Services\PepDirectory\{DirectoryPreviewService, PreviewCompiler, ProcessCaptureTransport};
use App\Services\PepDirectory\DTOs\{ConfigurationDecisionData, ServerCaptureData};
use Illuminate\Database\{Connection, ConnectionResolver};
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

final class ManualCaptureFlowTest extends TestCase
{
    public function test_role_condition_control_marks_preview_dirty_without_clearing_fields(): void
    {
        $component = new \App\Livewire\Pep\ConfiguracionDirectorio();
        self::assertFalse($component->separateAiSuffix);
        $component->fields = ['condition' => ['mode' => 'column', 'value' => '2', 'scope' => 'record']];
        $before = $component->fields;
        $component->separateAiSuffix = true;
        $component->notice = 'Stored preview';
        $component->updated('separateAiSuffix');
        self::assertTrue($component->dirty);
        self::assertSame('', $component->notice);
        self::assertSame($before, $component->fields);
    }

    public function test_transport_metadata_survives_without_mutating_existing_capture(): void
    {
        foreach (['http', 'https'] as $scheme) {
            $url = $scheme.'://directory.example/people';
            $plain = ServerCaptureData::fromArray(['raw_body' => 'synthetic', 'status' => 200,
                'requested_url' => $url, 'final_url' => $url, 'encoding' => 'utf-8',
                'captured_at' => '2026-10-18T12:00:00+00:00',
                'provenance' => ['adapter' => 'public-'.$scheme.'-pinned-v1']]);
            $before = $plain->provenance;
            $stored = $plain->withTransport('93.184.216.34', $scheme === 'https');
            self::assertSame($before, $plain->provenance);
            self::assertSame($plain->body, $stored->body);
            self::assertSame($plain->metadata, $stored->metadata);
            self::assertSame($plain->requestedUrl, $stored->requestedUrl);
            self::assertSame($plain->finalUrl, $stored->finalUrl);
            self::assertSame($before + ['transport' => ['peer_ip' => '93.184.216.34', 'tls_verified' => $scheme === 'https']], $stored->provenance);
        }
    }

    public function test_manual_controls_template_compiles_without_application_bootstrap(): void
    {
        $root = dirname(__DIR__, 3);
        $compiler = new \Illuminate\View\Compilers\BladeCompiler(new \Illuminate\Filesystem\Filesystem(), $root.'/storage/framework/views');
        $compiled = $compiler->compileString(file_get_contents($root.'/resources/views/livewire/pep/configuracion-directorio.blade.php'));
        token_get_all($compiled, TOKEN_PARSE);
        self::assertStringContainsString('wire:click="capturePage"', $compiled);
        self::assertStringContainsString('wire:click="useFuente(', $compiled);
        self::assertStringContainsString('HTTP sin cifrado', $compiled);
        self::assertStringContainsString('wire:model="separateAiSuffix"', $compiled);
        self::assertStringContainsString('Separar sufijo a.i. del cargo como condición', $compiled);
        self::assertStringContainsString('wire:target="directory,record,section,columns,separateAiSuffix,', $compiled);
    }

    public function test_caller_transactions_are_refused_before_queries_or_transport(): void
    {
        $old = Model::getConnectionResolver();
        try {
            foreach ([[1, false], [0, true]] as [$level, $raw]) {
                $pdo = $this->createMock(\PDO::class);
                $pdo->method('inTransaction')->willReturn($raw);
                $db = $this->createMock(Connection::class);
                $db->method('transactionLevel')->willReturn($level);
                $db->method('getPdo')->willReturn($pdo);
                $db->expects(self::never())->method('transaction');
                $db->expects(self::never())->method('select');
                $resolver = new ConnectionResolver(['capture-unit' => $db]);
                $resolver->setDefaultConnection('capture-unit');
                Model::setConnectionResolver($resolver);
                $calls = 0;
                $transport = new ProcessCaptureTransport('/usr/bin/python3', true, function () use (&$calls): array { $calls++; return []; });
                $service = new DirectoryPreviewService($this->createMock(PreviewCompiler::class));
                $decision = ConfigurationDecisionData::fromArray(['expected_version' => 0, 'reason' => 'Synthetic manual capture']);
                try {
                    $service->captureSource(1, $transport, new User(), $decision);
                    self::fail('Outer transaction must be refused.');
                } catch (CaptureException $error) {
                    self::assertSame('Directory capture failed.', $error->getMessage());
                }
                self::assertSame(0, $calls);
            }
        } finally {
            if ($old !== null) {
                Model::setConnectionResolver($old);
            } else {
                Model::unsetConnectionResolver();
            }
        }
    }
}
