<?php

declare(strict_types=1);

namespace Tests\Feature\PepDirectory;

require_once __DIR__.'/../../Support/GuardsDisposableDatabase.php';
require_once __DIR__.'/../../Support/PepDirectoryReviewTestCase.php';

use Tests\Support\PepDirectoryReviewTestCase;

/** Read-only plain fixture inspection inside the inherited rollback boundary. */
final class PlainCatalogHarnessTest extends PepDirectoryReviewTestCase
{
    protected function usesAuthorizationOverlay(): bool
    {
        return false;
    }

    public function test_plain_catalog_keeps_original_inventory_without_installing_authorization(): void
    {
        self::assertSame(1, $this->db->transactionLevel());
        $schema = $this->db->getSchemaBuilder();
        $tables = $schema->getTableListing('public', false);
        sort($tables);
        self::assertSame(self::TABLES, $tables);
        self::assertSame(['id', 'label'], $schema->getColumnListing('users'));
        self::assertSame(['id', 'label'], $schema->getColumnListing('fuentes'));
        self::assertFalse(isset($this->authorization));
        self::assertFalse($this->committedAuthorization);
        self::assertNull($this->db->selectOne("SELECT to_regprocedure('public.pep_invalidate_source()') AS name")->name);
        self::assertSame(0, (int) $this->db->selectOne("SELECT count(*) AS n FROM pg_catalog.pg_trigger WHERE tgname = 'pep_fuente_invalidate'")->n);
    }
}
