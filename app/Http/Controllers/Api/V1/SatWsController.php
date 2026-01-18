<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SatWsService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

/**
 * SAT WebService Controller for Massive Downloads
 * 
 * Stateless proxy for SAT's official WebService (Descarga Masiva).
 * Use this for large-scale downloads (up to 200k CFDIs per request).
 * The process is asynchronous and can take minutes to 72 hours.
 * 
 * Flow:
 * 1. POST /ws/solicitar - Create download request, get request_id (or two if 'ambos')
 * 2. POST /ws/verificar - Poll status until finished, get package_ids
 * 3. POST /ws/descargar - Download packages (ZIP files with XMLs)
 */
#[OA\Tag(
    name: 'WebService (Descarga Masiva)',
    description: 'SAT official WebService for massive asynchronous CFDI downloads. Supports up to 200k CFDIs per request. Process can take minutes to 72 hours.'
)]
class SatWsController extends Controller
{
    /**
     * Create download request (solicitar)
     */
    #[OA\Post(
        path: '/api/v1/ws/solicitar',
        summary: 'Step 1: Create massive download request',
        description: 'Initiates a download request with SAT WebService. Returns request_id for verification. If download_type is "ambos", returns two request_ids (one for emitidos, one for recibidos). Process can take minutes to 72 hours.',
        tags: ['WebService (Descarga Masiva)'],
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
                            format: 'date-time',
                            description: 'Start date and time. Format: Y-m-d H:i:s. Must be at least 2 seconds before end_date.',
                            example: '2025-01-01 00:00:00'
                        ),
                        new OA\Property(
                            property: 'end_date',
                            type: 'string',
                            format: 'date-time',
                            description: 'End date and time. Format: Y-m-d H:i:s',
                            example: '2025-01-31 23:59:59'
                        ),
                        new OA\Property(
                            property: 'download_type',
                            type: 'string',
                            description: 'Type of CFDIs to download. "ambos" creates two separate requests.',
                            enum: ['emitidos', 'recibidos', 'ambos'],
                            default: 'recibidos',
                            example: 'emitidos'
                        ),
                        new OA\Property(
                            property: 'request_type',
                            type: 'string',
                            description: 'Request type: "cfdi" for actual XML files, "metadata" for metadata only (faster)',
                            enum: ['cfdi', 'metadata'],
                            default: 'cfdi',
                            example: 'cfdi'
                        ),
                        new OA\Property(
                            property: 'service_type',
                            type: 'string',
                            description: 'Service type: regular CFDI or CFDI de Retenciones',
                            enum: ['cfdi', 'retenciones'],
                            default: 'cfdi',
                            example: 'cfdi'
                        ),
                        new OA\Property(
                            property: 'document_status',
                            type: 'string',
                            description: 'Filter by document status. Note: SAT requires "vigentes" for recibidos+cfdi downloads.',
                            enum: ['vigentes', 'cancelados'],
                            nullable: true,
                            example: 'vigentes'
                        ),
                        new OA\Property(
                            property: 'document_type',
                            type: 'string',
                            description: 'Filter by document type',
                            enum: ['ingreso', 'egreso', 'traslado', 'nomina', 'pago'],
                            nullable: true,
                            example: 'ingreso'
                        ),
                        new OA\Property(
                            property: 'complemento',
                            type: 'string',
                            description: 'Filter by complemento. Available options: pagos10, pagos20, aerolineas10, cartaporte10, cartaporte20, cartaporte30, cartaporte31, comercioexterior10, comercioexterior11, comercioexterior20, donat10, donat11, gastoshidrocarburos10, ine11, ingresoshidrocarburos10, leyendasfisc10, nomina11, nomina12, notariospublicos10, obraimp10, pfic10, renovyvehsam10, servicioparcialdeconstruccion10, turista10, venta10',
                            nullable: true,
                            example: 'pagos20'
                        ),
                        new OA\Property(
                            property: 'rfc_match',
                            type: 'string',
                            description: 'Filter by RFC counterpart (12-13 chars). For emitidos: filters by receptor. For recibidos: filters by emisor.',
                            nullable: true,
                            example: 'XAXX010101000'
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Request accepted',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'request_id', type: 'string', description: 'Request ID for single type downloads', example: '447a3fd4-8409-4ceb-9bdd-82e51bef37bb'),
                            new OA\Property(property: 'request_ids', type: 'object', description: 'Request IDs when download_type=ambos', properties: [
                                new OA\Property(property: 'emitidos', type: 'string'),
                                new OA\Property(property: 'recibidos', type: 'string'),
                            ]),
                            new OA\Property(property: 'status', type: 'string', example: 'accepted'),
                            new OA\Property(property: 'message', type: 'string', example: 'Solicitud Aceptada'),
                        ], type: 'object'),
                        new OA\Property(property: 'messages', type: 'array', items: new OA\Items(type: 'string')),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Validation error or SAT rejected request'),
            new OA\Response(response: 401, description: 'FIEL authentication failed'),
        ]
    )]
    public function solicitar(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'certificate' => 'required|file',
            'private_key' => 'required|file',
            'passphrase' => 'required|string',
            'start_date' => 'required|date_format:Y-m-d H:i:s',
            'end_date' => 'required|date_format:Y-m-d H:i:s',
            'download_type' => 'sometimes|string|in:emitidos,recibidos,ambos',
            'request_type' => 'sometimes|string|in:cfdi,metadata',
            'service_type' => 'sometimes|string|in:cfdi,retenciones',
            'document_status' => 'sometimes|nullable|string|in:vigentes,cancelados,active,cancelled',
            'document_type' => 'sometimes|nullable|string|in:ingreso,egreso,traslado,nomina,pago',
            'complemento' => 'sometimes|nullable|string',
            'rfc_match' => 'sometimes|nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()->toArray(),
            ], 400);
        }

        $service = new SatWsService();
        $serviceType = $request->input('service_type', 'cfdi');

        // Authenticate
        if (!$service->createSession(
            $request->file('certificate'),
            $request->file('private_key'),
            $request->input('passphrase'),
            $serviceType
        )) {
            return response()->json([
                'success' => false,
                'errors' => $service->getErrors(),
            ], 401);
        }

        $downloadType = $request->input('download_type', 'recibidos');

        // Handle 'ambos' by making two requests
        if ($downloadType === 'ambos') {
            $resultEmitidos = $service->solicitar(
                $request->input('start_date'),
                $request->input('end_date'),
                'emitidos',
                $request->input('request_type', 'cfdi'),
                $request->input('document_status'),
                $request->input('document_type'),
                $request->input('complemento'),
                $request->input('rfc_match')
            );

            // Create new session for second request
            $service = new SatWsService();
            $service->createSession(
                $request->file('certificate'),
                $request->file('private_key'),
                $request->input('passphrase'),
                $serviceType
            );

            $resultRecibidos = $service->solicitar(
                $request->input('start_date'),
                $request->input('end_date'),
                'recibidos',
                $request->input('request_type', 'cfdi'),
                $request->input('document_status'),
                $request->input('document_type'),
                $request->input('complemento'),
                $request->input('rfc_match')
            );

            if ($resultEmitidos === null && $resultRecibidos === null) {
                return response()->json([
                    'success' => false,
                    'errors' => $service->getErrors(),
                    'messages' => $service->getMessages(),
                ], 400);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'request_ids' => [
                        'emitidos' => $resultEmitidos['request_id'] ?? null,
                        'recibidos' => $resultRecibidos['request_id'] ?? null,
                    ],
                    'status' => 'accepted',
                    'message' => 'Both requests submitted (emitidos + recibidos)',
                ],
                'messages' => $service->getMessages(),
            ]);
        }

        // Single request
        $result = $service->solicitar(
            $request->input('start_date'),
            $request->input('end_date'),
            $downloadType,
            $request->input('request_type', 'cfdi'),
            $request->input('document_status'),
            $request->input('document_type'),
            $request->input('complemento'),
            $request->input('rfc_match')
        );

        if ($result === null) {
            return response()->json([
                'success' => false,
                'errors' => $service->getErrors(),
                'messages' => $service->getMessages(),
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
            'messages' => $service->getMessages(),
        ]);
    }

    /**
     * Verify request status (verificar)
     */
    #[OA\Post(
        path: '/api/v1/ws/verificar',
        summary: 'Step 2: Check download request status',
        description: 'Polls SAT to check if the download request is ready. When status is "finished", package_ids will be available for download. You should poll this endpoint periodically (every 1-5 minutes recommended).',
        tags: ['WebService (Descarga Masiva)'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['certificate', 'private_key', 'passphrase', 'request_id'],
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
                            property: 'request_id',
                            type: 'string',
                            description: 'Request ID obtained from solicitar endpoint',
                            example: '447a3fd4-8409-4ceb-9bdd-82e51bef37bb'
                        ),
                        new OA\Property(
                            property: 'service_type',
                            type: 'string',
                            description: 'Must match the service_type used in solicitar',
                            enum: ['cfdi', 'retenciones'],
                            default: 'cfdi',
                            example: 'cfdi'
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Status retrieved successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(
                                property: 'status',
                                type: 'string',
                                description: 'Current request status. "finished" means packages are ready.',
                                enum: ['accepted', 'in_progress', 'finished', 'failure', 'rejected', 'expired'],
                                example: 'finished'
                            ),
                            new OA\Property(property: 'message', type: 'string', example: 'Solicitud Aceptada'),
                            new OA\Property(
                                property: 'package_ids',
                                type: 'array',
                                description: 'Package IDs for download (only when status=finished)',
                                items: new OA\Items(type: 'string'),
                                example: ['ABC123_01', 'ABC123_02']
                            ),
                            new OA\Property(property: 'count', type: 'integer', description: 'Number of packages', example: 2),
                            new OA\Property(property: 'cfdi_count', type: 'integer', description: 'Total CFDIs found', example: 150),
                        ], type: 'object'),
                        new OA\Property(property: 'messages', type: 'array', items: new OA\Items(type: 'string')),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Verification failed'),
            new OA\Response(response: 401, description: 'FIEL authentication failed'),
        ]
    )]
    public function verificar(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'certificate' => 'required|file',
            'private_key' => 'required|file',
            'passphrase' => 'required|string',
            'request_id' => 'required|string',
            'service_type' => 'sometimes|string|in:cfdi,retenciones',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()->toArray(),
            ], 400);
        }

        $service = new SatWsService();

        if (!$service->createSession(
            $request->file('certificate'),
            $request->file('private_key'),
            $request->input('passphrase'),
            $request->input('service_type', 'cfdi')
        )) {
            return response()->json([
                'success' => false,
                'errors' => $service->getErrors(),
            ], 401);
        }

        $result = $service->verificar($request->input('request_id'));

        if ($result === null) {
            return response()->json([
                'success' => false,
                'errors' => $service->getErrors(),
                'messages' => $service->getMessages(),
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
            'messages' => $service->getMessages(),
        ]);
    }

    /**
     * Download packages (descargar)
     */
    #[OA\Post(
        path: '/api/v1/ws/descargar',
        summary: 'Step 3: Download ready packages',
        description: 'Downloads the ZIP packages containing XMLs. Each package is returned as Base64-encoded content. You must decode the Base64 content to get the ZIP file.',
        tags: ['WebService (Descarga Masiva)'],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\MediaType(
                mediaType: 'multipart/form-data',
                schema: new OA\Schema(
                    required: ['certificate', 'private_key', 'passphrase', 'package_ids'],
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
                            property: 'package_ids',
                            type: 'string',
                            description: 'Comma-separated package IDs obtained from verificar endpoint',
                            example: '447A3FD4-8409-4CEB-9BDD-82E51BEF37BB_01,447A3FD4-8409-4CEB-9BDD-82E51BEF37BB_02'
                        ),
                        new OA\Property(
                            property: 'service_type',
                            type: 'string',
                            description: 'Must match the service_type used in solicitar',
                            enum: ['cfdi', 'retenciones'],
                            default: 'cfdi',
                            example: 'cfdi'
                        ),
                    ]
                )
            )
        ),
        responses: [
            new OA\Response(
                response: 200,
                description: 'Packages downloaded successfully',
                content: new OA\JsonContent(
                    properties: [
                        new OA\Property(property: 'success', type: 'boolean', example: true),
                        new OA\Property(property: 'data', properties: [
                            new OA\Property(property: 'count', type: 'integer', example: 2),
                            new OA\Property(property: 'packages', type: 'array', items: new OA\Items(
                                properties: [
                                    new OA\Property(property: 'package_id', type: 'string', example: '447A3FD4-8409-4CEB-9BDD-82E51BEF37BB_01'),
                                    new OA\Property(property: 'content_base64', type: 'string', description: 'Base64-encoded ZIP file. Decode this to get the ZIP containing XML files.'),
                                    new OA\Property(property: 'size', type: 'integer', description: 'Size in bytes', example: 16111),
                                    new OA\Property(property: 'format', type: 'string', example: 'zip'),
                                ],
                                type: 'object'
                            )),
                        ], type: 'object'),
                        new OA\Property(property: 'messages', type: 'array', items: new OA\Items(type: 'string')),
                    ]
                )
            ),
            new OA\Response(response: 400, description: 'Download failed'),
            new OA\Response(response: 401, description: 'FIEL authentication failed'),
        ]
    )]
    public function descargar(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'certificate' => 'required|file',
            'private_key' => 'required|file',
            'passphrase' => 'required|string',
            'package_ids' => 'required|string',
            'service_type' => 'sometimes|string|in:cfdi,retenciones',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()->toArray(),
            ], 400);
        }

        $service = new SatWsService();

        if (!$service->createSession(
            $request->file('certificate'),
            $request->file('private_key'),
            $request->input('passphrase'),
            $request->input('service_type', 'cfdi')
        )) {
            return response()->json([
                'success' => false,
                'errors' => $service->getErrors(),
            ], 401);
        }

        // Parse comma-separated package IDs
        $packageIds = array_map('trim', explode(',', $request->input('package_ids')));
        $packageIds = array_filter($packageIds);

        if (empty($packageIds)) {
            return response()->json([
                'success' => false,
                'errors' => ['package_ids' => 'At least one package ID is required'],
            ], 400);
        }

        $result = $service->descargar($packageIds);

        if ($result === null) {
            return response()->json([
                'success' => false,
                'errors' => $service->getErrors(),
                'messages' => $service->getMessages(),
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'count' => count($result),
                'packages' => $result,
            ],
            'messages' => $service->getMessages(),
        ]);
    }
}
