<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_can_register(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Test Company',
            'email' => 'test@example.com',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'client_id',
                    'api_key',
                ],
            ]);

        $this->assertDatabaseHas('clients', [
            'email' => 'test@example.com',
        ]);
    }

    public function test_client_can_login_with_valid_credentials(): void
    {
        $apiKey = 'test-api-key-12345';
        
        $client = Client::create([
            'name' => 'Test Company',
            'email' => 'test@example.com',
            'api_key_hash' => Hash::make($apiKey),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'client_id' => $client->id,
            'api_key' => $apiKey,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'token',
                    'token_type',
                    'expires_in',
                ],
            ]);
    }

    public function test_client_cannot_login_with_invalid_credentials(): void
    {
        $client = Client::create([
            'name' => 'Test Company',
            'email' => 'test@example.com',
            'api_key_hash' => Hash::make('correct-key'),
            'is_active' => true,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'client_id' => $client->id,
            'api_key' => 'wrong-key',
        ]);

        $response->assertStatus(401);
    }

    public function test_inactive_client_cannot_login(): void
    {
        $apiKey = 'test-api-key';
        
        $client = Client::create([
            'name' => 'Test Company',
            'email' => 'test@example.com',
            'api_key_hash' => Hash::make($apiKey),
            'is_active' => false,
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'client_id' => $client->id,
            'api_key' => $apiKey,
        ]);

        $response->assertStatus(403);
    }

    public function test_protected_routes_require_authentication(): void
    {
        $response = $this->getJson('/api/v1/jobs');

        $response->assertStatus(401);
    }

    public function test_health_check_is_public(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'timestamp',
            ]);
    }
}
