<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Global SQLite test discovery must not install PostgreSQL qualification.
        if (DB::connection()->getDriverName() === 'sqlite'
            && app()->runningUnitTests()
            && defined('PHPUNIT_COMPOSER_INSTALL')) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Directory qualification requires PostgreSQL; SQLite approval is unsupported.');
        }
        Schema::table('pep_directory_sources', function (Blueprint $table): void {
            $table->boolean('configurable')->default(false);
            $table->bigInteger('eligibility_version')->default(0);
            $table->bigInteger('draft_config_id')->nullable();
            $table->bigInteger('current_preview_id')->nullable();
            $table->bigInteger('approved_decision_id')->nullable();
        });
        Schema::create('pep_directory_configurations', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->bigInteger('source_id');
            $table->json('declaration');
            $table->binary('canonical_bytes');
            $table->string('revision', 255); // Preserve the actual configured-html-v1:<sha> identity.
            $table->text('binding_url');
            $table->text('directory_url');
            $table->string('creator_identity');
            $table->json('creator_snapshot');
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->unique(['source_id', 'id'], 'pep_cfg_source_uq');
            $table->unique(['source_id', 'revision'], 'pep_cfg_revision_uq');
            $table->foreign('source_id', 'pep_cfg_source_fk')->references('id')->on('pep_directory_sources')->restrictOnDelete();
        });
        Schema::create('pep_directory_captures', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->bigInteger('source_id');
            $table->binary('body_bytes');
            $table->char('content_sha256', 64);
            $table->json('metadata');
            $table->json('provenance');
            $table->timestampTz('captured_at', 6);
            $table->unique(['source_id', 'id'], 'pep_cap_source_uq');
            $table->foreign('source_id', 'pep_cap_source_fk')->references('id')->on('pep_directory_sources')->restrictOnDelete();
        });
        Schema::create('pep_directory_previews', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->bigInteger('source_id');
            $table->bigInteger('configuration_id');
            $table->bigInteger('capture_id');
            $table->binary('result_bytes');
            $table->char('result_sha256', 64);
            $table->json('wire_payload');
            $table->string('validity', 32);
            $table->json('qualification');
            $table->bigInteger('eligibility_version');
            $table->timestampTz('created_at', 6)->useCurrent();
            $table->unique(['source_id', 'id'], 'pep_pre_source_uq');
            $table->foreign('source_id', 'pep_pre_source_fk')->references('id')->on('pep_directory_sources')->restrictOnDelete();
            $table->foreign(['source_id', 'configuration_id'], 'pep_pre_config_fk')->references(['source_id', 'id'])->on('pep_directory_configurations')->restrictOnDelete();
            $table->foreign(['source_id', 'capture_id'], 'pep_pre_capture_fk')->references(['source_id', 'id'])->on('pep_directory_captures')->restrictOnDelete();
        });
        Schema::create('pep_directory_configuration_decisions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->bigInteger('source_id');
            $table->bigInteger('sequence');
            $table->string('action', 32);
            $table->bigInteger('configuration_id')->nullable();
            $table->bigInteger('preview_id')->nullable();
            $table->bigInteger('prior_approval_id')->nullable();
            $table->bigInteger('new_approval_id')->nullable();
            $table->string('actor_kind', 16);
            $table->string('actor_identity');
            $table->json('actor_snapshot');
            $table->text('reason');
            $table->timestampTz('decided_at', 6)->useCurrent();
            $table->unique(['source_id', 'id'], 'pep_dec_source_uq');
            $table->unique(['source_id', 'sequence'], 'pep_dec_sequence_uq');
            $table->foreign('source_id', 'pep_dec_source_fk')->references('id')->on('pep_directory_sources')->restrictOnDelete();
            $table->foreign(['source_id', 'configuration_id'], 'pep_dec_config_fk')->references(['source_id', 'id'])->on('pep_directory_configurations')->restrictOnDelete();
            $table->foreign(['source_id', 'preview_id'], 'pep_dec_preview_fk')->references(['source_id', 'id'])->on('pep_directory_previews')->restrictOnDelete();
            $table->foreign(['source_id', 'prior_approval_id'], 'pep_dec_prior_fk')->references(['source_id', 'id'])->on('pep_directory_configuration_decisions')->restrictOnDelete();
            $table->foreign(['source_id', 'new_approval_id'], 'pep_dec_new_fk')->references(['source_id', 'id'])->on('pep_directory_configuration_decisions')->restrictOnDelete();
        });
        foreach (['configurations' => 'cfg', 'captures' => 'cap', 'previews' => 'pre', 'configuration_decisions' => 'dec'] as $suffix => $short) {
            Schema::table('pep_directory_'.$suffix, function (Blueprint $table) use ($suffix, $short): void {
                // PostgreSQL grammar names serial primary keys implicitly; rename via Schema.
                $table->renameIndex('pep_directory_'.$suffix.'_pkey', 'pep_'.$short.'_pk');
            });
        }
        Schema::table('pep_directory_sources', function (Blueprint $table): void {
            $table->foreign(['id', 'draft_config_id'], 'pep_src_draft_fk')->references(['source_id', 'id'])->on('pep_directory_configurations')->restrictOnDelete();
            $table->foreign(['id', 'current_preview_id'], 'pep_src_preview_fk')->references(['source_id', 'id'])->on('pep_directory_previews')->restrictOnDelete();
            $table->foreign(['id', 'approved_decision_id'], 'pep_src_approval_fk')->references(['source_id', 'id'])->on('pep_directory_configuration_decisions')->restrictOnDelete();
        });
        Schema::table('pep_directory_observations', function (Blueprint $table): void {
            $table->bigInteger('configuration_id')->nullable();
            $table->bigInteger('capture_id')->nullable();
            $table->bigInteger('approval_decision_id')->nullable();
            $table->foreign(['source_id', 'configuration_id'], 'pep_obs_config_fk')->references(['source_id', 'id'])->on('pep_directory_configurations')->restrictOnDelete();
            $table->foreign(['source_id', 'capture_id'], 'pep_obs_capture_fk')->references(['source_id', 'id'])->on('pep_directory_captures')->restrictOnDelete();
            $table->foreign(['source_id', 'approval_decision_id'], 'pep_obs_approval_fk')->references(['source_id', 'id'])->on('pep_directory_configuration_decisions')->restrictOnDelete();
        });
        // PostgreSQL-specific checks and partial uniqueness cannot be expressed by portable Blueprint.
        DB::statement('ALTER TABLE public.pep_directory_sources ADD CONSTRAINT pep_src_eligibility_ck CHECK (eligibility_version >= 0)');
        DB::statement('ALTER TABLE public.pep_directory_previews ADD CONSTRAINT pep_pre_eligibility_ck CHECK (eligibility_version >= 0)');
        DB::statement("ALTER TABLE public.pep_directory_configuration_decisions ADD CONSTRAINT pep_dec_actor_ck CHECK (actor_kind IN ('system', 'human')), ADD CONSTRAINT pep_dec_sequence_ck CHECK (sequence > 0)");
        DB::statement('CREATE UNIQUE INDEX pep_src_live_uq ON public.pep_directory_sources (fuente_id) WHERE configurable = true AND fuente_id IS NOT NULL');
        // Fuente is already locked by the statement; serialize registered projections in id order.
        DB::unprepared(<<<'SQL'
CREATE FUNCTION public.pep_invalidate_source() RETURNS trigger
LANGUAGE plpgsql SECURITY INVOKER SET search_path = pg_catalog, public AS $$
DECLARE
    source_row record;
    binding_changed boolean;
    cause text;
BEGIN
    IF TG_OP = 'DELETE' THEN
        binding_changed := true;
        cause := 'fuente deleted';
    ELSE
        binding_changed := NEW.url IS DISTINCT FROM OLD.url OR NEW.tipo IS DISTINCT FROM OLD.tipo;
        IF NOT binding_changed AND NOT (OLD.activo IS TRUE AND NEW.activo IS FALSE) THEN
            RETURN NEW;
        END IF;
        cause := CASE WHEN binding_changed THEN 'fuente binding changed' ELSE 'fuente deactivated' END;
    END IF;
    FOR source_row IN
        SELECT * FROM public.pep_directory_sources
        WHERE fuente_id = OLD.id AND configurable = true ORDER BY id FOR UPDATE
    LOOP
        INSERT INTO public.pep_directory_configuration_decisions
            (source_id, sequence, action, configuration_id, preview_id, prior_approval_id,
             new_approval_id, actor_kind, actor_identity, actor_snapshot, reason)
        VALUES (source_row.id, source_row.eligibility_version + 1, 'invalidate',
            source_row.draft_config_id, source_row.current_preview_id, source_row.approved_decision_id,
            NULL, 'system', 'fuente-invalidation', '{"mechanism":"fuente-trigger"}', cause);
        UPDATE public.pep_directory_sources
        SET eligibility_version = source_row.eligibility_version + 1,
            approved_decision_id = NULL, current_preview_id = NULL,
            draft_config_id = CASE WHEN binding_changed THEN NULL ELSE source_row.draft_config_id END
        WHERE id = source_row.id;
    END LOOP;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$;
CREATE TRIGGER pep_fuente_invalidate BEFORE UPDATE OR DELETE ON public.fuentes
FOR EACH ROW EXECUTE FUNCTION public.pep_invalidate_source();
SQL);

    }

    public function down(): void
    {
        throw new RuntimeException('Qualification history requires explicit archival review; automatic destructive rollback is prohibited.');
    }
};
