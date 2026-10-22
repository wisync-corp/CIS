<?php
namespace App\Providers;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
class AppServiceProvider extends ServiceProvider {
    public function register(): void {
        config(['database.connections.mysql_legacy' => require base_path('config/legacy-connection.example.php')]);
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
