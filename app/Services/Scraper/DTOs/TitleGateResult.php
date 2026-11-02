<?php

declare(strict_types=1);

namespace App\Services\Scraper\DTOs;

final readonly class TitleGateResult
{
    private function __construct(
        public string $status,
        public ?string $decision,
        public ?string $evidence,
        public ?string $reason,
        public ?string $errorCode,
        public ?string $model,
        public string $promptVersion,
    ) {}

    public static function fromArray(array $data, int $id, string $title, string $model, string $promptVersion): ?self
    {
        $keys = array_keys($data);
        sort($keys);
        if ($keys !== ['decision', 'evidencia', 'id', 'motivo'] || $data['id'] !== $id
            || ! in_array($data['decision'], ['relevant', 'doubtful', 'unrelated'], true)
            || ! is_string($data['evidencia']) || trim($data['evidencia']) === ''
            || mb_strlen($data['evidencia']) > 180 || ! str_contains($title, $data['evidencia'])
            || ! is_string($data['motivo']) || trim($data['motivo']) === '' || mb_strlen($data['motivo']) > 160) {
            return null;
        }

        return new self($data['decision'], $data['decision'], $data['evidencia'], $data['motivo'], null, $model, $promptVersion);
    }

    public static function failure(string $errorCode, string $promptVersion, bool $invalidResponse = false): self
    {
        return new self($invalidResponse ? 'invalid_response' : 'technical_error', null, null, null, $errorCode, null, $promptVersion);
    }

    public function toPersistenceAttributes(): array
    {
        return [
            'deepseek_title_status' => $this->status,
            'deepseek_title_reason' => $this->reason,
            'deepseek_title_evidence' => $this->evidence,
            'deepseek_title_error_code' => $this->errorCode,
            'deepseek_title_attempted_at' => now(),
            'deepseek_title_model' => $this->model,
            'deepseek_title_prompt_version' => $this->promptVersion,
        ];
    }
}
