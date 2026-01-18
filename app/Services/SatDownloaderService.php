<?php

namespace App\Services;

use App\Models\FielCredential;
use App\Models\DownloadJob;
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
use PhpCfdi\Credentials\Credential;
use DateTimeImmutable;
use Exception;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class SatDownloaderService
{
    private ?SatScraper $scraper = null;
    private array $errors = [];
    private array $messages = [];

    /**
     * Create a scraper instance from stored FIEL credentials
     */
    public function createFromCredential(
        FielCredential $fielCredential,
        string $passphrase
    ): bool {
        try {
            // Get decrypted credentials
            $certificate = $fielCredential->certificate;
            $privateKey = $fielCredential->private_key;

            $credential = Credential::create($certificate, $privateKey, $passphrase);

            if (!$credential->isFiel()) {
                $this->errors[] = 'The certificate and private key is not a valid FIEL';
                return false;
            }

            if (!$credential->certificate()->validOn()) {
                $this->errors[] = 'The FIEL certificate has expired';
                return false;
            }

            // Configure Guzzle with SSL workarounds for SAT
            $client = new Client([
                RequestOptions::CONNECT_TIMEOUT => 30,
                RequestOptions::TIMEOUT => 300,
                RequestOptions::VERIFY => true,
                'curl' => [
                    CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1',
                    CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                ],
            ]);

            $httpGateway = new SatHttpGateway($client);
            $sessionManager = FielSessionManager::create($credential);
            
            $this->scraper = new SatScraper($sessionManager, $httpGateway);
            $this->messages[] = 'Successfully authenticated with FIEL';
            
            return true;
        } catch (Exception $e) {
            $this->errors[] = 'Authentication error: ' . $e->getMessage();
            Log::error('SAT Authentication Error', [
                'credential_id' => $fielCredential->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Query CFDIs by date range
     */
    public function queryByDateRange(
        string $startDate,
        string $endDate,
        string $downloadType = 'emitidos',
        string $stateVoucher = 'todos'
    ): ?MetadataList {
        if ($this->scraper === null) {
            $this->errors[] = 'Scraper not initialized';
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
            
            return $list;
        } catch (Exception $e) {
            $this->errors[] = 'Query error: ' . $e->getMessage();
            Log::error('SAT Query Error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Query CFDIs by UUIDs
     */
    public function queryByUuids(
        array $uuids,
        string $downloadType = 'emitidos'
    ): ?MetadataList {
        if ($this->scraper === null) {
            $this->errors[] = 'Scraper not initialized';
            return null;
        }

        try {
            $type = $downloadType === 'recibidos' 
                ? DownloadType::recibidos() 
                : DownloadType::emitidos();

            $list = $this->scraper->listByUuids($uuids, $type);
            $this->messages[] = "Found {$list->count()} CFDIs";
            
            return $list;
        } catch (Exception $e) {
            $this->errors[] = 'Query error: ' . $e->getMessage();
            Log::error('SAT UUID Query Error', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Download resources to storage
     */
    public function downloadResources(
        MetadataList $list,
        array $resourceTypes,
        string $storagePath,
        int $concurrency = 10
    ): array {
        if ($this->scraper === null) {
            $this->errors[] = 'Scraper not initialized';
            return [];
        }

        $results = [];

        try {
            // Ensure session is alive
            $this->scraper->confirmSessionIsAlive();

            $fullPath = Storage::path($storagePath);
            
            if (!is_dir($fullPath)) {
                mkdir($fullPath, 0755, true);
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

                $downloaded = $this->scraper
                    ->resourceDownloader($resourceType, $list, $concurrency)
                    ->saveTo($fullPath, true, 0755);

                $results[$type] = $downloaded;
                $this->messages[] = "Downloaded " . count($downloaded) . " {$type} files";
            }
        } catch (Exception $e) {
            $this->errors[] = 'Download error: ' . $e->getMessage();
            Log::error('SAT Download Error', ['error' => $e->getMessage()]);
        }

        return $results;
    }

    /**
     * Convert MetadataList to array
     */
    public function metadataToArray(MetadataList $list): array
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

    public function clearErrors(): void
    {
        $this->errors = [];
    }
}
