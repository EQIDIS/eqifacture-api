<?php

use App\Http\Controllers\Api\V1\CfdiProxyController;
use App\Http\Controllers\Api\V1\SatWsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Stateless Proxy to SAT
|--------------------------------------------------------------------------
|
| This API is a stateless proxy between clients and SAT.
| NO data is stored. FIEL credentials are sent and discarded per request.
|
| Two methods available:
| - /cfdis/*  : Scraping (sync, fast, <500 CFDIs)
| - /ws/*     : WebService (async, slow, up to 200k CFDIs)
|
*/

Route::prefix('v1')->group(function () {
    
    // ============================================================
    // SCRAPING ENDPOINTS (Synchronous, fast, for small queries)
    // ============================================================
    Route::prefix('cfdis')->group(function () {
        // Query CFDIs and return metadata
        Route::post('query', [CfdiProxyController::class, 'query']);
        
        // Download CFDIs and return files directly
        Route::post('download', [CfdiProxyController::class, 'download']);
        
        // Download single CFDI by UUID
        Route::post('download-by-uuid', [CfdiProxyController::class, 'downloadByUuid']);
    });

    // ============================================================
    // WEBSERVICE ENDPOINTS (Asynchronous, for massive downloads)
    // Up to 200,000 CFDIs per request. Can take minutes to 72 hours.
    // ============================================================
    Route::prefix('ws')->group(function () {
        // Step 1: Create download request, returns request_id
        Route::post('solicitar', [SatWsController::class, 'solicitar']);
        
        // Step 2: Check status, returns package_ids when ready
        Route::post('verificar', [SatWsController::class, 'verificar']);
        
        // Step 3: Download packages (ZIP files with XMLs)
        Route::post('descargar', [SatWsController::class, 'descargar']);
    });

    // Health check
    Route::get('health', function () {
        return response()->json([
            'status' => 'ok',
            'service' => 'SAT CFDI Proxy',
            'methods' => ['scraping', 'webservice'],
            'timestamp' => now()->toIso8601String(),
        ]);
    });
});
