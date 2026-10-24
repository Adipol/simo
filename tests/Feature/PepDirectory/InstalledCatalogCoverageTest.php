<?php

declare(strict_types=1);

namespace Tests\Feature\PepDirectory;

require_once __DIR__.'/../../Support/GuardsDisposableDatabase.php';
require_once __DIR__.'/../../Support/PepDirectoryReviewTestCase.php';
require_once __DIR__.'/../../Support/PepDirectoryInstalledCatalog.php';

use RuntimeException;
use Tests\Support\PepDirectoryInstalledCatalog;
use Tests\Support\PepDirectoryReviewTestCase;

/** Evidence sensitivity, not admission policy. No rows or sequences are allocated. */
final class InstalledCatalogCoverageTest extends PepDirectoryReviewTestCase
{
    protected function usesAuthorizationOverlay(): bool
    {
        return false;
    }

    private function namespaceState(): array
    {
        return (array) $this->db->selectOne("SELECT nspowner::text AS owner_oid, pg_catalog.pg_get_userbyid(nspowner) AS owner, nspacl::text AS acl FROM pg_catalog.pg_namespace WHERE nspname = 'public'");
    }

    private function dependencyState(): array
    {
        // Independent raw native rows: every extension membership, plus original target dependencies.
        // OIDs are retained solely to prove exact rollback, not exported as catalog evidence.
        return array_map(static fn (object $row): array => (array) $row, $this->db->select("SELECT d.* FROM pg_catalog.pg_depend d WHERE d.deptype = 'e' OR (d.classid = 'pg_catalog.pg_class'::regclass AND d.objid = 'public.users'::regclass) OR (d.classid = 'pg_catalog.pg_namespace'::regclass AND d.objid = (SELECT oid FROM pg_catalog.pg_namespace WHERE nspname = 'public')) ORDER BY classid, objid, objsubid, refclassid, refobjid, refobjsubid, deptype"));
    }

    private function changedEvidence(string $ddl): void
    {
        $namespace = $this->namespaceState();
        $dependencies = $this->dependencyState();
        $before = PepDirectoryInstalledCatalog::collect($this->db);
        $this->db->beginTransaction();
        try {
            $this->db->statement($ddl);
            $namespaceAfter = $this->namespaceState();
            $dependenciesAfter = $this->dependencyState();
            if (str_contains($ddl, 'EXTENSION')) {
                $automaticTypes = array_map(static fn (object $row): array => (array) $row, $this->db->select("SELECT d.* FROM pg_catalog.pg_depend d JOIN pg_catalog.pg_type t ON d.classid = 'pg_catalog.pg_type'::regclass AND t.oid = d.objid JOIN pg_catalog.pg_extension e ON d.refclassid = 'pg_catalog.pg_extension'::regclass AND e.oid = d.refobjid WHERE d.deptype = 'e' AND e.extname = 'plpgsql' AND (t.typrelid = 'public.users'::regclass OR t.typelem = (SELECT reltype FROM pg_catalog.pg_class WHERE oid = 'public.users'::regclass)) ORDER BY d.classid, d.objid, d.objsubid, d.refclassid, d.refobjid, d.refobjsubid, d.deptype"));
                self::assertNotEmpty($automaticTypes, 'Attachment must expose an actual automatic pg_type membership edge.');
                foreach ($automaticTypes as $dependency) {
                    self::assertTrue(in_array($dependency, $dependenciesAfter, true), 'Automatic pg_type membership missing from raw rollback snapshot.');
                }
                echo 'AUTOMATIC PG_TYPE MEMBERSHIP ROWS CAPTURED: '.count($automaticTypes).PHP_EOL;
                self::assertNotSame($dependencies, $dependenciesAfter, 'Native membership must really change.');
                self::assertSame(1, (int) $this->db->selectOne("SELECT count(*) AS n FROM pg_catalog.pg_depend d JOIN pg_catalog.pg_extension e ON e.oid = d.refobjid WHERE d.classid = 'pg_catalog.pg_class'::regclass AND d.objid = 'public.users'::regclass AND d.refclassid = 'pg_catalog.pg_extension'::regclass AND d.deptype = 'e' AND e.extname = 'plpgsql'")->n);
            } else {
                self::assertNotSame($namespace, $namespaceAfter, 'Native namespace ACL must really change.');
            }
            $after = PepDirectoryInstalledCatalog::collect($this->db);
            self::assertSame($before['objects'], $after['objects'], 'Object inventory must stay identical.');
            self::assertNotSame($before, $after, 'Collected evidence must expose '.$ddl);
            if (str_contains($ddl, 'EXTENSION')) {
                $members = array_values(array_filter($after['extension_memberships'], static fn (array $entry): bool => $entry['member_identity'] === 'public.users' && $entry['member_type'] === 'table'));
                self::assertCount(1, $members);
                self::assertSame('plpgsql', $members[0]['extension']);
                self::assertSame('table', $members[0]['member_type']);
                self::assertSame('public', $members[0]['member_schema']);
            } else {
                self::assertSame($namespaceAfter['owner'], $after['namespaces'][0]['owner']);
                self::assertSame('public', $after['namespaces'][0]['identity']);
                self::assertNotSame($before['namespaces'][0]['acl'], $after['namespaces'][0]['acl']);
            }
        } finally {
            $this->db->rollBack();
            self::assertSame(1, $this->db->transactionLevel());
            self::assertSame($namespace, $this->namespaceState(), 'Native namespace owner/ACL restoration failed; stop without repair.');
            $restored = $this->dependencyState();
            self::assertSame($dependencies, $restored, 'Native dependency restoration failed; stop without repair.');
            $memberships = static fn (array $rows): array => array_values(array_filter($rows, static fn (array $row): bool => $row['deptype'] === 'e'));
            self::assertSame($memberships($dependencies), $memberships($restored), 'Full raw extension-membership restoration failed; stop without repair.');
            echo 'ALL RAW EXTENSION MEMBERSHIPS RESTORED: before='.count($memberships($dependencies)).' after='.count($memberships($restored)).PHP_EOL;
            echo "NATIVE NAMESPACE OWNER/ACL AND DEPENDENCIES RESTORED\n";
        }
    }

    public function test_namespace_create_privilege_changes_collected_evidence(): void
    {
        $hasCreate = (bool) $this->db->selectOne("SELECT EXISTS (SELECT 1 FROM pg_catalog.pg_namespace n CROSS JOIN LATERAL pg_catalog.aclexplode(COALESCE(n.nspacl, pg_catalog.acldefault('n', n.nspowner))) a WHERE n.nspname = 'public' AND a.grantee = 0 AND a.privilege_type = 'CREATE') AS allowed")->allowed;
        $this->changedEvidence($hasCreate ? 'REVOKE CREATE ON SCHEMA public FROM PUBLIC' : 'GRANT CREATE ON SCHEMA public TO PUBLIC');
    }

    public function test_extension_membership_changes_collected_evidence(): void
    {
        $eligible = $this->db->selectOne("SELECT current_user AS actor, pg_catalog.pg_get_userbyid(e.extowner) AS extension_owner, pg_catalog.pg_get_userbyid(c.relowner) AS table_owner FROM pg_catalog.pg_extension e CROSS JOIN pg_catalog.pg_class c WHERE e.extname = 'plpgsql' AND c.oid = 'public.users'::regclass");
        if ($eligible === null || $eligible->actor !== 'postgres' || $eligible->extension_owner !== 'postgres' || $eligible->table_owner !== 'postgres') {
            throw new RuntimeException('Blocker: installed plpgsql and public.users must both be owned by current postgres.');
        }
        // Eligibility is target-only; unrelated memberships (including plpgsql's language) are valid.
        $targetMemberships = $this->db->selectOne("SELECT count(*) AS n FROM pg_catalog.pg_depend d WHERE d.deptype = 'e' AND ((d.classid = 'pg_catalog.pg_class'::regclass AND d.objid = 'public.users'::regclass) OR (d.classid = 'pg_catalog.pg_namespace'::regclass AND d.objid = (SELECT oid FROM pg_catalog.pg_namespace WHERE nspname = 'public')))");
        if ((int) $targetMemberships->n !== 0) {
            throw new RuntimeException('Blocker: public.users/public already has extension membership; no detach or alternate target authorized.');
        }
        $this->changedEvidence('ALTER EXTENSION plpgsql ADD TABLE public.users');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (isset($this->db)) {
            self::assertNull($this->db->selectOne("SELECT pg_catalog.to_regprocedure('public.pep_invalidate_source()') AS name")->name);
            self::assertSame(0, (int) $this->db->selectOne("SELECT count(*) AS n FROM pg_catalog.pg_trigger WHERE tgname = 'pep_fuente_invalidate'")->n);
            echo "FUNCTION/TRIGGER ABSENT AFTER OUTER ROLLBACK\n";
        }
    }
}
