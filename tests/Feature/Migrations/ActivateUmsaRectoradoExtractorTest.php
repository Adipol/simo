<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\Fuente;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivateUmsaRectoradoExtractorTest extends TestCase
{
    use RefreshDatabase;

    private const ADAPTER = 'umsa_rectorado_profile';

    private const URL = 'https://www.umsa.bo/web/guest/rectorado';

    private function migration(): Migration
    {
        return require database_path('migrations/2026_09_20_000001_activate_umsa_rectorado_authority_extractor.php');
    }

    public function test_up_configura_solo_la_url_exacta_sin_adaptador(): void
    {
        $objetivo = $this->fuente(self::URL, null);
        $urlParecida = $this->fuente(self::URL.'/', null);
        $otraFuente = $this->fuente('https://www.umsa.bo/web/guest/autoridades', null);

        $this->migration()->up();

        $this->assertSame(self::ADAPTER, $objetivo->fresh()->autoridades_extractor);
        $this->assertNull($urlParecida->fresh()->autoridades_extractor);
        $this->assertNull($otraFuente->fresh()->autoridades_extractor);

        $objetivo->update(['autoridades_extractor' => '']);
        $this->migration()->up();
        $this->assertSame(self::ADAPTER, $objetivo->fresh()->autoridades_extractor);

        $objetivo->update(['autoridades_extractor' => 'divi_blurb']);
        $this->migration()->up();
        $this->assertSame('divi_blurb', $objetivo->fresh()->autoridades_extractor);
    }

    public function test_down_limpia_solo_la_configuracion_original_aun_presente(): void
    {
        $objetivo = $this->fuente(self::URL, self::ADAPTER);
        $reconfigurada = $this->fuente(self::URL.'?source=manual', self::ADAPTER);
        $noObjetivo = $this->fuente('https://www.umsa.bo/web/guest/autoridades', self::ADAPTER);

        $this->migration()->down();

        $this->assertNull($objetivo->fresh()->autoridades_extractor);
        $this->assertSame(self::ADAPTER, $reconfigurada->fresh()->autoridades_extractor);
        $this->assertSame(self::ADAPTER, $noObjetivo->fresh()->autoridades_extractor);

        $objetivo->update(['autoridades_extractor' => 'divi_blurb']);
        $this->migration()->down();

        $this->assertSame('divi_blurb', $objetivo->fresh()->autoridades_extractor);
    }

    private function fuente(string $url, ?string $extractor): Fuente
    {
        return Fuente::create([
            'url' => $url,
            'nombre' => 'Fuente de prueba '.str_replace(['https://', '/', '?', '='], '-', $url),
            'pais' => 'BO',
            'organismo' => 'Organismo de prueba',
            'nivel' => 'nacional',
            'tipo' => 'html',
            'activo' => true,
            'autoridades_extractor' => $extractor,
            'analizar_imagenes' => false,
        ]);
    }
}
