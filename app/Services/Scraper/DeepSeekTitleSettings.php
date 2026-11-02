<?php

declare(strict_types=1);

namespace App\Services\Scraper;

final readonly class DeepSeekTitleSettings
{
    public bool $enabled;
    public ?int $activationAfterId;
    public int $timeout;
    public int $batchSize;
    public string $model;
    private string $apiKey;
    private bool $valid;

    public function __construct()
    {
        $settings = config('services.deepseek_title_gate');
        $settings = is_array($settings) ? $settings : [];
        $this->enabled = ($settings['enabled'] ?? false) === true;
        $this->activationAfterId = self::nonnegativeInteger($settings['activation_after_id'] ?? null);
        $timeout = self::nonnegativeInteger($settings['timeout'] ?? null);
        $this->timeout = $timeout !== null && $timeout >= 1 && $timeout <= 20 ? $timeout : 20;
        $this->batchSize = 1;
        $model = $settings['model'] ?? null;
        $this->model = is_string($model) && preg_match('/\A[a-zA-Z0-9._-]{1,128}\z/', $model) === 1 ? $model : '';
        $key = $settings['api_key'] ?? null;
        $this->apiKey = is_string($key) && preg_match('/\A[\x21-\x7E]+\z/', $key) === 1 ? $key : '';
        $this->valid = $this->activationAfterId !== null && $timeout === $this->timeout
            && self::nonnegativeInteger($settings['batch_size'] ?? null) === 1
            && $this->model !== '' && $this->apiKey !== '';
    }

    public function configured(): bool
    {
        // Readiness only: no request has verified credentials or provider availability.
        return $this->enabled && $this->valid;
    }

    public function apiKey(): string
    {
        return $this->apiKey;
    }

    private static function nonnegativeInteger(string|int|float|bool|array|object|null $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (! is_string($value) || preg_match('/\A(?:0|[1-9][0-9]*)\z/', $value) !== 1) {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return is_int($parsed) ? $parsed : null;
    }
}
