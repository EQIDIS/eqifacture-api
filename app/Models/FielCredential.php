<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class FielCredential extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'client_id',
        'rfc',
        'certificate_encrypted',
        'private_key_encrypted',
        'expires_at',
        'is_valid',
    ];

    protected $hidden = [
        'certificate_encrypted',
        'private_key_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'is_valid' => 'boolean',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function downloadJobs(): HasMany
    {
        return $this->hasMany(DownloadJob::class);
    }

    // Encrypt certificate before storing
    public function setCertificateAttribute(string $value): void
    {
        $this->attributes['certificate_encrypted'] = Crypt::encryptString($value);
    }

    // Decrypt certificate when retrieving
    public function getCertificateAttribute(): string
    {
        return Crypt::decryptString($this->attributes['certificate_encrypted']);
    }

    // Encrypt private key before storing
    public function setPrivateKeyAttribute(string $value): void
    {
        $this->attributes['private_key_encrypted'] = Crypt::encryptString($value);
    }

    // Decrypt private key when retrieving
    public function getPrivateKeyAttribute(): string
    {
        return Crypt::decryptString($this->attributes['private_key_encrypted']);
    }
}
