<?php

declare(strict_types=1);

namespace App\Enums;

enum CategoriaCorreccion: string
{
    case PEP = 'PEP';
    case OPI = 'OPI';
    case NoRel = 'NO_REL';
}
