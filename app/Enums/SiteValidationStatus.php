<?php

declare(strict_types=1);

namespace App\Enums;

enum SiteValidationStatus: string
{
    case Pending = 'pending';
    case Validating = 'validating';
    case Valid = 'valid';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pendiente',
            self::Validating => 'Validando',
            self::Valid => 'Validado',
            self::Failed => 'Falló',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::Pending => 'bg-amber-50 text-amber-700',
            self::Validating => 'bg-blue-50 text-blue-700',
            self::Valid => 'bg-green-50 text-green-700',
            self::Failed => 'bg-red-50 text-red-700',
        };
    }
}
