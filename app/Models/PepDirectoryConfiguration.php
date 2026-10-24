<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Read-only stored evidence; bulk SQL is not an immutability guarantee. */
final class PepDirectoryConfiguration extends Model
{
    public $timestamps = false;
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['source_id' => 'integer', 'declaration' => 'array', 'creator_snapshot' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(PepDirectorySource::class, 'source_id');
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
