<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CisConsultaController;
Route::post('auth/login', [AuthController::class,'login'])->middleware('throttle:5,1');
Route::middleware('auth:sanctum')->group(function(){
    Route::get('auth/me',[AuthController::class,'me']);
    Route::post('auth/logout',[AuthController::class,'logout']);
    Route::prefix('cis')->middleware('financial-test')->group(function(){
        Route::get('clientes',[CisConsultaController::class,'clientes']);
        Route::get('contractos',[CisConsultaController::class,'contractos']);
        Route::get('contractos/{numero}/prestacoes',[CisConsultaController::class,'prestacoes']);
    });
});
