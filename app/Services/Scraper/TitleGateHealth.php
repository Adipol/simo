<?php

declare(strict_types=1);

namespace App\Services\Scraper;

use App\Models\ResultadoScraping;

final class TitleGateHealth
{
    /** @return array{enabled: bool, configured: bool, configuration: string, last_status: string, last_attempt_at: ?string, last_success_at: ?string, label: string} */
    public function status(): array
    {
        $settings = new DeepSeekTitleSettings;
        $valid = ['relevant', 'doubtful', 'unrelated'];
        $latest = ResultadoScraping::whereNotNull('deepseek_title_attempted_at')
            ->orderByDesc('deepseek_title_attempted_at')->orderByDesc('id')
            ->first(['deepseek_title_status', 'deepseek_title_attempted_at']);
        $success = ResultadoScraping::whereNotNull('deepseek_title_attempted_at')
            ->whereIn('deepseek_title_status', $valid)
            ->orderByDesc('deepseek_title_attempted_at')->orderByDesc('id')
            ->first(['deepseek_title_attempted_at']);
        $status = in_array($latest?->deepseek_title_status, [...$valid, 'technical_error', 'invalid_response'], true)
            ? $latest->deepseek_title_status : 'unknown';

        return [
            'enabled' => $settings->enabled,
            'configured' => $settings->configured(),
            'configuration' => ! $settings->enabled ? 'disabled' : ($settings->configured() ? 'ready' : 'not_configured'),
            'last_status' => $status,
            'last_attempt_at' => $latest?->deepseek_title_attempted_at?->format('Y-m-d H:i:s'),
            'last_success_at' => $success?->deepseek_title_attempted_at?->format('Y-m-d H:i:s'),
            'label' => in_array($status, $valid, true) ? 'Última llamada validada'
                : (in_array($status, ['technical_error', 'invalid_response'], true) ? 'Último intento fallido'
                    : ($settings->configured() && $latest === null ? 'Configurada; aún sin llamadas verificadas' : 'Sin llamada verificada')),
        ];
    }
}
