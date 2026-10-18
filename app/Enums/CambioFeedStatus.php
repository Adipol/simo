<?php

declare(strict_types=1);

namespace App\Enums;

enum CambioFeedStatus: string
{
    case Primary = 'primary';
    case Review = 'review';
    case Suppressed = 'suppressed';
    case SourceHealth = 'source_health';
}
