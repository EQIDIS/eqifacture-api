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
            
            // Credential contents are now out of scope and will be garbage collected
            // We don't store them anywhere
            
            return true;
        } catch (Exception $e) {
            $this->errors[] = 'Authentication error: ' . $e->getMessage();
            Log::error('SAT Proxy Auth Error', ['error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * Query CFDIs and return metadata as array
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
            $until = new DateTimeImmutable($endDate);

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
            $this->messages[] = "Found {$list->count()} CFDIs";
            
            return $this->metadataToArray($list);
        } catch (Exception $e) {
            $this->errors[] = 'Query error: ' . $e->getMessage();
            Log::error('SAT Proxy Query Error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Download CFDIs and return file contents directly (Base64 encoded)
     */
    public function downloadAndReturn(
        string $startDate,
        string $endDate,
        string $downloadType,
        string $stateVoucher,
        array $resourceTypes,
        int $maxResults = 100
    ): ?array {
        if ($this->scraper === null) {
            $this->errors[] = 'Not authenticated';
            return null;
        }

        try {
            // First, query to get the list
            $since = new DateTimeImmutable($startDate);
            $until = new DateTimeImmutable($endDate);

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
            
            // Limit results
            $limitedList = $this->limitMetadataList($list, $maxResults);
            
            // Ensure session is alive before downloading
            $this->scraper->confirmSessionIsAlive();
            
            // Download each resource type and collect contents
            return $this->downloadToMemory($limitedList, $resourceTypes);
            
        } catch (Exception $e) {
            $this->errors[] = 'Download error: ' . $e->getMessage();
            Log::error('SAT Proxy Download Error', ['error' => $e->getMessage()]);
            return null;
        }
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
        
        return $files;
    }

    /**
     * Limit MetadataList to max results
     */
    private function limitMetadataList(MetadataList $list, int $max): MetadataList
    {
        $items = [];
        $count = 0;
        
        foreach ($list as $item) {
            if ($count >= $max) {
                break;
            }
            $items[] = $item;
            $count++;
        }
        
        return new MetadataList($items);
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
