<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Original wire dates remain strings; do not normalize their offsets or re-encode evidence. */
final class PepDirectoryObservation extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['source_id' => 'integer', 'payload' => 'array', 'provenance' => 'array',
            'reasons' => 'array', 'received_at' => 'immutable_datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(PepDirectorySource::class, 'source_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PepDirectoryEvent::class, 'current_observation_id')->orderBy('event_index');
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
