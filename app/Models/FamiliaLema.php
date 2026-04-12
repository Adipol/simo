<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CategoriaFamilia;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class FamiliaLema extends Model
{
    use HasFactory;

    protected $table = 'familias_lemas';

    protected $fillable = ['raiz', 'variantes', 'categoria', 'activo'];

    protected $casts = [
        'variantes' => 'array',
        'activo' => 'boolean',
        'categoria' => CategoriaFamilia::class,
    ];

    public function scopeActive(Builder $q): void
    {
        $q->where('activo', true);
    }

    public function scopeByCategoria(Builder $q, CategoriaFamilia|string $cat): void
    {
        $value = $cat instanceof CategoriaFamilia ? $cat->value : $cat;
        $q->where('categoria', $value);
    }
}
