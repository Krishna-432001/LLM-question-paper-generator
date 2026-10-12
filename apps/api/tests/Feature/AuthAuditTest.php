<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Tenancy\TenantContext;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Who signed in, and who tried to.
 *
 * This is the first thing an audit trail gets asked and was the last thing
 * this one recorded. Failed attempts matter more than successful ones: a
 * successful login is one row a day, a run of failures is an attack in
 * progress.
 */
final class AuthAuditTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct-horse-battery';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('login:auth-audit@example.test|127.0.0.1');

        TenantContext::withoutTenancy(function (): void {
            $this->user = User::query()->create([
                'name' => 'Auditee',
                'email' => 'auth-audit@example.test',
                'password' => Hash::make(self::PASSWORD),
            ]);

            $org = Organization::query()->create([
                'name' => 'Auth Org', 'slug' => 'auth-org',
                'type' => 'individual', 'owner_user_id' => $this->user->getKey(),
            ]);

            OrganizationUser::query()->create([
                'organization_id' => $org->getKey(),
                'user_id' => $this->user->getKey(),
                'status' => 'active', 'is_default' => true,
            ]);
        });
    }

    /** @return array<int, AuditLog> */
    private function entries(string $action): array
    {
        return TenantContext::withoutTenancy(
            fn () => AuditLog::query()->where('action', $action)->get()->all()
        );
    }

    public function test_a_successful_sign_in_is_recorded_without_the_token(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $entries = $this->entries('auth.login');

        $this->assertCount(1, $entries);
        $this->assertSame($this->user->getKey(), $entries[0]->auditable_id);

        // The issued token must never reach the log: an audit trail that
        // records credentials is a credential store.
        $token = $response->json('data.token');
        $this->assertStringNotContainsString(
            (string) $token,
            json_encode($entries[0]->changes, JSON_THROW_ON_ERROR),
        );
    }

    public function test_a_wrong_password_is_recorded_against_the_account(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'not-the-password',
        ])->assertStatus(422);

        $entries = $this->entries('auth.failed');

        $this->assertCount(1, $entries);
        // Attached to the user, so "is someone trying this account?" is a
        // question the log can answer.
        $this->assertSame($this->user->getKey(), $entries[0]->auditable_id);
        $this->assertSame('bad_password', $entries[0]->changes['reason']);
    }

    public function test_an_unknown_address_is_recorded_but_never_disclosed(): void
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'nobody@example.test',
            'password' => 'whatever',
        ])->assertStatus(422);

        $entries = $this->entries('auth.failed');

        $this->assertCount(1, $entries);
        $this->assertSame('unknown_email', $entries[0]->changes['reason']);
        $this->assertNull($entries[0]->auditable_id);

        // The distinction is kept internally and must not leak outward, or the
        // endpoint becomes an account enumerator.
        $this->assertSame(
            'These credentials do not match our records.',
            $response->json('errors.email.0'),
        );
    }

    public function test_tripping_the_limiter_is_itself_recorded(): void
    {
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->postJson('/api/v1/auth/login', [
                'email' => $this->user->email,
                'password' => 'wrong',
            ]);
        }

        // Five failures then a refusal: the refusal is the row that says an
        // attack got as far as being blocked.
        $this->assertCount(5, $this->entries('auth.failed'));
        $this->assertCount(1, $this->entries('auth.throttled'));
    }

    public function test_signing_out_is_recorded(): void
    {
        $token = $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => self::PASSWORD,
        ])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        $entries = $this->entries('auth.logout');

        $this->assertCount(1, $entries);
        $this->assertSame($this->user->getKey(), $entries[0]->auditable_id);
    }

    public function test_no_password_ever_reaches_the_log(): void
    {
        $this->postJson('/api/v1/auth/login', [
            'email' => $this->user->email,
            'password' => 'a-very-distinctive-wrong-password',
        ])->assertStatus(422);

        $all = TenantContext::withoutTenancy(
            fn () => json_encode(AuditLog::query()->get()->toArray(), JSON_THROW_ON_ERROR)
        );

        $this->assertStringNotContainsString('a-very-distinctive-wrong-password', $all);
        $this->assertStringNotContainsString(self::PASSWORD, $all);
    }
}
