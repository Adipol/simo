<?php

declare(strict_types=1);

namespace App\Enums;

enum TipoFeedback: string
{
    case Correcto = 'correcto';
    case Incorrecto = 'incorrecto';
}
