<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Database\Connection;
use RuntimeException;

/** Native evidence collector, deliberately not an installed-schema validator. */
final class PepDirectoryInstalledCatalog
{
    public static function collect(Connection $connection): array
    {
        if ($connection->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Catalog collection requires PostgreSQL 17.');
        }
        // No connector, bootstrap, SQL override, session mutation, or transaction management.
        $context = $connection->selectOne("SELECT pg_catalog.current_setting('server_version_num')::integer AS version, current_user AS actor, pg_catalog.current_schemas(false)::text AS schemas");
        if ($context === null || (int) $context->version < 170000 || (int) $context->version >= 180000
            || $context->actor !== 'postgres' || $context->schemas !== '{public}') {
            throw new RuntimeException('Unsupported catalog version/user/effective namespace.');
        }
        $sql = file_get_contents(__DIR__.'/../Fixtures/PepDirectory/schema-contracts/installed-pg17.sql');
        if ($sql === false) {
            throw new RuntimeException('Fixed catalog projection is unavailable.');
        }
        $row = $connection->selectOne($sql);
        if ($row === null || !isset($row->catalog) || !is_string($row->catalog)) {
            throw new RuntimeException('Invalid catalog result.');
        }
        $catalog = json_decode($row->catalog, true, 512, JSON_THROW_ON_ERROR);
        $sections = ['metadata', 'relations', 'sequences', 'columns', 'constraints', 'indexes', 'triggers', 'routines', 'policies', 'objects'];
        if (!is_array($catalog) || count($catalog) !== count($sections)) {
            throw new RuntimeException('Invalid catalog envelope.');
        }
        foreach ($sections as $section) {
            if (!isset($catalog[$section]) || !is_array($catalog[$section])
                || ($section !== 'metadata' && !array_is_list($catalog[$section]))) {
                throw new RuntimeException('Invalid catalog section: '.$section);
            }
            if ($section !== 'metadata') {
                foreach ($catalog[$section] as $entry) {
                    if (!is_array($entry) || !is_string($entry['identity'] ?? null)) {
                        throw new RuntimeException('Invalid catalog entry: '.$section);
                    }
                }
            }
        }
        if (($catalog['metadata']['projection'] ?? null) !== 'pg17-native-collection-v1'
            || ($catalog['metadata']['server_version_num'] ?? null) !== (int) $context->version
            || ($catalog['metadata']['user'] ?? null) !== 'postgres'
            || ($catalog['metadata']['schemas'] ?? null) !== ['public']) {
            throw new RuntimeException('Unsupported catalog metadata.');
        }

        return $catalog;
    }
}
