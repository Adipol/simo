<?php

declare(strict_types=1);

namespace Tests\Support;

require_once __DIR__.'/GuardsDisposableDatabase.php';
require_once __DIR__.'/PepDirectoryReviewTestCase.php';

use Illuminate\Database\Schema\Blueprint;

abstract class PepDirectoryConfigurationTestCase extends PepDirectoryReviewTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->db->getSchemaBuilder()->table('fuentes', function (Blueprint $table): void {
            $table->text('url')->nullable();
            $table->string('tipo', 32)->nullable();
            $table->boolean('activo')->default(true);
        });
        (require __DIR__.'/../../database/migrations/2026_10_18_000002_create_pep_directory_configuration_tables.php')->up();
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            if (isset($this->db)) {
                self::assertNull($this->db->selectOne("SELECT to_regprocedure('public.pep_invalidate_source()') AS name")->name);
                self::assertSame(0, (int) $this->db->selectOne("SELECT count(*) AS n FROM pg_catalog.pg_trigger WHERE tgname = 'pep_fuente_invalidate'")->n);
                echo "FUNCTION/TRIGGER ABSENT AFTER ROLLBACK\n";
            }
        }
    }
}
