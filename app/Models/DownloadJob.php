<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DownloadJob extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'client_id',
        'fiel_credential_id',
        'status',
        'query_params',
        'resource_types',
        'total_cfdis',
        'downloaded_count',
        'failed_count',
        'errors',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'query_params' => 'array',
            'resource_types' => 'array',
            'errors' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function fielCredential(): BelongsTo
    {
        return $this->belongsTo(FielCredential::class);
    }

    public function cfdiFiles(): HasMany
    {
        return $this->hasMany(CfdiFile::class);
    }

    // Status helpers
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isProcessing(): bool
    {
        return $this->status === 'processing';
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function markAsProcessing(): void
    {
        $this->update([
            'status' => 'processing',
            'started_at' => now(),
        ]);
    }

    public function markAsCompleted(): void
    {
        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    public function markAsFailed(array $errors = []): void
    {
        $this->update([
            'status' => 'failed',
            'errors' => $errors,
            'completed_at' => now(),
        ]);
    }

    public function incrementDownloaded(): void
    {
        $this->increment('downloaded_count');
    }

    public function incrementFailed(): void
    {
        $this->increment('failed_count');
    }

    public function getProgressPercentage(): float
    {
        if ($this->total_cfdis === 0) {
            return 0;
        }
        
        return round(($this->downloaded_count + $this->failed_count) / $this->total_cfdis * 100, 2);
    }
}
