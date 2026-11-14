<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CisConsultaController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\ConnectionStatusController;
use App\Http\Controllers\Api\LegacyClienteEditController;
use App\Http\Controllers\Api\ClientSecurityInfoController;
use App\Http\Controllers\Api\ContractProfileController;
use App\Http\Controllers\Api\ContractListController;
Route::post('auth/login', [AuthController::class,'login'])->middleware('throttle:5,1');
Route::middleware('auth:sanctum')->group(function(){
    Route::get('auth/me',[AuthController::class,'me']);
    Route::get('admin/connection-status', ConnectionStatusController::class);
    Route::post('auth/logout',[AuthController::class,'logout']);
    Route::get('cis/clients',[ClientController::class,'index']);
    Route::post('cis/clients',[ClientController::class,'store'])->middleware('throttle:20,1');
    Route::put('cis/clients/{id}',[ClientController::class,'update'])->whereNumber('id')->middleware('throttle:20,1');
    Route::prefix('cis')->middleware(\App\Http\Middleware\AllowPrivateClientPreview::class)->group(function(){
        Route::get('clientes',[CisConsultaController::class,'clientes'])->middleware('throttle:30,1');
        Route::get('clientes/{id}',[CisConsultaController::class,'cliente'])->whereNumber('id')->middleware('throttle:30,1');
        Route::get('clientes/{id}/editar',[LegacyClienteEditController::class,'show'])->whereNumber('id')->middleware('throttle:30,1');
        Route::patch('clientes/{id}',[LegacyClienteEditController::class,'update'])->whereNumber('id')->middleware('throttle:10,1');
        Route::get('clientes/{id}/complementares',[ClientSecurityInfoController::class,'show'])->whereNumber('id')->middleware('throttle:30,1');
        Route::put('clientes/{id}/complementares',[ClientSecurityInfoController::class,'update'])->whereNumber('id')->middleware('throttle:10,1');
    });
    Route::prefix('cis')->middleware(\App\Http\Middleware\AllowPrivateContractsPreview::class)->group(function(){
        Route::get('contractos',[ContractListController::class,'index'])->middleware('throttle:30,1');
        Route::get('contractos/estados',[ContractListController::class,'states'])->middleware('throttle:30,1');
        Route::get('contractos/{numero}/ficha',[ContractProfileController::class,'show'])->middleware('throttle:20,1');
    });
    Route::prefix('cis')->middleware(\App\Http\Middleware\RequireTestDataApproval::class)->group(function(){
        Route::get('extracto',[CisConsultaController::class,'extracto']);
        Route::get('contractos/{numero}/prestacoes',[CisConsultaController::class,'prestacoes']);
    });
});
