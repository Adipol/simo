<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DeploymentRunbookTest extends TestCase
{
    private const PRODUCTION_MIGRATION_COMMAND = 'sudo -u www-data php /var/www/simo/artisan migrate --force';

    private const UNFORCED_PRODUCTION_MIGRATION_COMMAND = 'sudo -u www-data php /var/www/simo/artisan migrate';

    public function test_canonical_vps_workflow_forces_production_migrations(): void
    {
        $workflow = $this->canonicalVpsWorkflow($this->readRunbook());
        $forcedLine = '/^'.preg_quote(self::PRODUCTION_MIGRATION_COMMAND, '/').'\r?$/m';

        $this->assertMatchesRegularExpression(
            $forcedLine,
            $workflow,
            'The canonical VPS update workflow must force Laravel migrations in production.',
        );
    }

    public function test_runbook_contains_no_unforced_production_migration_line(): void
    {
        $runbook = $this->readRunbook();
        $unforcedLine = '/^'.preg_quote(self::UNFORCED_PRODUCTION_MIGRATION_COMMAND, '/').'\r?$/m';

        $this->assertDoesNotMatchRegularExpression(
            $unforcedLine,
            $runbook,
            'The deployment runbook must not contain an unforced production migration command.',
        );
    }

    private function readRunbook(): string
    {
        $path = dirname(__DIR__, 2).'/DEPLOY.md';

        if (! is_readable($path)) {
            $this->fail(sprintf('Deployment runbook is not readable: %s', $path));
        }

        $runbook = file_get_contents($path);

        if ($runbook === false) {
            $this->fail(sprintf('Deployment runbook could not be read: %s', $path));
        }

        return $runbook;
    }

    private function canonicalVpsWorkflow(string $runbook): string
    {
        $matched = preg_match(
            '/^## Workflow de actualización en VPS\R(?<section>.*?)(?=^##(?:\h|$)|\z)/ms',
            $runbook,
            $matches,
        );

        $this->assertSame(
            1,
            $matched,
            'The canonical "Workflow de actualización en VPS" section could not be extracted.',
        );

        return $matches['section'];
    }
}
