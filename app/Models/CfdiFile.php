<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class CfdiFile extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'download_job_id',
        'cfdi_uuid',
        'resource_type',
        'storage_path',
        'file_size',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'file_size' => 'integer',
        ];
    }

    public function downloadJob(): BelongsTo
    {
        return $this->belongsTo(DownloadJob::class);
    }

    public function getFileContents(): ?string
    {
        if (Storage::exists($this->storage_path)) {
            return Storage::get($this->storage_path);
        }
        
        return null;
    }

    public function getDownloadUrl(): string
    {
        return route('api.files.download', [
            'uuid' => $this->cfdi_uuid,
            'type' => $this->resource_type,
        ]);
    }

    public function getFilename(): string
    {
        $extensions = [
            'xml' => 'xml',
            'pdf' => 'pdf',
            'cancel_request' => 'pdf',
            'cancel_voucher' => 'pdf',
        ];

        $extension = $extensions[$this->resource_type] ?? 'bin';
        
        return "{$this->cfdi_uuid}.{$extension}";
    }
}
