<?php

namespace App\Jobs;

use App\Models\DownloadJob;
use App\Models\CfdiFile;
use App\Services\SatDownloaderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ProcessCfdiDownload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 600; // 10 minutes max

    public function __construct(
        public DownloadJob $downloadJob
    ) {}

    public function handle(): void
    {
        $job = $this->downloadJob;
        
        Log::info('Starting CFDI download job', ['job_id' => $job->id]);
        
        $job->markAsProcessing();

        try {
            $credential = $job->fielCredential;
            $queryParams = $job->query_params;
            $passphrase = decrypt($queryParams['passphrase']);

            $service = new SatDownloaderService();
            
            if (!$service->createFromCredential($credential, $passphrase)) {
                $job->markAsFailed(['Authentication failed: ' . implode(', ', $service->getErrors())]);
                return;
            }

            // Query CFDIs
            $list = null;
            if ($queryParams['query_type'] === 'date_range') {
                $list = $service->queryByDateRange(
                    $queryParams['start_date'],
                    $queryParams['end_date'],
                    $queryParams['download_type'] ?? 'emitidos',
                    $queryParams['state_voucher'] ?? 'todos'
                );
            } else {
                $list = $service->queryByUuids(
                    $queryParams['uuids'],
                    $queryParams['download_type'] ?? 'emitidos'
                );
            }

            if ($list === null || $list->count() === 0) {
                $job->update(['total_cfdis' => 0]);
                $job->markAsCompleted();
                Log::info('No CFDIs found for job', ['job_id' => $job->id]);
                return;
            }

            $job->update(['total_cfdis' => $list->count()]);

            // Prepare storage path
            $storagePath = "cfdis/{$job->client_id}/{$job->id}";
            
            // Download resources
            $results = $service->downloadResources(
                $list,
                $job->resource_types,
                $storagePath,
                10 // Concurrency
            );

            // Store file records
            $metadataMap = [];
            foreach ($service->metadataToArray($list) as $cfdi) {
                $metadataMap[$cfdi['uuid']] = $cfdi;
            }

            foreach ($results as $type => $uuids) {
                foreach ($uuids as $uuid) {
                    $extension = $type === 'xml' ? 'xml' : 'pdf';
                    $filename = match($type) {
                        'xml' => "{$uuid}.xml",
                        'pdf' => "{$uuid}.pdf",
                        'cancel_request' => "{$uuid}-cancel-request.pdf",
                        'cancel_voucher' => "{$uuid}-cancel-voucher.pdf",
                        default => "{$uuid}.{$extension}",
                    };
                    
                    $fullPath = "{$storagePath}/{$filename}";
                    $fileSize = Storage::exists($fullPath) ? Storage::size($fullPath) : 0;

                    CfdiFile::create([
                        'download_job_id' => $job->id,
                        'cfdi_uuid' => $uuid,
                        'resource_type' => $type,
                        'storage_path' => $fullPath,
                        'file_size' => $fileSize,
                        'metadata' => $metadataMap[$uuid] ?? null,
                    ]);

                    $job->incrementDownloaded();
                }
            }

            // Check for errors
            $errors = $service->getErrors();
            if (!empty($errors)) {
                $job->update(['errors' => $errors]);
            }

            $job->markAsCompleted();
            
            Log::info('CFDI download job completed', [
                'job_id' => $job->id,
                'downloaded' => $job->downloaded_count,
                'failed' => $job->failed_count,
            ]);

        } catch (\Exception $e) {
            Log::error('CFDI download job failed', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);
            
            $job->markAsFailed([$e->getMessage()]);
            
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('CFDI download job permanently failed', [
            'job_id' => $this->downloadJob->id,
            'error' => $exception->getMessage(),
        ]);

        $this->downloadJob->markAsFailed([$exception->getMessage()]);
    }
}
