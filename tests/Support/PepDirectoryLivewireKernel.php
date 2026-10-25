<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Http\Kernel;

/** Only the already-configured review Application; never load environment/config/providers. */
final class PepDirectoryLivewireKernel extends Kernel
{
    protected $bootstrappers = [];

    protected $middlewareGroups = [
        'web' => [
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
        ],
    ];

    protected $middlewareAliases = [
        'auth' => \Illuminate\Auth\Middleware\Authenticate::class,
        'activo' => \App\Http\Middleware\UsuarioActivo::class,
        'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
    ];
}
