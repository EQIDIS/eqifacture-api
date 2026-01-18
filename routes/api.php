<?php

use App\Http\Controllers\Api\V1\CfdiProxyController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Stateless Proxy to SAT
|--------------------------------------------------------------------------
|
| This API is a stateless proxy between clients and SAT.
| NO data is stored. Everything is synchronous.
|
*/

Route::prefix('v1')->group(function () {
    
    // Stateless CFDI operations - no auth required, FIEL is sent in each request
    Route::prefix('cfdis')->group(function () {
        // Query CFDIs and return metadata
        Route::post('query', [CfdiProxyController::class, 'query']);
        
        // Download CFDIs and return files directly
        Route::post('download', [CfdiProxyController::class, 'download']);
        
        // Download single CFDI by UUID
        Route::post('download-by-uuid', [CfdiProxyController::class, 'downloadByUuid']);
    });

    // Health check
    Route::get('health', function () {
        return response()->json([
            'status' => 'ok',
            'service' => 'SAT CFDI Proxy',
            'timestamp' => now()->toIso8601String(),
        ]);
    });
});
