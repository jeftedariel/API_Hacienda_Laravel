<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\CredentialController;
use App\Http\Controllers\Api\V1\DocumentController;
use App\Http\Controllers\Api\V1\GeoController;
use App\Http\Controllers\Api\V1\ProductController;
use App\Http\Controllers\Api\V1\ReceiverController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API REST v1
|--------------------------------------------------------------------------
| API moderna con autenticación por tokens Sanctum (empresa/usuario).
| Convive con la capa de compatibilidad legacy (/api.php, routes/legacy.php).
*/

Route::prefix('v1')->group(function () {
    // Autenticación (login con rate limiting para frenar fuerza bruta).
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('auth/register', [AuthController::class, 'register']);
        Route::post('auth/login', [AuthController::class, 'login']);
        Route::post('auth/company/login', [AuthController::class, 'companyLogin']);
    });

    // Catálogos públicos (solo lectura).
    Route::get('geo/provinces', [GeoController::class, 'provinces']);
    Route::get('geo/provinces/{province}/cantons', [GeoController::class, 'cantons']);
    Route::get('geo/provinces/{province}/cantons/{canton}/districts', [GeoController::class, 'districts']);
    Route::get('geo/provinces/{province}/cantons/{canton}/districts/{district}/neighborhoods', [GeoController::class, 'neighborhoods']);
    Route::get('catalogs/tax-types', [GeoController::class, 'taxTypes']);
    Route::get('catalogs/measure-units', [GeoController::class, 'measureUnits']);
    Route::get('catalogs/id-types', [GeoController::class, 'idTypes']);

    // Rutas autenticadas (token Sanctum de empresa o usuario master).
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);

        // Empresa.
        Route::get('company', [CompanyController::class, 'show']);
        Route::put('company', [CompanyController::class, 'update']);

        // Credenciales ATV.
        Route::get('company/credentials', [CredentialController::class, 'index']);
        Route::put('company/credentials', [CredentialController::class, 'upsert']);
        Route::post('company/certificate', [CredentialController::class, 'uploadCertificate']);

        // Sucursales y terminales.
        Route::get('branches', [BranchController::class, 'index']);
        Route::post('branches', [BranchController::class, 'store']);
        Route::get('terminals', [BranchController::class, 'terminals']);
        Route::post('terminals', [BranchController::class, 'storeTerminal']);

        // Receptores.
        Route::apiResource('receivers', ReceiverController::class)->except('update');
        Route::put('receivers/{receiver}', [ReceiverController::class, 'update']);

        // Inventario.
        Route::apiResource('products', ProductController::class)->only(['index', 'store', 'update', 'destroy']);

        // Comprobantes: emisión y seguimiento (recurso principal).
        Route::get('documents', [DocumentController::class, 'index']);
        Route::get('documents/{document}', [DocumentController::class, 'show']);
        Route::get('documents/{document}/status', [DocumentController::class, 'status']);

        // Consulta de estado por clave: sirve para comprobantes que esta
        // instalación no emitió (los que RECIBE la empresa de terceros).
        Route::middleware('throttle:60,1')
            ->get('hacienda/status', [DocumentController::class, 'statusByClave']);
        Route::middleware('throttle:60,1')->post('documents', [DocumentController::class, 'store']);
    });
});
