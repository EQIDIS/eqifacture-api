<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SatProxyService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

/**
 * Stateless Proxy Controller for SAT CFDI operations (Scraping).
 * 
 * Fast, synchronous downloads using web scraping.
 * Ideal for small queries (<500 CFDIs).
 * 
 * No data is stored. FIEL credentials are sent in each request
 * and discarded after processing.
 */
#[OA\Tag(
    name: 'CFDIs (Scraping)',
    description: 'Synchronous CFDI operations via web scraping. Fast, ideal for <500 CFDIs.'
)]
class CfdiProxyController extends Controller
{
    /**
     * Query CFDIs from SAT (returns metadata only)
     */
    #[OA\Post(
        path: '/api/v1/cfdis/query',
        summary: 'Query CFDIs from SAT (metadata only)',
        description: 'Authenticates with FIEL and queries CFDIs. Returns metadata only, no files downloaded. Fast and synchronous.',
        tags: ['CFDIs (Scraping)'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['certificate', 'private_key', 'passphrase', 'start_date', 'end_date'],
                    properties: [
                        new OA\Property(
                            property: 'certificate',
                            type: 'string',
                            format: 'binary',
                            description: 'FIEL certificate file (.cer) - Must be valid FIEL, not CSD'
                        ),
                        new OA\Property(
                            property: 'private_key',
                            type: 'string',
                            format: 'binary',
                            description: 'FIEL private key file (.key)'
                        ),
                        new OA\Property(
                            property: 'passphrase',
                            type: 'string',
                            description: 'FIEL password',
                            example: 'MySecurePassword123'
                        ),
                        new OA\Property(
                            property: 'start_date',
                            type: 'string',
                            format: 'date',
                            description: 'Start date for query range',
                            example: '2025-01-01'
                        ),
                        new OA\Property(
                            property: 'end_date',
                            type: 'string',
                            format: 'date',
                            description: 'End date for query range',
                            example: '2025-01-31'
                        ),
                        new OA\Property(
                            property: 'download_type',
                            type: 'string',
                            description: 'Type of CFDIs to query',
                            enum: ['emitidos', 'recibidos', 'ambos'],
                            default: 'emitidos',
                            example: 'emitidos'
                        ),
                        new OA\Property(
                            property: 'state_voucher',
                            type: 'string',
                            description: 'Filter by CFDI status',
                            enum: ['todos', 'vigentes', 'cancelados'],
                            default: 'todos',
                            example: 'todos'
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'CFDIs found successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'count', type: 'integer', example: 42),
                            new OA\Property(property: 'cfdis', type: 'array', items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'uuid', type: 'string', example: 'b456fff2-0e87-465b-83a9-0493469cb153'),
                                    new OA\Property(property: 'rfc_emisor', type: 'string', example: 'ABC123456789'),
                                    new OA\Property(property: 'nombre_emisor', type: 'string', example: 'Empresa SA de CV'),
                                    new OA\Property(property: 'rfc_receptor', type: 'string', example: 'XYZ987654321'),
                                    new OA\Property(property: 'total', type: 'string', example: '$4,029.98'),
                                    new OA\Property(property: 'fecha_emision', type: 'string', example: '2025-01-15T11:40:34'),
                                    new OA\Property(property: 'estado_comprobante', type: 'string', example: 'Vigente'),
                                    new OA\Property(property: 'has_xml', type: 'boolean', example: true),
                                    new OA\Property(property: 'has_pdf', type: 'boolean', example: true),
                                ],
                                type: 'object'
                            )),
                        ], type: 'object'),
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
            'download_type' => 'in:emitidos,recibidos,ambos',
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
            'messages' => $service->getMessages(),
        ]);
    }

    /**
     * Download CFDIs from SAT (returns files directly)
     */
    #[OA\Post(
        path: '/api/v1/cfdis/download',
        summary: 'Download CFDIs from SAT (returns Base64 files)',
        description: 'Authenticates with FIEL, queries and downloads CFDIs. Returns XML/PDF content directly as Base64. Fast and synchronous.',
        tags: ['CFDIs (Scraping)'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['certificate', 'private_key', 'passphrase', 'start_date', 'end_date'],
                    properties: [
                        new OA\Property(
                            property: 'certificate',
                            type: 'string',
                            format: 'binary',
                            description: 'FIEL certificate file (.cer)'
                        ),
                        new OA\Property(
                            property: 'private_key',
                            type: 'string',
                            format: 'binary',
                            description: 'FIEL private key file (.key)'
                        ),
                        new OA\Property(
                            property: 'passphrase',
                            type: 'string',
                            description: 'FIEL password'
                        ),
                        new OA\Property(
                            property: 'start_date',
                            type: 'string',
                            format: 'date',
                            description: 'Start date',
                            example: '2025-01-01'
                        ),
                        new OA\Property(
                            property: 'end_date',
                            type: 'string',
                            format: 'date',
                            description: 'End date',
                            example: '2025-01-31'
                        ),
                        new OA\Property(
                            property: 'download_type',
                            type: 'string',
                            description: 'Type of CFDIs to download',
                            enum: ['emitidos', 'recibidos', 'ambos'],
                            default: 'emitidos'
                        ),
                        new OA\Property(
                            property: 'state_voucher',
                            type: 'string',
                            description: 'Filter by status',
                            enum: ['todos', 'vigentes', 'cancelados'],
                            default: 'todos'
                        ),
                        new OA\Property(
                            property: 'resource_types',
                            type: 'string',
                            description: 'Comma-separated resource types to download',
                            enum: ['xml', 'pdf', 'xml,pdf', 'cancel_request', 'cancel_voucher'],
                            default: 'xml',
                            example: 'xml,pdf'
                        ),
                        new OA\Property(
                            property: 'max_results',
                            type: 'integer',
                            description: 'Maximum number of CFDIs to download (1-500)',
                            minimum: 1,
                            maximum: 500,
                            default: 100,
                            example: 100
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Files downloaded successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'count', type: 'integer', example: 15),
                            new OA\Property(property: 'files', type: 'array', items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'uuid', type: 'string', example: 'b456fff2-0e87-465b-83a9-0493469cb153'),
                                    new OA\Property(property: 'type', type: 'string', example: 'xml'),
                                    new OA\Property(property: 'content', type: 'string', description: 'Base64 encoded file content'),
                                    new OA\Property(property: 'size', type: 'integer', example: 15234),
                                    new OA\Property(property: 'metadata', type: 'object'),
                                ],
                                type: 'object'
                            )),
                        ], type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'FIEL authentication failed'),
            new OA\Response(response: 422, description: 'Validation error'),
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
            'download_type' => 'in:emitidos,recibidos,ambos',
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
            'messages' => $service->getMessages(),
        ]);
    }

    /**
     * Download specific CFDIs by UUID
     */
    #[OA\Post(
        path: '/api/v1/cfdis/download-by-uuid',
        summary: 'Download specific CFDIs by UUID',
        description: 'Download specific CFDIs by their UUIDs. Useful when you already know which CFDIs you need.',
        tags: ['CFDIs (Scraping)'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['certificate', 'private_key', 'passphrase', 'uuids', 'download_type'],
                    properties: [
                        new OA\Property(
                            property: 'certificate',
                            type: 'string',
                            format: 'binary',
                            description: 'FIEL certificate file (.cer)'
                        ),
                        new OA\Property(
                            property: 'private_key',
                            type: 'string',
                            format: 'binary',
                            description: 'FIEL private key file (.key)'
                        ),
                        new OA\Property(
                            property: 'passphrase',
                            type: 'string',
                            description: 'FIEL password'
                        ),
                        new OA\Property(
                            property: 'uuids',
                            type: 'string',
                            description: 'Comma-separated list of UUIDs to download',
                            example: 'b456fff2-0e87-465b-83a9-0493469cb153,a123bcd4-5678-90ef-ghij-klmnopqrstuv'
                        ),
                        new OA\Property(
                            property: 'download_type',
                            type: 'string',
                            description: 'Specify if UUIDs are from emitidos or recibidos. Note: "ambos" is NOT supported for UUID downloads.',
                            enum: ['emitidos', 'recibidos'],
                            example: 'emitidos'
                        ),
                        new OA\Property(
                            property: 'resource_types',
                            type: 'string',
                            description: 'Comma-separated resource types',
                            enum: ['xml', 'pdf', 'xml,pdf', 'cancel_request', 'cancel_voucher'],
                            default: 'xml',
                            example: 'xml,pdf'
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Files downloaded successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'count', type: 'integer'),
                            new OA\Property(property: 'files', type: 'array', items: new OA\Items(type: 'object')),
                        ], type: 'object'),
                    ]
                )
            ),
            new OA\Response(response: 401, description: 'FIEL authentication failed'),
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
            'messages' => $service->getMessages(),
        ]);
    }
}
