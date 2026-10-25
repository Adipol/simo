<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class PepDirectoryConfigurationDecision extends Model
{
    public $timestamps = false;
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['source_id' => 'integer', 'sequence' => 'integer', 'configuration_id' => 'integer', 'preview_id' => 'integer',
            'prior_approval_id' => 'integer', 'new_approval_id' => 'integer', 'actor_snapshot' => 'array', 'decided_at' => 'immutable_datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(PepDirectorySource::class, 'source_id');
    }

    public function configuration(): BelongsTo
    {
        return $this->belongsTo(PepDirectoryConfiguration::class, 'configuration_id');
    }

    public function preview(): BelongsTo
    {
        return $this->belongsTo(PepDirectoryPreview::class, 'preview_id');
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
