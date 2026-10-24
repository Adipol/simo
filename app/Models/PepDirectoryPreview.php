<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class PepDirectoryPreview extends Model
{
    public $timestamps = false;
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return ['source_id' => 'integer', 'configuration_id' => 'integer', 'capture_id' => 'integer',
            'eligibility_version' => 'integer', 'wire_payload' => 'array', 'qualification' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(PepDirectorySource::class, 'source_id');
    }

    public function configuration(): BelongsTo
    {
        return $this->belongsTo(PepDirectoryConfiguration::class, 'configuration_id');
    }

    public function capture(): BelongsTo
    {
        return $this->belongsTo(PepDirectoryCapture::class, 'capture_id');
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
