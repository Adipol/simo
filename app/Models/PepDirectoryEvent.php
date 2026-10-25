<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Immutable proposal evidence plus the review service's two-field projection. */
final class PepDirectoryEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['source_id' => 'integer', 'previous_observation_id' => 'integer', 'current_observation_id' => 'integer',
            'event_index' => 'integer', 'payload' => 'array', 'review_version' => 'integer'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(PepDirectorySource::class, 'source_id');
    }

    public function previousObservation(): BelongsTo
    {
        return $this->belongsTo(PepDirectoryObservation::class, 'previous_observation_id');
    }

    public function currentObservation(): BelongsTo
    {
        return $this->belongsTo(PepDirectoryObservation::class, 'current_observation_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(PepDirectoryReview::class, 'event_id')->orderBy('version');
    }

    public function scopeWithReviewEvidence(Builder $query): void
    {
        $query->with(['source', 'previousObservation', 'currentObservation', 'reviews.user']);
    }

    public function save(array $options = []): bool
    {
        throw new LogicException('Directory read models cannot be persisted.');
    }

    public function delete(): ?bool
    {
        throw new LogicException('Directory read models cannot be persisted.');
    }
}
