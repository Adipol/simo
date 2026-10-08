<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const ADAPTER = 'umsa_rectorado_profile';

    private const URL = 'https://www.umsa.bo/web/guest/rectorado';

    public function up(): void
    {
        DB::table('fuentes')
            ->where('url', self::URL)
            ->where(static function (Builder $query): void {
                $query->whereNull('autoridades_extractor')
                    ->orWhere('autoridades_extractor', '');
            })
            ->update(['autoridades_extractor' => self::ADAPTER]);
    }

    public function down(): void
    {
        DB::table('fuentes')
            ->where('url', self::URL)
            ->where('autoridades_extractor', self::ADAPTER)
            ->update(['autoridades_extractor' => null]);
    }
};
