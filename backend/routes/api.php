<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CisConsultaController;
use App\Http\Controllers\Api\ClientDraftController;
Route::post('auth/login', [AuthController::class,'login'])->middleware('throttle:5,1');
Route::middleware('auth:sanctum')->group(function(){
    Route::get('auth/me',[AuthController::class,'me']);
    Route::post('auth/logout',[AuthController::class,'logout']);
    Route::get('cis/client-drafts',[ClientDraftController::class,'index']);
    Route::post('cis/client-drafts',[ClientDraftController::class,'store'])->middleware('throttle:20,1');
    Route::prefix('cis')->middleware(\App\Http\Middleware\RequireTestDataApproval::class)->group(function(){
        Route::get('clientes',[CisConsultaController::class,'clientes']);
        Route::get('contractos',[CisConsultaController::class,'contractos']);
        Route::get('extracto',[CisConsultaController::class,'extracto']);
        Route::get('contractos/{numero}/prestacoes',[CisConsultaController::class,'prestacoes']);
    });
});
