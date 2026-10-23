<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

/** Transaction-only extension of the populated-target review harness. */
abstract class PepDirectoryLivewireTestCase extends PepDirectoryReviewTestCase
{
    private array $counts = [];

    public function createApplication(): Application
    {
        self::requireOptIn();
        foreach ([\Livewire\LivewireServiceProvider::class, \Illuminate\View\ViewServiceProvider::class,
            \Illuminate\Auth\AuthServiceProvider::class, \Illuminate\Hashing\HashServiceProvider::class,
            \Illuminate\Session\SessionServiceProvider::class, \Illuminate\Cookie\CookieServiceProvider::class,
            \Illuminate\Encryption\EncryptionServiceProvider::class] as $provider) {
            if (! class_exists($provider)) {
                throw new RuntimeException('Required harness dependency unavailable: '.$provider);
            }
        }
        $app = parent::createApplication(); // Existing config-only guard and actual target identity check.
        $app->detectEnvironment(fn (): string => 'testing');
        $app['config']->set([
            'app.key' => 'base64:'.base64_encode(str_repeat('h', 32)),
            'app.cipher' => 'AES-256-CBC',
            'app.url' => 'http://localhost',
            'app.timezone' => 'UTC',
            'hashing.driver' => 'bcrypt',
            'session' => ['driver' => 'array', 'cookie' => 'pep_harness', 'lifetime' => 120,
                'path' => '/', 'domain' => null, 'secure' => false, 'http_only' => true, 'same_site' => 'lax',
                'lottery' => [0, 100], 'expire_on_close' => false],
            'view' => ['paths' => ['/work/resources/views'], 'compiled' => '/tmp/pep-review/views'],
            'livewire.component_locations' => ['/work/resources/views/components', '/work/resources/views/livewire'],
        ]);
        $app['files']->ensureDirectoryExists('/tmp/pep-review/views');
        $app->instance('request', Request::create('http://localhost'));
        $app->singleton(\Illuminate\Contracts\Http\Kernel::class, PepDirectoryLivewireKernel::class);
        $app->singleton(\Illuminate\Contracts\Debug\ExceptionHandler::class, \Illuminate\Foundation\Exceptions\Handler::class);
        // Fail closed if a future edit reintroduces ordinary kernel bootstrappers.
        foreach ([\Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables::class,
            \Illuminate\Foundation\Bootstrap\LoadConfiguration::class,
            \Illuminate\Foundation\Bootstrap\RegisterProviders::class,
            \Illuminate\Foundation\Bootstrap\BootProviders::class] as $bootstrapper) {
            $app->beforeBootstrapping($bootstrapper, static function (): never {
                throw new RuntimeException('Normal application bootstrap is forbidden in this harness.');
            });
        }
        foreach ([\Illuminate\Auth\AuthServiceProvider::class, \Illuminate\Hashing\HashServiceProvider::class,
            \Illuminate\Session\SessionServiceProvider::class, \Illuminate\Cookie\CookieServiceProvider::class,
            \Illuminate\Encryption\EncryptionServiceProvider::class,
            \Illuminate\View\ViewServiceProvider::class, \Livewire\LivewireServiceProvider::class] as $provider) {
            $app->register($provider);
        }
        $app->make(\Illuminate\Contracts\Http\Kernel::class); // Install real aliases before routes execute.

        return $app;
    }

    protected function setUp(): void
    {
        self::requireOptIn(); // Also protects failure cleanup from creating an Application without opt-in.
        try {
            parent::setUp();
            foreach (self::TABLES as $table) {
                $this->counts[$table] = $this->db->table($table)->count();
            }
        } catch (Throwable $failure) {
            // PHPUnit does not call tearDown when setUp fails after scoped DDL starts.
            $this->tearDown();
            throw $failure;
        }
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->db)) {
                while ($this->db->transactionLevel() > 0) {
                    $this->db->rollBack();
                }
                foreach ($this->counts as $table => $count) {
                    self::assertSame($count, $this->db->table($table)->count(), 'Row count changed: '.$table);
                }
            }
        } finally {
            parent::tearDown(); // Exact original rows, columns, inventory; HTTP/queue assertions.
        }
    }

    protected function syntheticUser(bool $active = true, bool $allowed = true): User
    {
        $id = $this->db->table('users')->insertGetId([
            'label' => 'livewire-harness', 'name' => 'Synthetic Harness Actor', 'activo' => $active,
        ]);
        $user = User::query()->findOrFail($id);
        if ($allowed) {
            $permission = \Spatie\Permission\Models\Permission::findOrCreate('ver cambios pep', 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    /** SessionGuard must rehydrate the persisted identity on the next real HTTP request. */
    protected function authenticateSession(User $user): void
    {
        $key = $this->app['auth']->guard('web')->getName();
        $this->withSession([$key => $user->getKey()]);
        $this->app['auth']->forgetGuards();
    }
}
