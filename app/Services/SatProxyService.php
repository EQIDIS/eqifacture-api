<?php

namespace App\Services;

use Composer\CaBundle\CaBundle;
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
     * FIEL contents kept in memory for the duration of THIS request only, so a
     * failed scrape can be retried on a different Webshare IP (re-login). Never
     * persisted to disk/DB — discarded when the request ends.
     */
    private ?string $certificateContent = null;
    private ?string $privateKeyContent = null;
    private ?string $passphrase = null;

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

            // Keep the FIEL in memory for THIS request so a throttled/failed scrape
            // can be retried on a different Webshare IP (see withProxyRetry).
            $this->certificateContent = $certificateContent;
            $this->privateKeyContent = $privateKeyContent;
            $this->passphrase = $passphrase;

            $this->buildScraper($credential);
            $this->messages[] = 'FIEL authentication successful';
            Log::info('SAT Proxy: Authentication successful using FIEL');

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
     * Build the Guzzle client + SatScraper, routing every request through a
     * fresh Webshare residential IP (sticky for this scrape) when configured.
     *
     * Unlike Node 18, PHP/cURL applies CURLOPT_SSL_CIPHER_LIST to the SAT TLS
     * handshake THROUGH the proxy tunnel, so the 1024-bit DH ("dh key too
     * small") is handled with no extra process flags.
     *
     * @param  bool  $useProxy  When false, skip the Webshare tunnel and egress
     *                          from the server's own IP — used by the fallback
     *                          when Webshare is unreachable (quota exhausted,
     *                          credentials expired, 402 tunnel rejected).
     */
    private function buildScraper(Credential $credential, bool $useProxy = true): void
    {
        // Retry middleware: same-IP retries for transient connection/5xx blips.
        $stack = \GuzzleHttp\HandlerStack::create();
        $stack->push(\GuzzleHttp\Middleware::retry(function ($retries, $request, $response, $exception) {
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
            return 1000 * pow(2, $retries); // exponential backoff
        }));

        // TLS: SAT / GlobalSign chain often fails with VERIFY=true alone (cURL 60). Match
        // phpcfdi + Mozilla bundle + GlobalSign OV intermediate (see sat-cfdi-api scraper).
        $verify = $this->resolveSatTlsVerify();

        $config = [
            'handler' => $stack,
            RequestOptions::CONNECT_TIMEOUT => 90,
            RequestOptions::TIMEOUT => 600,
            RequestOptions::HTTP_ERRORS => true,
            RequestOptions::VERIFY => $verify,
            'curl' => [
                CURLOPT_SSL_CIPHER_LIST => 'DEFAULT@SECLEVEL=1',
            ],
        ];

        // WEB mode only: egress through a different Mexican residential IP per
        // scrape so the SAT never rate-limits a single server IP.
        $proxyUrl = $useProxy ? WebshareProxy::stickyUrl() : null;
        if ($proxyUrl !== null) {
            $config[RequestOptions::PROXY] = $proxyUrl;
            Log::info('SAT Proxy: scraper routed through Webshare', [
                'proxy' => WebshareProxy::mask($proxyUrl),
            ]);
        } elseif (!$useProxy) {
            Log::info('SAT Proxy: scraper using direct server IP (Webshare fallback)');
        }

        $client = new Client($config);
        $httpGateway = new SatHttpGateway($client);
        $sessionManager = FielSessionManager::create($credential);

        $this->scraper = new SatScraper($sessionManager, $httpGateway);
    }

    /**
     * Run a scrape operation, retrying on a DIFFERENT Webshare IP (re-login)
     * when it fails with a SAT/HTTP error (likely throttle or a bad IP). Only
     * retries when a proxy is configured; otherwise runs once.
     *
     * If a proxy tunnel error is detected (402 quota exhausted, 407 auth
     * required, "CONNECT tunnel failed"), the fallback runs immediately
     * without burning the remaining Webshare retries — Webshare is down for
     * this account, no IP rotation will help.
     *
     * After ALL Webshare retries are exhausted, a final attempt is made
     * WITHOUT the proxy (direct from server IP). Trades the per-IP rate
     * limit risk for keeping Web mode usable when Webshare is down.
     *
     * @template T
     * @param  callable():T  $operation
     * @return T
     */
    private function withProxyRetry(callable $operation)
    {
        $proxyEnabled = WebshareProxy::enabled();
        $maxAttempts = $proxyEnabled
            ? max(1, (int) config('sat.webshare_retries', 3))
            : 1;

        $lastError = null;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                return $operation();
            } catch (Throwable $e) {
                $lastError = $e;

                // Webshare tunnel down (402/407/CONNECT) → no point rotating IPs,
                // jump straight to direct-IP fallback.
                if ($proxyEnabled && $this->isProxyTunnelError($e)) {
                    Log::warning('SAT Proxy: Webshare tunnel rejected (likely quota/credentials), falling back to direct IP', [
                        'error' => $e->getMessage(),
                    ]);
                    return $this->runWithoutProxy($operation);
                }

                if (!$this->isRetryableProxyError($e)) {
                    throw $e;
                }
                if ($attempt === $maxAttempts) {
                    // Last Webshare attempt exhausted — try direct IP as last resort.
                    if ($proxyEnabled) {
                        Log::warning("SAT Proxy: {$maxAttempts} Webshare attempts failed, falling back to direct IP", [
                            'error' => $e->getMessage(),
                        ]);
                        return $this->runWithoutProxy($operation);
                    }
                    throw $e;
                }
                Log::warning("SAT Proxy: scrape attempt {$attempt}/{$maxAttempts} failed, retrying with a new IP", [
                    'error' => $e->getMessage(),
                ]);
                $this->rebuildScraperWithNewIp();
            }
        }

        throw $lastError; // unreachable
    }

    /** Errors worth retrying from a fresh residential IP. */
    private function isRetryableProxyError(Throwable $e): bool
    {
        return $e instanceof LoginException
            || $e instanceof SatHttpGatewayException
            || $e instanceof \GuzzleHttp\Exception\ConnectException
            || $e instanceof \GuzzleHttp\Exception\RequestException;
    }

    /**
     * The Webshare tunnel itself is rejecting us — quota exhausted (402),
     * proxy auth required (407), or the CONNECT handshake failed entirely.
     * Rotating IPs won't help; only a direct-IP fallback will.
     */
    private function isProxyTunnelError(Throwable $e): bool
    {
        // Walk the exception chain — phpcfdi wraps Guzzle's "CONNECT tunnel failed"
        // inside a generic "Connection error when try to login using FIEL", so the
        // tunnel-level detail only appears in $e->getPrevious()->...
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            $msg = $cur->getMessage();
            if (preg_match('/CONNECT tunnel failed, response (402|407|403)/i', $msg)) {
                return true;
            }
            if (stripos($msg, 'proxy') !== false
                && (stripos($msg, 'tunnel') !== false
                    || stripos($msg, 'authentication required') !== false
                    || stripos($msg, 'could not resolve') !== false)) {
                return true;
            }
        }
        return false;
    }

    /** Rebuild the scraper so the next attempt logs in through a new sticky IP. */
    private function rebuildScraperWithNewIp(): void
    {
        if ($this->certificateContent === null) {
            return;
        }
        $credential = Credential::create(
            $this->certificateContent,
            $this->privateKeyContent,
            $this->passphrase
        );
        $this->buildScraper($credential);
    }

    /**
     * Last-resort fallback: rebuild the scraper WITHOUT the Webshare tunnel
     * (egress from the server's own IP) and run the operation once.
     */
    private function runWithoutProxy(callable $operation)
    {
        if ($this->certificateContent === null) {
            throw new Exception('Cannot fall back to direct IP: FIEL credentials not available in memory');
        }
        $credential = Credential::create(
            $this->certificateContent,
            $this->privateKeyContent,
            $this->passphrase
        );
        $this->buildScraper($credential, useProxy: false);
        return $operation();
    }

    /**
     * Guzzle VERIFY: merged Mozilla CA bundle + GlobalSign RSA OV SSL CA 2018 (SAT portal chain).
     * Set SAT_VERIFY_SSL=0 only for local diagnostics.
     *
     * @return bool|string Path to PEM bundle, true for default CAs, or false to skip verify
     */
    private function resolveSatTlsVerify(): bool|string
    {
        $raw = env('SAT_VERIFY_SSL', getenv('SAT_VERIFY_SSL') ?: '');
        if ($raw === '0' || strcasecmp((string) $raw, 'false') === 0) {
            Log::warning('SAT Proxy: SAT_VERIFY_SSL disables TLS verification (diagnostic only)');

            return false;
        }

        $extra = resource_path('certs/globalsign-rsa-ov-ssl-ca-2018.pem');
        if (! is_readable($extra)) {
            Log::notice('SAT Proxy: GlobalSign PEM missing, falling back to default VERIFY', ['path' => $extra]);

            return true;
        }

        $cacheDir = storage_path('framework');
        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $cachePath = $cacheDir.'/sat_scraper_merged_ca.pem';
        $mozillaPath = CaBundle::getBundledCaBundlePath();
        $needRebuild = ! is_readable($cachePath)
            || @filemtime($cachePath) < @filemtime($extra)
            || @filemtime($cachePath) < @filemtime($mozillaPath);

        if ($needRebuild) {
            $merged = file_get_contents($mozillaPath)."\n".file_get_contents($extra);
            file_put_contents($cachePath, $merged);
        }

        return $cachePath;
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
            return $this->withProxyRetry(function () use ($startDate, $endDate, $downloadType, $stateVoucher) {
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
            });
        } catch (Throwable $e) {
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
            return $this->withProxyRetry(function () use ($startDate, $endDate, $downloadType, $stateVoucher, $resourceTypes) {
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
            });
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
            return $this->withProxyRetry(function () use ($uuids, $downloadType, $resourceTypes) {
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
            });
        } catch (Throwable $e) {
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
