<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pep_directory_sources', function (Blueprint $table): void {
            $table->id();
            $table->string('identity')->unique();
            $table->foreignId('fuente_id')->nullable()->index()->constrained('fuentes')->nullOnDelete();
            $table->unsignedBigInteger('baseline_observation_id')->nullable();
            $table->unsignedBigInteger('version')->default(0);
        });

        Schema::create('pep_directory_observations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('source_id')->constrained('pep_directory_sources')->restrictOnDelete();
            $table->string('submission_id', 128);
            $table->string('capture_identity');
            $table->char('content_sha256', 64); // Deliberately not unique: unchanged captures are history.
            $table->json('provenance');
            $table->json('payload'); // Full immutable Observation wire object, including original offsets.
            $table->string('observed_at', 64);
            $table->enum('disposition', ['baseline', 'rebaselined', 'compared', 'rejected']);
            $table->json('reasons');
            $table->timestampTz('received_at', 6)->useCurrent();
            $table->unique(['source_id', 'submission_id'], 'pep_obs_submission_unique');
            $table->unique(['source_id', 'id'], 'pep_obs_source_id_unique');
        });

        Schema::table('pep_directory_sources', function (Blueprint $table): void {
            $table->foreign(['id', 'baseline_observation_id'], 'pep_source_baseline_fk')
                ->references(['source_id', 'id'])->on('pep_directory_observations')->restrictOnDelete();
        });

        Schema::create('pep_directory_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('previous_observation_id')->nullable();
            $table->unsignedBigInteger('current_observation_id');
            $table->unsignedInteger('event_index');
            $table->json('payload'); // Full Event: groups, evidence, detection time and nullable effective_date.
            $table->enum('review_state', ['pending', 'confirmed', 'discarded'])->default('pending');
            $table->unsignedBigInteger('review_version')->default(0);
            $table->unique(['current_observation_id', 'event_index'], 'pep_event_identity_unique');
            $table->index(['source_id', 'review_state'], 'pep_event_source_review_idx');
            $table->foreign(['source_id', 'previous_observation_id'], 'pep_event_previous_fk')
                ->references(['source_id', 'id'])->on('pep_directory_observations')->restrictOnDelete();
            $table->foreign(['source_id', 'current_observation_id'], 'pep_event_current_fk')
                ->references(['source_id', 'id'])->on('pep_directory_observations')->restrictOnDelete();
        });

        Schema::create('pep_directory_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('pep_directory_events')->restrictOnDelete();
            $table->unsignedBigInteger('version');
            $table->foreignId('user_id')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('actor_identity');
            $table->json('actor_snapshot');
            $table->timestampTz('decided_at', 6)->useCurrent();
            $table->text('reason');
            $table->enum('prior_state', ['pending', 'confirmed', 'discarded']);
            $table->enum('new_state', ['pending', 'confirmed', 'discarded']);
            $table->unique(['event_id', 'version'], 'pep_review_version_unique');
        });
    }

    public function down(): void
    {
        Schema::table('pep_directory_sources', function (Blueprint $table): void {
            $table->dropForeign('pep_source_baseline_fk');
        });
        Schema::dropIfExists('pep_directory_reviews');
        Schema::dropIfExists('pep_directory_events');
        Schema::dropIfExists('pep_directory_observations');
        Schema::dropIfExists('pep_directory_sources');
    }
};
