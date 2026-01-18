<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SatProxyService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

/**
 * Stateless Proxy Controller for SAT CFDI operations.
 * 
 * No data is stored. FIEL credentials are sent in each request
 * and discarded after processing.
 */
#[OA\Tag(name: 'CFDIs', description: 'Stateless CFDI proxy operations')]
class CfdiProxyController extends Controller
{
    /**
     * Query CFDIs from SAT (returns metadata only)
     */
    #[OA\Post(
        path: '/api/v1/cfdis/query',
        summary: 'Query CFDIs from SAT',
        description: 'Authenticates with FIEL and queries CFDIs. Returns metadata only, no files.',
        tags: ['CFDIs'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['certificate', 'private_key', 'passphrase', 'start_date', 'end_date'],
                    properties: [
                        new OA\Property(property: 'certificate', type: 'string', format: 'binary', description: 'FIEL certificate file (.cer)'),
                        new OA\Property(property: 'private_key', type: 'string', format: 'binary', description: 'FIEL private key file (.key)'),
                        new OA\Property(property: 'passphrase', type: 'string', description: 'FIEL passphrase'),
                        new OA\Property(property: 'start_date', type: 'string', format: 'date', example: '2024-01-01'),
                        new OA\Property(property: 'end_date', type: 'string', format: 'date', example: '2024-01-31'),
                        new OA\Property(property: 'download_type', type: 'string', enum: ['emitidos', 'recibidos'], default: 'emitidos'),
                        new OA\Property(property: 'state_voucher', type: 'string', enum: ['todos', 'vigentes', 'cancelados'], default: 'todos'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'CFDIs found',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'data', type: 'object', properties: [
                            new OA\Property(property: 'count', type: 'integer'),
                            new OA\Property(property: 'cfdis', type: 'array', items: new OA\Items(type: 'object')),
                        ]),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'FIEL authentication failed'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function query(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'certificate' => 'required|file',
            'private_key' => 'required|file',
            'passphrase' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'download_type' => 'in:emitidos,recibidos',
            'state_voucher' => 'in:todos,vigentes,cancelados',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $service = new SatProxyService();
        
        // Authenticate with FIEL (credentials are NOT stored)
        $authenticated = $service->authenticateFromRequest(
            $request->file('certificate'),
            $request->file('private_key'),
            $request->passphrase
        );

        if (!$authenticated) {
            return response()->json([
                'success' => false,
                'message' => 'FIEL authentication failed',
                'errors' => $service->getErrors(),
            ], 401);
        }

        // Query CFDIs
        $cfdis = $service->queryByDateRange(
            $request->start_date,
            $request->end_date,
            $request->input('download_type', 'emitidos'),
            $request->input('state_voucher', 'todos')
        );

        if ($cfdis === null) {
            return response()->json([
                'success' => false,
                'message' => 'Query failed',
                'errors' => $service->getErrors(),
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'count' => count($cfdis),
                'cfdis' => $cfdis,
            ],
        ]);
    }

    /**
     * Download CFDIs from SAT (returns files directly)
     */
    #[OA\Post(
        path: '/api/v1/cfdis/download',
        summary: 'Download CFDIs from SAT',
        description: 'Authenticates with FIEL, queries and downloads CFDIs. Returns XML/PDF content directly.',
        tags: ['CFDIs'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['certificate', 'private_key', 'passphrase', 'start_date', 'end_date'],
                    properties: [
                        new OA\Property(property: 'certificate', type: 'string', format: 'binary'),
                        new OA\Property(property: 'private_key', type: 'string', format: 'binary'),
                        new OA\Property(property: 'passphrase', type: 'string'),
                        new OA\Property(property: 'start_date', type: 'string', format: 'date'),
                        new OA\Property(property: 'end_date', type: 'string', format: 'date'),
                        new OA\Property(property: 'download_type', type: 'string', enum: ['emitidos', 'recibidos']),
                        new OA\Property(property: 'state_voucher', type: 'string', enum: ['todos', 'vigentes', 'cancelados']),
                        new OA\Property(property: 'resource_types', type: 'string', description: 'Comma-separated: xml,pdf', example: 'xml'),
                        new OA\Property(property: 'max_results', type: 'integer', description: 'Limit results', default: 100),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Files downloaded',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean'),
                        new OA\Property(property: 'data', type: 'object', properties: [
                            new OA\Property(property: 'count', type: 'integer'),
                            new OA\Property(property: 'files', type: 'array', items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'uuid', type: 'string'),
                                    new OA\Property(property: 'type', type: 'string'),
                                    new OA\Property(property: 'content', type: 'string', description: 'Base64 encoded'),
                                    new OA\Property(property: 'metadata', type: 'object'),
                                ]
                            )),
                        ]),
                    ]
                )
            ),
        ]
    )]
    public function download(Request $request): JsonResponse
    {
        // Increase limits for heavy downloads
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        $validator = Validator::make($request->all(), [
            'certificate' => 'required|file',
            'private_key' => 'required|file',
            'passphrase' => 'required|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'download_type' => 'in:emitidos,recibidos',
            'state_voucher' => 'in:todos,vigentes,cancelados',
            'resource_types' => 'string',
            'max_results' => 'integer|min:1|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $service = new SatProxyService();
        
        $authenticated = $service->authenticateFromRequest(
            $request->file('certificate'),
            $request->file('private_key'),
            $request->passphrase
        );

        if (!$authenticated) {
            return response()->json([
                'success' => false,
                'message' => 'FIEL authentication failed',
                'errors' => $service->getErrors(),
            ], 401);
        }

        // Parse resource types
        $resourceTypes = explode(',', $request->input('resource_types', 'xml'));
        $resourceTypes = array_map('trim', $resourceTypes);
        $maxResults = $request->input('max_results', 100);

        // Download and return files
        $files = $service->downloadAndReturn(
            $request->start_date,
            $request->end_date,
            $request->input('download_type', 'emitidos'),
            $request->input('state_voucher', 'todos'),
            $resourceTypes,
            $maxResults
        );

        if ($files === null) {
            return response()->json([
                'success' => false,
                'message' => 'Download failed',
                'errors' => $service->getErrors(),
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'count' => count($files),
                'files' => $files,
            ],
        ]);
    }

    /**
     * Download specific CFDIs by UUID
     */
    #[OA\Post(
        path: '/api/v1/cfdis/download-by-uuid',
        summary: 'Download specific CFDIs by UUID',
        description: 'Download specific CFDIs by their UUIDs',
        tags: ['CFDIs'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['certificate', 'private_key', 'passphrase', 'uuids', 'download_type'],
                    properties: [
                        new OA\Property(property: 'certificate', type: 'string', format: 'binary'),
                        new OA\Property(property: 'private_key', type: 'string', format: 'binary'),
                        new OA\Property(property: 'passphrase', type: 'string'),
                        new OA\Property(property: 'uuids', type: 'string', description: 'Comma-separated UUIDs'),
                        new OA\Property(property: 'download_type', type: 'string', enum: ['emitidos', 'recibidos']),
                        new OA\Property(property: 'resource_types', type: 'string', example: 'xml,pdf'),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Files downloaded'),
        ]
    )]
    public function downloadByUuid(Request $request): JsonResponse
    {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        $validator = Validator::make($request->all(), [
            'certificate' => 'required|file',
            'private_key' => 'required|file',
            'passphrase' => 'required|string',
            'uuids' => 'required|string',
            'download_type' => 'required|in:emitidos,recibidos',
            'resource_types' => 'string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $service = new SatProxyService();
        
        $authenticated = $service->authenticateFromRequest(
            $request->file('certificate'),
            $request->file('private_key'),
            $request->passphrase
        );

        if (!$authenticated) {
            return response()->json([
                'success' => false,
                'message' => 'FIEL authentication failed',
                'errors' => $service->getErrors(),
            ], 401);
        }

        $uuids = array_map('trim', explode(',', $request->uuids));
        $resourceTypes = explode(',', $request->input('resource_types', 'xml'));
        $resourceTypes = array_map('trim', $resourceTypes);

        $files = $service->downloadByUuids(
            $uuids,
            $request->download_type,
            $resourceTypes
        );

        if ($files === null) {
            return response()->json([
                'success' => false,
                'message' => 'Download failed',
                'errors' => $service->getErrors(),
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'count' => count($files),
                'files' => $files,
            ],
        ]);
    }
}
