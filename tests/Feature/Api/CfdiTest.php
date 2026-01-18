<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\FielCredential;
use App\Models\DownloadJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

class CfdiTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = Client::create([
            'name' => 'Test Company',
            'email' => 'test@example.com',
            'api_key_hash' => Hash::make('test-key'),
            'is_active' => true,
        ]);

        $this->token = JWTAuth::fromUser($this->client);
    }

    public function test_query_requires_authentication(): void
    {
        $response = $this->postJson('/api/v1/cfdis/query', []);

        $response->assertStatus(401);
    }

    public function test_query_validates_required_fields(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/v1/cfdis/query', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['credential_id', 'passphrase', 'query_type']);
    }

    public function test_query_requires_valid_credential(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/v1/cfdis/query', [
            'credential_id' => '00000000-0000-0000-0000-000000000000',
            'passphrase' => 'test',
            'query_type' => 'date_range',
            'start_date' => '2024-01-01',
            'end_date' => '2024-01-31',
        ]);

        $response->assertStatus(422);
    }

    public function test_download_creates_job(): void
    {
        $credential = FielCredential::create([
            'client_id' => $this->client->id,
            'rfc' => 'TEST123456ABC',
            'certificate_encrypted' => encrypt('fake-cert'),
            'private_key_encrypted' => encrypt('fake-key'),
            'is_valid' => true,
        ]);

        // Note: This will fail authentication with SAT but tests job creation flow
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->postJson('/api/v1/cfdis/download', [
            'credential_id' => $credential->id,
            'passphrase' => 'test-password',
            'query_type' => 'date_range',
            'start_date' => '2024-01-01',
            'end_date' => '2024-01-31',
            'resource_types' => ['xml'],
        ]);

        // Should return 202 Accepted (job queued)
        $response->assertStatus(202)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'job_id',
                    'status',
                ],
            ]);

        $this->assertDatabaseHas('download_jobs', [
            'client_id' => $this->client->id,
            'status' => 'pending',
        ]);
    }

    public function test_jobs_list_returns_only_client_jobs(): void
    {
        // Create job for our client
        $job = DownloadJob::create([
            'client_id' => $this->client->id,
            'fiel_credential_id' => FielCredential::create([
                'client_id' => $this->client->id,
                'rfc' => 'TEST123456ABC',
                'certificate_encrypted' => encrypt('cert'),
                'private_key_encrypted' => encrypt('key'),
            ])->id,
            'status' => 'completed',
            'query_params' => ['test' => 'data'],
        ]);

        // Create job for another client
        $otherClient = Client::create([
            'name' => 'Other Company',
            'email' => 'other@example.com',
            'api_key_hash' => Hash::make('key'),
        ]);

        DownloadJob::create([
            'client_id' => $otherClient->id,
            'fiel_credential_id' => FielCredential::create([
                'client_id' => $otherClient->id,
                'rfc' => 'OTHER12345ABC',
                'certificate_encrypted' => encrypt('cert'),
                'private_key_encrypted' => encrypt('key'),
            ])->id,
            'status' => 'completed',
            'query_params' => ['other' => 'data'],
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson('/api/v1/jobs');

        $response->assertStatus(200);
        
        // Should only see our client's job
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals($job->id, $response->json('data.data.0.id'));
    }

    public function test_job_status_returns_progress(): void
    {
        $credential = FielCredential::create([
            'client_id' => $this->client->id,
            'rfc' => 'TEST123456ABC',
            'certificate_encrypted' => encrypt('cert'),
            'private_key_encrypted' => encrypt('key'),
        ]);

        $job = DownloadJob::create([
            'client_id' => $this->client->id,
            'fiel_credential_id' => $credential->id,
            'status' => 'processing',
            'query_params' => ['test' => 'data'],
            'total_cfdis' => 100,
            'downloaded_count' => 50,
            'failed_count' => 5,
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $this->token,
        ])->getJson("/api/v1/jobs/{$job->id}");

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'status' => 'processing',
                    'progress' => 55.0, // (50+5)/100 * 100
                    'total_cfdis' => 100,
                    'downloaded_count' => 50,
                    'failed_count' => 5,
                ],
            ]);
    }
}
