<?php

declare(strict_types=1);

namespace Tests\Unit\Gemini;

use App\Models\Cambio;
use App\Services\Gemini\GeminiAnalisisService;
use App\Services\Gemini\GeminiPromptBuilder;
use App\Services\Gemini\GeminiService;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionMethod;
use RuntimeException;

final class GeminiAnalisisImagePathsTest extends TestCase
{
    private string $fixtureRoot;

    private Container $previousContainer;

    private ?Container $previousFacadeApplication;

    private GeminiAnalisisService $service;

    private GeminiService $gemini;

    private GeminiPromptBuilder $builder;

    private LoggerInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        // Run with an approved temporary directory; never use application storage.
        $parent = sys_get_temp_dir();
        $this->assertDirectoryExists($parent);
        $this->fixtureRoot = $parent.'/simo-image-paths-'.bin2hex(random_bytes(8));
        mkdir($this->fixtureRoot);
        mkdir($this->fixtureRoot.'/app');
        mkdir($this->fixtureRoot.'/app/img_cambios');
        mkdir($this->fixtureRoot.'/app/img_cambios_backup');
        file_put_contents($this->fixtureRoot.'/outside.png', 'Harmless outside fixture');
        file_put_contents($this->fixtureRoot.'/app/img_cambios_backup/42_0.png', 'Harmless sibling fixture');
        file_put_contents($this->fixtureRoot.'/app/img_cambios/42_0.png', 'Harmless image fixture');

        $this->previousContainer = Container::getInstance();
        $this->previousFacadeApplication = Facade::getFacadeApplication();
        $container = new class($this->fixtureRoot) extends Container
        {
            public function __construct(private readonly string $fixtureStorage) {}

            public function storagePath(string $path = ''): string
            {
                return $this->fixtureStorage.'/'.$path;
            }
        };
        $container->instance('config', new Repository(['services' => ['gemini' => [
            'pro_model' => 'fixture-text-model',
            'vision_model' => 'fixture-vision-model',
        ]]]));
        $this->logger = $this->createMock(LoggerInterface::class);
        $manager = $this->createStub(LogManager::class);
        $manager->method('channel')->willReturn($this->logger);
        $container->instance('log', $manager);
        Container::setInstance($container);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($container);

        // Constructors are disabled: no kernel, configuration files, API or database.
        $this->gemini = $this->createMock(GeminiService::class);
        $this->builder = $this->createMock(GeminiPromptBuilder::class);
        $this->service = new GeminiAnalisisService($this->gemini, $this->builder);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApplication);
        Container::setInstance($this->previousContainer);

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->fixtureRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file->isDir() && ! $file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir($this->fixtureRoot);

        parent::tearDown();
    }

    public function test_valid_generated_paths_preserve_supported_image_metadata(): void
    {
        foreach (['png' => 'png', 'jpeg' => 'jpg', 'webp' => 'webp', 'gif' => 'gif', 'bmp' => 'bmp', 'tiff' => 'tiff', 'avif' => 'avif', 'heic' => 'heic', 'heif' => 'heif'] as $mime => $extension) {
            $path = 'img_cambios/42_0.'.$extension;
            file_put_contents($this->fixtureRoot.'/app/'.$path, 'Harmless image fixture');
            $entry = ['path' => $path, 'mime_type' => 'image/'.$mime, 'src_original' => 'https://example.invalid/image'];

            $this->assertSame([
                ['path' => realpath($this->fixtureRoot.'/app/'.$path), 'mime_type' => 'image/'.$mime],
            ], $this->resolve([$entry]));
        }
    }

    public function test_outside_traversal_is_rejected_even_with_image_mime(): void
    {
        $this->assertSame([], $this->resolve([$this->entry('img_cambios/../../outside.png')]));
        $this->assertSame([], $this->resolve([$this->entry('../outside.png')]));
    }

    public function test_sibling_prefix_escape_is_rejected(): void
    {
        $this->assertSame([], $this->resolve([$this->entry('img_cambios/../img_cambios_backup/42_0.png')]));
        $this->assertSame([], $this->resolve([$this->entry('img_cambios_backup/42_0.png')]));
    }

    public function test_absolute_and_non_contract_paths_are_rejected(): void
    {
        foreach ([$this->fixtureRoot.'/app/img_cambios/42_0.png', '/img_cambios/42_0.png', 'C:\\images\\42_0.png', '//server/images/42_0.png', 'file://'.$this->fixtureRoot.'/outside.png', 'php://memory', './img_cambios/42_0.png'] as $path) {
            $this->assertSame([], $this->resolve([$this->entry($path)]));
        }
    }

    public function test_file_and_directory_symlink_escapes_are_rejected(): void
    {
        $this->requireSymlinks();
        symlink($this->fixtureRoot.'/outside.png', $this->fixtureRoot.'/app/img_cambios/escape.png');
        symlink($this->fixtureRoot.'/app/img_cambios_backup', $this->fixtureRoot.'/app/img_cambios/linked');

        $this->assertSame([], $this->resolve([$this->entry('img_cambios/escape.png')]));
        $this->assertSame([], $this->resolve([$this->entry('img_cambios/linked/42_0.png')]));
    }

    public function test_contained_symlink_returns_canonical_path(): void
    {
        $this->requireSymlinks();
        symlink($this->fixtureRoot.'/app/img_cambios/42_0.png', $this->fixtureRoot.'/app/img_cambios/alias.png');

        $this->assertSame($this->resolve([$this->entry()]), $this->resolve([$this->entry('img_cambios/alias.png')]));
    }

    public function test_storage_root_is_canonicalized(): void
    {
        $this->requireSymlinks();
        rename($this->fixtureRoot.'/app/img_cambios', $this->fixtureRoot.'/canonical-images');
        symlink($this->fixtureRoot.'/canonical-images', $this->fixtureRoot.'/app/img_cambios');

        $this->assertSame([
            ['path' => realpath($this->fixtureRoot.'/canonical-images/42_0.png'), 'mime_type' => 'image/png'],
        ], $this->resolve([$this->entry()]));
    }

    public function test_malformed_collection_shapes_are_rejected(): void
    {
        foreach ([null, [], false, true, 42, 1.5, 'invalid', new \stdClass, $this->entry(), ['named' => $this->entry()]] as $payload) {
            $this->assertSame([], $this->resolve($payload));
        }
    }

    public function test_malformed_entries_and_field_types_are_skipped(): void
    {
        foreach ([null, false, true, 42, 1.5, 'invalid', new \stdClass, [], ['path' => 'img_cambios/42_0.png'], ['mime_type' => 'image/png']] as $entry) {
            $this->assertSame([], $this->resolve([$entry]));
        }
        foreach ([null, false, true, 42, 1.5, [], new \stdClass] as $value) {
            foreach (['path', 'mime_type'] as $field) {
                $this->assertSame([], $this->resolve([array_replace($this->entry(), [$field => $value])]));
            }
        }
    }

    public function test_malformed_path_and_mime_strings_are_rejected(): void
    {
        foreach (['', ' ', "img_cambios/42_0.png\0", "img_cambios/\n42_0.png", 'img_cambios\\42_0.png'] as $path) {
            $this->assertSame([], $this->resolve([$this->entry($path)]));
        }
        foreach (['', ' ', "image/png\0", "image/png\n", 'text/plain', 'image/', 'image/png; charset=utf-8'] as $mime) {
            $this->assertSame([], $this->resolve([array_replace($this->entry(), ['mime_type' => $mime])]));
        }
    }

    public function test_missing_file_and_directories_are_rejected(): void
    {
        mkdir($this->fixtureRoot.'/app/img_cambios/directory.png');

        foreach (['img_cambios/missing.png', 'img_cambios/directory.png', 'img_cambios/', 'img_cambios/.'] as $path) {
            $this->assertSame([], $this->resolve([$this->entry($path)]));
        }
    }

    public function test_missing_or_non_directory_root_fails_closed(): void
    {
        rename($this->fixtureRoot.'/app/img_cambios', $this->fixtureRoot.'/saved-images');
        $this->assertSame([], $this->resolve([$this->entry()]));
        file_put_contents($this->fixtureRoot.'/app/img_cambios', 'Harmless non-directory root');
        $this->assertSame([], $this->resolve([$this->entry()]));
    }

    public function test_unreadable_regular_file_is_rejected(): void
    {
        $path = $this->fixtureRoot.'/app/img_cambios/42_0.png';
        chmod($path, 0000);
        clearstatcache();
        if (is_readable($path)) {
            $this->markTestSkipped('The current user bypasses filesystem read permissions.');
        }

        $this->assertSame([], $this->resolve([$this->entry()]));
    }

    public function test_mixed_entries_only_expose_safe_files_to_the_payload_builder(): void
    {
        $resolved = $this->resolve([$this->entry('img_cambios/../../outside.png'), null, $this->entry()]);
        $this->assertSame([
            ['path' => realpath($this->fixtureRoot.'/app/img_cambios/42_0.png'), 'mime_type' => 'image/png'],
        ], $resolved);
        $body = (new ReflectionMethod(GeminiService::class, 'buildRequestBodyMultimodal'))
            ->invoke($this->gemini, 'Fixture prompt', $resolved);
        $this->assertSame([
            ['text' => 'Fixture prompt'],
            ['inline_data' => ['mime_type' => 'image/png', 'data' => base64_encode('Harmless image fixture')]],
        ], $body['contents'][0]['parts']);
    }

    public function test_rejected_paths_are_not_leaked_in_log_context(): void
    {
        $this->logger->method('warning')->willReturnCallback(function (string $message, array $context): void {
            $this->assertSame(['cambio_id' => 42], $context);
            $this->assertStringNotContainsString($this->fixtureRoot, $message);
        });

        $this->assertSame([], $this->resolve([$this->entry('img_cambios/missing.png')]));
    }

    public function test_no_usable_images_degrades_to_text_only(): void
    {
        $this->builder->expects($this->once())->method('analisisCambio')->with('Changed text', '', '', null)->willReturn('Text prompt');
        $this->builder->expects($this->never())->method('analisisCambioMultimodal');
        $this->gemini->expects($this->never())->method('sendMultimodalWithMetadata');
        $this->gemini->expects($this->once())->method('sendWithMetadata')->with('Text prompt', 'fixture-text-model')
            ->willThrowException(new RuntimeException('Text transport boundary reached'));
        $this->expectExceptionMessage('Text transport boundary reached');

        (new ReflectionMethod($this->service, 'procesarCambioMultimodal'))->invoke(
            $this->service,
            $this->cambio([$this->entry('img_cambios/../../outside.png'), null]),
        );
    }

    public function test_valid_images_still_reach_multimodal_transport(): void
    {
        $images = $this->resolve([$this->entry()]);
        $this->builder->expects($this->once())->method('analisisCambioMultimodal')->with('Changed text', '', '', 1, null)->willReturn('Vision prompt');
        $this->gemini->expects($this->never())->method('sendWithMetadata');
        $this->gemini->expects($this->once())->method('sendMultimodalWithMetadata')->with('Vision prompt', $images, 'fixture-vision-model')
            ->willThrowException(new RuntimeException('Vision transport boundary reached'));
        $this->expectExceptionMessage('Vision transport boundary reached');

        (new ReflectionMethod($this->service, 'procesarCambioMultimodal'))->invoke($this->service, $this->cambio([$this->entry()]));
    }

    /** The payload is deliberately mixed to exercise untrusted JSON shapes. */
    private function cambio(mixed $entries): Cambio
    {
        $cambio = $this->getMockBuilder(Cambio::class)->disableOriginalConstructor()->onlyMethods(['getAttribute', 'update'])->getMock();
        $cambio->method('getAttribute')->willReturnMap([
            ['imagenes_cambio_json', $entries], ['id', 42], ['diff_texto', 'Changed text'],
            ['fuente', null], ['autoridades_eventos_json', null],
        ]);
        $cambio->expects($this->never())->method('update');

        return $cambio;
    }

    /**
     * @param  mixed  $entries  Deliberately malformed JSON values are part of this boundary test.
     * @return array<int,array{path:string,mime_type:string}>
     */
    private function resolve(mixed $entries): array
    {
        return (new ReflectionMethod($this->service, 'resolverImagenes'))->invoke($this->service, $this->cambio($entries));
    }

    /** @return array{path:string,mime_type:string} */
    private function entry(string $path = 'img_cambios/42_0.png'): array
    {
        return ['path' => $path, 'mime_type' => 'image/png'];
    }

    private function requireSymlinks(): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || ! function_exists('symlink')) {
            $this->markTestSkipped('Symlink coverage requires a POSIX filesystem with symlink support.');
        }
    }
}
