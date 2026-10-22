<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Read seam only; the Python writer owns source identity and baseline advancement. */
final class PepDirectorySource extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['version' => 'integer', 'fuente_id' => 'integer', 'baseline_observation_id' => 'integer'];
    }

    public function fuente(): BelongsTo
    {
        return $this->belongsTo(Fuente::class);
    }

    public function baselineObservation(): BelongsTo
    {
        return $this->belongsTo(PepDirectoryObservation::class, 'baseline_observation_id');
    }

    public function observations(): HasMany
    {
        return $this->hasMany(PepDirectoryObservation::class, 'source_id')->orderBy('id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(PepDirectoryEvent::class, 'source_id')->orderBy('id');
    }

    public function scopeWithHolderHistory(Builder $query): void
    {
        $query->with(['baselineObservation', 'observations']);
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
