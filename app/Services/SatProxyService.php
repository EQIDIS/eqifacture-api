<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use PhpCfdi\CfdiSatScraper\SatScraper;
use PhpCfdi\CfdiSatScraper\SatHttpGateway;
use PhpCfdi\CfdiSatScraper\QueryByFilters;
use PhpCfdi\CfdiSatScraper\MetadataList;
use PhpCfdi\CfdiSatScraper\ResourceType;
use PhpCfdi\CfdiSatScraper\Filters\DownloadType;
use PhpCfdi\CfdiSatScraper\Filters\Options\StatesVoucherOption;
use PhpCfdi\CfdiSatScraper\Sessions\Fiel\FielSessionManager;
use PhpCfdi\CfdiSatScraper\Contracts\ResourceDownloadHandlerInterface;
use PhpCfdi\CfdiSatScraper\Exceptions\ResourceDownloadError;
use PhpCfdi\Credentials\Credential;
use Psr\Http\Message\ResponseInterface;
use DateTimeImmutable;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use PhpCfdi\CfdiSatScraper\Exceptions\SatHttpGatewayException;
use PhpCfdi\CfdiSatScraper\Exceptions\LoginException;
use Throwable;

/**
 * Stateless SAT Proxy Service
 * 
 * Does NOT store any data. All credentials are used only for the request
 * and immediately discarded.
 */
class SatProxyService
{
    private ?SatScraper $scraper = null;
    private array $errors = [];
    private array $messages = [];

    /**
     * Authenticate with FIEL from uploaded files
     * Credentials are NOT stored - used only for this request
     */
    public function authenticateFromRequest(
        UploadedFile $certificate,
        UploadedFile $privateKey,
        string $passphrase
    ): bool {
        try {
            $certificateContent = file_get_contents($certificate->getPathname());
            $privateKeyContent = file_get_contents($privateKey->getPathname());

            return $this->authenticateWithContent($certificateContent, $privateKeyContent, $passphrase);
        } catch (\Exception $e) {
            $this->errors[] = 'Error reading credentials: ' . $e->getMessage();
            return false;
        }
    }

    private function authenticateWithContent(
        string $certificateContent,
        string $privateKeyContent,
        string $passphrase
    ): bool {
        try {
            $credential = Credential::create($certificateContent, $privateKeyContent, $passphrase);

            if (!$credential->isFiel()) {
                $this->errors[] = 'The provided files are not a valid FIEL';
                return false;
            }

            if (!$credential->certificate()->validOn()) {
                $this->errors[] = 'The FIEL certificate has expired';
                return false;
            }

            // Configure Guzzle with SSL workarounds for SAT and Retry Middleware
            $stack = \GuzzleHttp\HandlerStack::create();
            $stack->push(\GuzzleHttp\Middleware::retry(function ($retries, $request, $response, $exception) {
                // Retry on connection errors or 5xx server errors
                if ($retries >= 3) {
                    return false;
                }
                if ($exception instanceof \GuzzleHttp\Exception\ConnectException) {
                    return true;
                }
                if ($response && $response->getStatusCode() >= 500) {
                    return true;
                }
                return false;
            }, function ($retries) {
                // Exponential backoff
                return 1000 * pow(2, $retries);
            }));

            $client = new Client([
                'handler' => $stack,
                RequestOptions::CONNECT_TIMEOUT => 60, // Increased connect timeout
                RequestOptions::TIMEOUT => 600,        // Increased request timeout for large downloads
                RequestOptions::VERIFY => true,
                'curl' => [
                    CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1',
                    CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                ],
            ]);

            $httpGateway = new SatHttpGateway($client);
            $sessionManager = FielSessionManager::create($credential);
            
            $this->scraper = new SatScraper($sessionManager, $httpGateway);
            $this->messages[] = 'FIEL authentication successful';
            Log::info('SAT Proxy: Authentication successful using FIEL');
            
            // Credential contents are now out of scope and will be garbage collected
            // We don't store them anywhere
            
            return true;
        } catch (Throwable $e) {
            $this->errors[] = 'Authentication error: ' . $e->getMessage();
            
            $context = ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()];
            
            if ($e instanceof SatHttpGatewayException) {
                // Log the actual HTML response from SAT to see if it's a maintenance page
                $context['sat_response'] = substr($e->getMessage(), 0, 2000); // Capture potentially truncated message which might contain HTML
            }
            
            Log::error('SAT Proxy Auth Error', $context);
            return false;
        }
    }

    /**
     * Query CFDIs and return metadata as array
     * Supports 'emitidos', 'recibidos', or 'ambos' (makes two queries)
     */
    public function queryByDateRange(
        string $startDate,
        string $endDate,
        string $downloadType = 'emitidos',
        string $stateVoucher = 'todos'
    ): ?array {
        if ($this->scraper === null) {
            $this->errors[] = 'Not authenticated';
            return null;
        }

        try {
            $since = new DateTimeImmutable($startDate);
            $until = (new DateTimeImmutable($endDate))->setTime(23, 59, 59);

            // Handle 'ambos' by making two queries
            if ($downloadType === 'ambos') {
                $emitidos = $this->querySingleType($since, $until, 'emitidos', $stateVoucher);
                $recibidos = $this->querySingleType($since, $until, 'recibidos', $stateVoucher);
                
                $combined = array_merge($emitidos ?? [], $recibidos ?? []);
                $this->messages[] = "Found " . count($combined) . " CFDIs (emitidos + recibidos)";
                return $combined;
            }

            return $this->querySingleType($since, $until, $downloadType, $stateVoucher);
        } catch (Exception $e) {
            $this->errors[] = 'Query error: ' . $e->getMessage();
            Log::error('SAT Proxy Query Error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Query a single download type (emitidos or recibidos)
     */
    private function querySingleType(
        DateTimeImmutable $since,
        DateTimeImmutable $until,
        string $downloadType,
        string $stateVoucher
    ): ?array {
        $query = new QueryByFilters($since, $until);

        if ($downloadType === 'recibidos') {
            $query->setDownloadType(DownloadType::recibidos());
        } else {
            $query->setDownloadType(DownloadType::emitidos());
        }

        switch ($stateVoucher) {
            case 'vigentes':
                $query->setStateVoucher(StatesVoucherOption::vigentes());
                break;
            case 'cancelados':
                $query->setStateVoucher(StatesVoucherOption::cancelados());
                break;
            default:
                $query->setStateVoucher(StatesVoucherOption::todos());
        }

        $list = $this->scraper->listByPeriod($query);
        $this->messages[] = "Found {$list->count()} CFDIs ({$downloadType})";
        Log::info("SAT Proxy: Found {$list->count()} CFDIs ({$downloadType})", [
            'since' => $since->format('Y-m-d H:i:s'), 
            'until' => $until->format('Y-m-d H:i:s')
        ]);
        
        return $this->metadataToArray($list);
    }

    /**
     * Download CFDIs and return file contents directly (Base64 encoded)
     */
    public function downloadAndReturn(
        string $startDate,
        string $endDate,
        string $downloadType,
        string $stateVoucher,
        array $resourceTypes
    ): ?array {
        if ($this->scraper === null) {
            $this->errors[] = 'Not authenticated';
            return null;
        }

        try {
            $since = new DateTimeImmutable($startDate);
            $until = (new DateTimeImmutable($endDate))->setTime(23, 59, 59);

            // Handle 'ambos' by making two queries
            if ($downloadType === 'ambos') {
                $emitidos = $this->downloadSingleType($since, $until, 'emitidos', $stateVoucher, $resourceTypes);
                $recibidos = $this->downloadSingleType($since, $until, 'recibidos', $stateVoucher, $resourceTypes);
                
                $combined = array_merge($emitidos ?? [], $recibidos ?? []);
                $this->messages[] = "Downloaded " . count($combined) . " files (emitidos + recibidos)";
                return $combined;
            }

            return $this->downloadSingleType($since, $until, $downloadType, $stateVoucher, $resourceTypes);
            
        } catch (Throwable $e) {
            $this->errors[] = 'Download error: ' . $e->getMessage();
            
            $context = ['error' => $e->getMessage(), 'uuid' => $uuids ?? 'N/A', 'trace' => $e->getTraceAsString()];

             if ($e instanceof SatHttpGatewayException) {
                $context['sat_response'] = substr($e->getMessage(), 0, 2000);
            }

            Log::error('SAT Proxy Download Error', $context);
            return null;
        }
    }

    /**
     * Download a single download type (emitidos or recibidos)
     */
    private function downloadSingleType(
        DateTimeImmutable $since,
        DateTimeImmutable $until,
        string $downloadType,
        string $stateVoucher,
        array $resourceTypes
    ): ?array {
        $query = new QueryByFilters($since, $until);

        if ($downloadType === 'recibidos') {
            $query->setDownloadType(DownloadType::recibidos());
        } else {
            $query->setDownloadType(DownloadType::emitidos());
        }

        switch ($stateVoucher) {
            case 'vigentes':
                $query->setStateVoucher(StatesVoucherOption::vigentes());
                break;
            case 'cancelados':
                $query->setStateVoucher(StatesVoucherOption::cancelados());
                break;
            default:
                $query->setStateVoucher(StatesVoucherOption::todos());
        }

        $list = $this->scraper->listByPeriod($query);
        $this->messages[] = "Found {$list->count()} CFDIs ({$downloadType}) - downloading all";
        Log::info("SAT Proxy: Found {$list->count()} CFDIs ({$downloadType}) to download - starting process", [
            'since' => $since->format('Y-m-d H:i:s'), 
            'until' => $until->format('Y-m-d H:i:s')
        ]);
        
        // Ensure session is alive before downloading
        $this->scraper->confirmSessionIsAlive();
        
        // Download each resource type and collect contents - NO LIMITS
        return $this->downloadToMemory($list, $resourceTypes);
    }

    /**
     * Download specific CFDIs by UUID
     */
    public function downloadByUuids(
        array $uuids,
        string $downloadType,
        array $resourceTypes
    ): ?array {
        if ($this->scraper === null) {
            $this->errors[] = 'Not authenticated';
            return null;
        }

        try {
            $type = $downloadType === 'recibidos' 
                ? DownloadType::recibidos() 
                : DownloadType::emitidos();

            $list = $this->scraper->listByUuids($uuids, $type);
            
            if ($list->count() === 0) {
                $this->messages[] = 'No CFDIs found for the provided UUIDs';
                return [];
            }

            // Ensure session is alive
            $this->scraper->confirmSessionIsAlive();
            
            return $this->downloadToMemory($list, $resourceTypes);
            
        } catch (Exception $e) {
            $this->errors[] = 'Download error: ' . $e->getMessage();
            Log::error('SAT Proxy UUID Download Error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Download files to memory and return as array with Base64 content
     */
    private function downloadToMemory(MetadataList $list, array $resourceTypes): array
    {
        $files = [];
        $metadataMap = $this->metadataToArray($list);
        $metadataByUuid = [];
        
        foreach ($metadataMap as $cfdi) {
            $metadataByUuid[$cfdi['uuid']] = $cfdi;
        }

        foreach ($resourceTypes as $type) {
            $resourceType = match($type) {
                'xml' => ResourceType::xml(),
                'pdf' => ResourceType::pdf(),
                'cancel_request' => ResourceType::cancelRequest(),
                'cancel_voucher' => ResourceType::cancelVoucher(),
                default => null,
            };

            if ($resourceType === null) {
                continue;
            }

            // Use custom handler to capture content in memory
            $handler = new InMemoryDownloadHandler($type, $metadataByUuid);
            
            $this->scraper
                ->resourceDownloader($resourceType, $list, 10)
                ->download($handler);

            $files = array_merge($files, $handler->getFiles());
        }

        $this->messages[] = "Downloaded " . count($files) . " files";
        Log::info("SAT Proxy: Successfully downloaded " . count($files) . " files");
        
        return $files;
    }

    /**
     * Convert MetadataList to array
     */
    private function metadataToArray(MetadataList $list): array
    {
        $result = [];
        
        foreach ($list as $cfdi) {
            $result[] = [
                'uuid' => $cfdi->uuid(),
                'rfc_emisor' => $cfdi->get('rfcEmisor'),
                'nombre_emisor' => $cfdi->get('nombreEmisor'),
                'rfc_receptor' => $cfdi->get('rfcReceptor'),
                'nombre_receptor' => $cfdi->get('nombreReceptor'),
                'fecha_emision' => $cfdi->get('fechaEmision'),
                'fecha_certificacion' => $cfdi->get('fechaCertificacion'),
                'total' => $cfdi->get('total'),
                'efecto_comprobante' => $cfdi->get('efectoComprobante'),
                'estado_comprobante' => $cfdi->get('estadoComprobante'),
                'has_xml' => $cfdi->hasResource(ResourceType::xml()),
                'has_pdf' => $cfdi->hasResource(ResourceType::pdf()),
            ];
        }
        
        return $result;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getMessages(): array
    {
        return $this->messages;
    }
}

/**
 * In-memory download handler - captures file content without saving to disk
 */
class InMemoryDownloadHandler implements ResourceDownloadHandlerInterface
{
    private array $files = [];

    public function __construct(
        private string $resourceType,
        private array $metadataByUuid
    ) {}

    public function onSuccess(string $uuid, string $content, ResponseInterface $response): void
    {
        $this->files[] = [
            'uuid' => $uuid,
            'type' => $this->resourceType,
            'content' => base64_encode($content),
            'size' => strlen($content),
            'metadata' => $this->metadataByUuid[$uuid] ?? null,
        ];
    }

    public function onError(ResourceDownloadError $error): void
    {
        // Log error but continue with other downloads
        Log::warning('SAT download error for UUID: ' . $error->getUuid(), [
            'message' => $error->getMessage(),
        ]);
    }

    public function getFiles(): array
    {
        return $this->files;
    }
}
