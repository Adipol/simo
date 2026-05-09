<?php

declare(strict_types=1);

namespace App\Services\Fuente;

use App\Models\Fuente;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class FuenteService
{
    /**
     * Lista paginada de fuentes con filtros opcionales.
     *
     * @param  array{busqueda?:string,nivel?:string,activo?:bool|string,pais?:string}  $filtros
     */
    public function paginar(array $filtros, int $perPage = 20): LengthAwarePaginator
    {
        $q = Fuente::withCount(['cambios as cambios_sin_revisar' => fn ($q) => $q->where('revisado', false)]);

        if (! empty($filtros['busqueda'])) {
            $b = '%'.$filtros['busqueda'].'%';
            $q->where(fn ($s) => $s->where('nombre', 'like', $b)
                ->orWhere('url', 'like', $b)
                ->orWhere('organismo', 'like', $b));
        }

        if (! empty($filtros['nivel'])) {
            $q->where('nivel', $filtros['nivel']);
        }

        if (array_key_exists('activo', $filtros) && $filtros['activo'] !== '' && $filtros['activo'] !== null) {
            $q->where('activo', (bool) $filtros['activo']);
        }

        if (! empty($filtros['pais'])) {
            $q->where('pais', $filtros['pais']);
        }

        return $q->with('paisRelacion')->orderBy('nombre')->paginate($perPage);
    }


    /**
     * Crea una nueva Fuente con los datos validados del formulario.
     * Refresca desde BD para que los defaults de columna (ej: analizar_imagenes=false)
     * estén reflejados en el modelo retornado.
     *
     * @param  array<string,mixed>  $data
     */
    public function crear(array $data): Fuente
    {
        return Fuente::create($data)->refresh();
    }

    /**
     * Actualiza una Fuente existente. Lanza ModelNotFoundException si no existe.
     *
     * @param  array<string,mixed>  $data
     */
    public function actualizar(int $id, array $data): Fuente
    {
        $fuente = Fuente::findOrFail($id);
        $fuente->update($data);

        return $fuente->refresh();
    }

    /**
     * Invierte el flag `activo` de una Fuente. Lanza ModelNotFoundException si no existe.
     */
    public function toggleActivo(int $id): Fuente
    {
        $fuente = Fuente::findOrFail($id);
        $fuente->update(['activo' => ! $fuente->activo]);

        return $fuente->refresh();
    }
}
