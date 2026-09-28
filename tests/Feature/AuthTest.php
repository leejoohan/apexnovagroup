<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_returns_token_and_safe_user_resource(): void
    {
        $user = User::factory()->create(['password' => 'Correct-password-123']);
        $response = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'Correct-password-123']);
        $response->assertOk()->assertJsonPath('data.token_type', 'Bearer')->assertJsonPath('data.user.id', $user->id)->assertJsonMissingPath('data.user.password');
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_bad_credentials_and_malformed_inputs_are_validation_errors(): void
    {
        $this->postJson('/api/v1/auth/login', ['email' => 'missing@example.test', 'password' => 'wrong'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/v1/auth/login', ['email' => ['bad'], 'password' => ['bad']])->assertUnprocessable();
    }

    public function test_anonymous_requests_without_accept_header_receive_json_401(): void
    {
        $this->get('/api/v1/auth/me')->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
    }

    public function test_me_returns_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test')->plainTextToken;
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.id', $user->id)->assertJsonMissingPath('data.password');
    }

    public function test_logout_revokes_only_the_current_token(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('first');
        $other = $user->createToken('second');
        $this->withToken($token->plainTextToken)->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $other->accessToken->id]);
        auth()->forgetGuards();
        $this->withToken($token->plainTextToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
        auth()->forgetGuards();
        $this->withToken($other->plainTextToken)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_expired_tokens_cannot_authenticate(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('expired', ['*'], now()->subMinute());
        $this->withToken($token->plainTextToken)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_login_is_rate_limited_with_retry_after(): void
    {
        config(['inventory.login_rate_limit' => 2]);
        $payload = ['email' => 'missing@example.test', 'password' => 'bad'];
        $this->postJson('/api/v1/auth/login', $payload)->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', $payload)->assertUnprocessable();
        $this->postJson('/api/v1/auth/login', $payload)->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_login_ip_limit_applies_across_email_addresses(): void
    {
        config(['inventory.login_ip_rate_limit' => 2]);
        foreach (['first', 'second'] as $name) {
            $this->postJson('/api/v1/auth/login', ['email' => "$name@example.test", 'password' => 'bad'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'third@example.test', 'password' => 'bad'])->assertStatus(429);
    }

    public function test_authenticated_api_is_rate_limited_per_user(): void
    {
        config(['inventory.api_rate_limit' => 2]);
        $user = User::factory()->create();
        $this->withToken($user->createToken('test')->plainTextToken);
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->getJson('/api/v1/auth/me')->assertOk();
        $this->getJson('/api/v1/auth/me')->assertStatus(429)->assertHeader('Retry-After');
    }
}
