<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Cambio;
use Tests\TestCase;

class CambioTieneImagenesTest extends TestCase
{
    // ─── tieneImagenes() ──────────────────────────────────────────────────────

    public function test_tiene_imagenes_retorna_false_cuando_campo_es_null(): void
    {
        $cambio = new Cambio;
        $cambio->imagenes_cambio_json = null;

        $this->assertFalse($cambio->tieneImagenes());
    }

    public function test_tiene_imagenes_retorna_false_cuando_array_vacio(): void
    {
        $cambio = new Cambio;
        $cambio->imagenes_cambio_json = [];

        $this->assertFalse($cambio->tieneImagenes());
    }

    public function test_tiene_imagenes_retorna_true_cuando_hay_una_imagen(): void
    {
        $cambio = new Cambio;
        $cambio->imagenes_cambio_json = [
            ['path' => 'img_cambios/42_0.png', 'sha256' => 'abc', 'mime_type' => 'image/png', 'src_original' => 'https://example.com/img.png'],
        ];

        $this->assertTrue($cambio->tieneImagenes());
    }

    public function test_tiene_imagenes_retorna_true_cuando_hay_multiples_imagenes(): void
    {
        $cambio = new Cambio;
        $cambio->imagenes_cambio_json = [
            ['path' => 'img_cambios/42_0.png', 'sha256' => 'abc', 'mime_type' => 'image/png', 'src_original' => 'https://example.com/img.png'],
            ['path' => 'img_cambios/42_1.jpg', 'sha256' => 'def', 'mime_type' => 'image/jpeg', 'src_original' => 'https://example.com/img2.jpg'],
        ];

        $this->assertTrue($cambio->tieneImagenes());
    }
}
