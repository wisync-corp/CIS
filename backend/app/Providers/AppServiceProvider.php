<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
class AppServiceProvider extends ServiceProvider {
    public function register(): void {
        config(['database.connections.mysql_legacy' => require base_path('config/legacy-connection.example.php')]);
        config(['database.connections.mysql_editor' => array_merge(config('database.connections.mysql'), [
            'host' => env('CLIENT_EDITOR_DB_HOST', 'mysql'),
            'database' => env('CLIENT_EDITOR_DB_DATABASE', ''),
            'username' => env('CLIENT_EDITOR_DB_USERNAME', ''),
            'password' => env('CLIENT_EDITOR_DB_PASSWORD', ''),
        ])]);
    }
    public function boot(): void {
        Gate::define('cis.consultar', function ($user) {
            return in_array($user->role, ['administrador','gestor','agente','cobrador'], true) && $user->active;
        });
        Gate::define('cis.consultar.todos', function ($user) {
            return in_array($user->role, ['administrador','gestor'], true) && $user->active;
        });
    }
}
