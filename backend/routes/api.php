<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CisConsultaController;
Route::middleware('auth:sanctum')->prefix('cis')->group(function () {
    Route::get('clientes', [CisConsultaController::class,'clientes']);
    Route::get('contractos', [CisConsultaController::class,'contractos']);
    Route::get('contractos/{numero}/prestacoes', [CisConsultaController::class,'prestacoes']);
});
