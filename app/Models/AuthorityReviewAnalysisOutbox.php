<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuthorityReviewAnalysisOutbox extends Model
{
    public const MAX_PROCESSING_ATTEMPTS = 3;

    protected $table = 'authority_review_analysis_outbox';

    protected $fillable = [
        'idempotency_key', 'authority_removal_review_id', 'cambio_id',
        'dispatch_claim_token', 'dispatch_claimed_at', 'dispatched_at',
        'processing_claim_token', 'processing_claimed_at', 'processing_attempts',
        'next_attempt_at', 'terminal_at', 'last_error', 'failure_context', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'dispatch_claimed_at' => 'datetime',
            'dispatched_at' => 'datetime',
            'processing_claimed_at' => 'datetime',
            'processing_attempts' => 'integer',
            'next_attempt_at' => 'datetime',
            'terminal_at' => 'datetime',
            'failure_context' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(AuthorityRemovalReview::class, 'authority_removal_review_id');
    }

    public function cambio(): BelongsTo
    {
        return $this->belongsTo(Cambio::class);
    }
}
