<?php

namespace App\Services;

use PhpCfdi\SatWsDescargaMasiva\RequestBuilder\FielRequestBuilder\Fiel;
use PhpCfdi\SatWsDescargaMasiva\RequestBuilder\FielRequestBuilder\FielRequestBuilder;
use PhpCfdi\SatWsDescargaMasiva\Service;
use PhpCfdi\SatWsDescargaMasiva\WebClient\GuzzleWebClient;
use PhpCfdi\SatWsDescargaMasiva\Shared\ServiceEndpoints;
use PhpCfdi\SatWsDescargaMasiva\Shared\DateTimePeriod;
use PhpCfdi\SatWsDescargaMasiva\Shared\DownloadType;
use PhpCfdi\SatWsDescargaMasiva\Shared\RequestType;
use PhpCfdi\SatWsDescargaMasiva\Shared\DocumentStatus;
use PhpCfdi\SatWsDescargaMasiva\Shared\DocumentType;
use PhpCfdi\SatWsDescargaMasiva\Shared\ComplementoCfdi;
use PhpCfdi\SatWsDescargaMasiva\Shared\RfcMatch;
use PhpCfdi\SatWsDescargaMasiva\Shared\RfcMatches;
use PhpCfdi\SatWsDescargaMasiva\Services\Query\QueryParameters;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * Stateless SAT WebService Proxy Service
 * 
 * Wraps phpcfdi/sat-ws-descarga-masiva for massive CFDI downloads.
 * This is async by nature - can take minutes to 72 hours.
 * 
 * Does NOT store any data. All credentials are used only for the request
 * and immediately discarded.
 */
class SatWsService
{
    private ?Service $service = null;
    private array $errors = [];
    private array $messages = [];

    /**
     * Create authenticated service from uploaded FIEL files
     * Credentials are NOT stored - used only for this request
     * 
     * @param UploadedFile $certificate FIEL .cer file
     * @param UploadedFile $privateKey FIEL .key file
     * @param string $passphrase FIEL password
     * @param string $serviceType 'cfdi' or 'retenciones'
     */
    public function createSession(
        UploadedFile $certificate,
        UploadedFile $privateKey,
        string $passphrase,
        string $serviceType = 'cfdi'
    ): bool {
        try {
            $certificateContent = file_get_contents($certificate->getPathname());
            $privateKeyContent = file_get_contents($privateKey->getPathname());

            $fiel = Fiel::create($certificateContent, $privateKeyContent, $passphrase);

            if (!$fiel->isValid()) {
                $this->errors[] = 'Invalid FIEL: Certificate may be a CSD or expired';
                return false;
            }

            $webClient = new GuzzleWebClient();
            $requestBuilder = new FielRequestBuilder($fiel);

            // Choose endpoints based on service type
            $endpoints = match ($serviceType) {
                'retenciones' => ServiceEndpoints::retenciones(),
                default => ServiceEndpoints::cfdi(),
            };

            $this->service = new Service($requestBuilder, $webClient, null, $endpoints);
            $this->messages[] = "FIEL authenticated for {$serviceType} service";

            return true;
        } catch (Exception $e) {
            $this->errors[] = 'Authentication error: ' . $e->getMessage();
            Log::error('SAT WS Auth Error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Create download request (solicitud)
     * Returns requestId for later verification
     * 
     * @param string $startDate Format: Y-m-d H:i:s
     * @param string $endDate Format: Y-m-d H:i:s
     * @param string $downloadType 'emitidos' or 'recibidos'
     * @param string $requestType 'cfdi' or 'metadata'
     * @param string|null $documentStatus 'vigentes', 'cancelados', or null for all
     * @param string|null $documentType 'ingreso', 'egreso', 'traslado', 'nomina', 'pago', or null
     * @param string|null $complemento Complemento filter (e.g., 'pagos20')
     * @param string|null $rfcMatch RFC to filter by (emisor for recibidos, receptor for emitidos)
     */
    public function solicitar(
        string $startDate,
        string $endDate,
        string $downloadType = 'recibidos',
        string $requestType = 'cfdi',
        ?string $documentStatus = null,
        ?string $documentType = null,
        ?string $complemento = null,
        ?string $rfcMatch = null
    ): ?array {
        if ($this->service === null) {
            $this->errors[] = 'Not authenticated';
            return null;
        }

        try {
            // Create date period (minimum 2 seconds interval required by SAT)
            $period = DateTimePeriod::createFromValues($startDate, $endDate);

            // Build query parameters
            $query = QueryParameters::create($period);

            // Download type: emitidos or recibidos
            $query = $query->withDownloadType(
                $downloadType === 'emitidos' ? DownloadType::issued() : DownloadType::received()
            );

            // Request type: CFDI (xml) or Metadata
            $query = $query->withRequestType(
                $requestType === 'metadata' ? RequestType::metadata() : RequestType::xml()
            );

            // Document status filter
            if ($documentStatus !== null) {
                $status = match ($documentStatus) {
                    'vigentes', 'active' => DocumentStatus::active(),
                    'cancelados', 'cancelled' => DocumentStatus::cancelled(),
                    default => null,
                };
                if ($status !== null) {
                    $query = $query->withDocumentStatus($status);
                }
            }

            // IMPORTANT: SAT requires active status for received XML downloads
            if ($downloadType === 'recibidos' && $requestType !== 'metadata' && $documentStatus === null) {
                $query = $query->withDocumentStatus(DocumentStatus::active());
                $this->messages[] = 'Note: Forcing active status for received XML (SAT requirement)';
            }

            // Document type filter
            if ($documentType !== null) {
                $type = match ($documentType) {
                    'ingreso' => DocumentType::ingreso(),
                    'egreso' => DocumentType::egreso(),
                    'traslado' => DocumentType::traslado(),
                    'nomina' => DocumentType::nomina(),
                    'pago' => DocumentType::pago(),
                    default => null,
                };
                if ($type !== null) {
                    $query = $query->withDocumentType($type);
                }
            }

            // Complemento filter
            if ($complemento !== null) {
                $comp = match ($complemento) {
                    'pagos10' => ComplementoCfdi::pagos10(),
                    'pagos20' => ComplementoCfdi::pagos20(),
                    'aerolineas10' => ComplementoCfdi::aerolineas10(),
                    'cartaporte10' => ComplementoCfdi::cartaporte10(),
                    'cartaporte20' => ComplementoCfdi::cartaporte20(),
                    'cartaporte30' => ComplementoCfdi::cartaporte30(),
                    'cartaporte31' => ComplementoCfdi::cartaporte31(),
                    'comercioexterior10' => ComplementoCfdi::comercioExterior10(),
                    'comercioexterior11' => ComplementoCfdi::comercioExterior11(),
                    'comercioexterior20' => ComplementoCfdi::comercioExterior20(),
                    'donat10' => ComplementoCfdi::donat10(),
                    'donat11' => ComplementoCfdi::donat11(),
                    'gastoshidrocarburos10' => ComplementoCfdi::gastosHidrocarburos10(),
                    'ine11' => ComplementoCfdi::ine11(),
                    'ingresoshidrocarburos10' => ComplementoCfdi::ingresosHidrocarburos10(),
                    'leyendasfisc10' => ComplementoCfdi::leyendasFisc10(),
                    'nomina11' => ComplementoCfdi::nomina11(),
                    'nomina12' => ComplementoCfdi::nomina12(),
                    'notariospublicos10' => ComplementoCfdi::notariosPublicos10(),
                    'obraimp10' => ComplementoCfdi::obraImp10(),
                    'pfic10' => ComplementoCfdi::pfic10(),
                    'renovyvehsam10' => ComplementoCfdi::renovyVehSam10(),
                    'servicioparcialdeconstruccion10' => ComplementoCfdi::servicioParcialDeConstruccion10(),
                    'turista10' => ComplementoCfdi::turista10(),
                    'venta10' => ComplementoCfdi::venta10(),
                    default => null,
                };
                if ($comp !== null) {
                    $query = $query->withComplement($comp);
                }
            }

            // RFC filter
            if ($rfcMatch !== null) {
                $rfcMatches = RfcMatches::create(RfcMatch::create($rfcMatch));
                $query = $query->withRfcMatches($rfcMatches);
            }

            // Validate query parameters
            $errors = $query->validate();
            if (!empty($errors)) {
                $this->errors = array_merge($this->errors, $errors);
                return null;
            }

            // Submit query to SAT
            $result = $this->service->query($query);

            if (!$result->getStatus()->isAccepted()) {
                $this->errors[] = 'SAT rejected request: ' . $result->getStatus()->getMessage();
                return null;
            }

            $requestId = $result->getRequestId();
            $this->messages[] = "Request accepted with ID: {$requestId}";

            return [
                'request_id' => $requestId,
                'status' => 'accepted',
                'message' => $result->getStatus()->getMessage(),
            ];

        } catch (Exception $e) {
            $this->errors[] = 'Request error: ' . $e->getMessage();
            Log::error('SAT WS Solicitar Error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Verify request status and get package IDs when ready
     * 
     * @param string $requestId Request ID from solicitar()
     * @return array|null Status info with package_ids when finished
     */
    public function verificar(string $requestId): ?array
    {
        if ($this->service === null) {
            $this->errors[] = 'Not authenticated';
            return null;
        }

        try {
            $result = $this->service->verify($requestId);

            // Check if verification call was accepted
            if (!$result->getStatus()->isAccepted()) {
                $this->errors[] = 'Verification failed: ' . $result->getStatus()->getMessage();
                return null;
            }

            // Check if the request itself was accepted
            if (!$result->getCodeRequest()->isAccepted()) {
                return [
                    'status' => 'rejected',
                    'code' => $result->getCodeRequest()->getValue(),
                    'message' => $result->getCodeRequest()->getMessage(),
                    'package_ids' => [],
                    'count' => 0,
                ];
            }

            // Get request status
            $statusRequest = $result->getStatusRequest();
            $statusName = match (true) {
                $statusRequest->isAccepted() => 'accepted',
                $statusRequest->isInProgress() => 'in_progress',
                $statusRequest->isFinished() => 'finished',
                $statusRequest->isFailure() => 'failure',
                $statusRequest->isRejected() => 'rejected',
                $statusRequest->isExpired() => 'expired',
                default => 'unknown',
            };

            $response = [
                'status' => $statusName,
                'message' => $result->getStatus()->getMessage(),
                'package_ids' => [],
                'count' => 0,
                'cfdi_count' => $result->getNumberCfdis(),
            ];

            // Include package IDs if finished
            if ($statusRequest->isFinished()) {
                $response['package_ids'] = $result->getPackagesIds();
                $response['count'] = $result->countPackages();
                $this->messages[] = "Request finished with {$response['count']} packages";
            } else {
                $this->messages[] = "Request status: {$statusName}";
            }

            return $response;

        } catch (Exception $e) {
            $this->errors[] = 'Verification error: ' . $e->getMessage();
            Log::error('SAT WS Verificar Error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Download packages and return Base64-encoded content
     * 
     * @param array $packageIds Package IDs from verificar()
     * @return array|null Array of packages with Base64 content
     */
    public function descargar(array $packageIds): ?array
    {
        if ($this->service === null) {
            $this->errors[] = 'Not authenticated';
            return null;
        }

        $packages = [];

        try {
            foreach ($packageIds as $packageId) {
                $result = $this->service->download($packageId);

                if (!$result->getStatus()->isAccepted()) {
                    $this->errors[] = "Failed to download package {$packageId}: " . $result->getStatus()->getMessage();
                    continue;
                }

                $content = $result->getPackageContent();
                $packages[] = [
                    'package_id' => $packageId,
                    'content_base64' => base64_encode($content),
                    'size' => strlen($content),
                    'format' => 'zip', // SAT returns ZIP files
                ];

                $this->messages[] = "Downloaded package {$packageId} (" . strlen($content) . " bytes)";
            }

            return $packages;

        } catch (Exception $e) {
            $this->errors[] = 'Download error: ' . $e->getMessage();
            Log::error('SAT WS Descargar Error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Get accumulated errors
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Get accumulated messages
     */
    public function getMessages(): array
    {
        return $this->messages;
    }
}
