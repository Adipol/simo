<?php

declare(strict_types=1);

namespace App\Enums;

enum EntidadTipo: string
{
    case Todas = 'todas';
    case Publica = 'publica';
    case Ambas = 'ambas';
}
