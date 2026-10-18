<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResultadoLabelsExclusivityMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const CONSTRAINT_NAME = 'resultados_scraping_descartado_relevante_exclusive';

    private function migration(): Migration
    {
        return require database_path('migrations/2026_08_22_000001_enforce_exclusive_resultado_labels.php');
    }

    private function dropConstraintIfPostgreSql(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE resultados_scraping
                 DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT_NAME
            );
        }
    }

    private function findPostgreSqlConstraint(): ?object
    {
        return DB::selectOne(
            "SELECT 1 AS found
             FROM pg_constraint
             WHERE conrelid = 'resultados_scraping'::regclass
               AND conname = ?",
            [self::CONSTRAINT_NAME]
        );
    }

    private function insertResultado(bool $descartado, bool $relevante): int
    {
        return (int) DB::table('resultados_scraping')->insertGetId([
            'url' => 'https://migration-test.example.com/article-'.uniqid(),
            'keyword' => 'migration test',
            'pais' => 'BO',
            'fecha_encontrado' => now(),
            'relevance_score' => 50,
            'leido' => false,
            'descartado' => $descartado,
            'relevante' => $relevante,
        ]);
    }

    public function test_migration_normalizes_existing_overlaps_before_enforcing_constraint(): void
    {
        $this->dropConstraintIfPostgreSql();
        $resultadoId = $this->insertResultado(descartado: true, relevante: true);

        $this->migration()->up();

        $resultado = DB::table('resultados_scraping')->find($resultadoId);

        $this->assertNotNull($resultado);
        $this->assertTrue((bool) $resultado->descartado);
        $this->assertFalse((bool) $resultado->relevante);

        if (DB::getDriverName() === 'pgsql') {
            $this->assertNotNull($this->findPostgreSqlConstraint());
        }
    }

    public function test_postgresql_constraint_rejects_overlapping_labels(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Label exclusivity constraint is enforced by PostgreSQL only.');
        }

        $resultadoId = $this->insertResultado(descartado: false, relevante: false);
        $constraintViolation = null;

        try {
            DB::transaction(static function () use ($resultadoId): void {
                DB::table('resultados_scraping')
                    ->where('id', $resultadoId)
                    ->update(['descartado' => true, 'relevante' => true]);
            });
        } catch (QueryException $exception) {
            $constraintViolation = $exception;
        }

        $this->assertNotNull($constraintViolation);
        $this->assertStringContainsString(self::CONSTRAINT_NAME, $constraintViolation->getMessage());
    }

    public function test_down_safely_removes_postgresql_constraint(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Constraint removal is applicable to PostgreSQL only.');
        }

        $migration = $this->migration();
        $migration->down();

        try {
            $this->assertNull($this->findPostgreSqlConstraint());
        } finally {
            $migration->up();
        }
    }
}
