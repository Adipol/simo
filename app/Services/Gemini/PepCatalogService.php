<?php

declare(strict_types=1);

namespace App\Services\Gemini;

use App\Models\CargoPep;
use App\Models\EntidadPublica;
use Illuminate\Support\Collection;

class PepCatalogService
{
    /** @var array<string, Collection> */
    private static array $cargosCache = [];

    /** @var array<string, Collection> */
    private static array $entidadesCache = [];

    public function getCargos(string $paisCodigo): Collection
    {
        return self::$cargosCache[$paisCodigo] ??=
            CargoPep::active()->forCountry($paisCodigo)->orderBy('categoria')->get();
    }

    public function getEntidades(string $paisCodigo): Collection
    {
        return self::$entidadesCache[$paisCodigo] ??=
            EntidadPublica::active()->forCountry($paisCodigo)->get();
    }

    public static function flushCache(): void
    {
        self::$cargosCache = [];
        self::$entidadesCache = [];
    }
}
