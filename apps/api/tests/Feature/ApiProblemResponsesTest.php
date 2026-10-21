<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\MakesTestData;
use Tests\TestCase;

/**
 * The status codes the web app acts on. Every page sends a 401 to the login
 * screen, so an expired token reported as anything else strands the user on
 * an error message.
 */
final class ApiProblemResponsesTest extends TestCase
{
    use MakesTestData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\LanguageSeeder::class);
        $this->seed(\Database\Seeders\RoleSeeder::class);
    }

    public function test_a_request_without_a_token_is_a_401(): void
    {
        $this->getJson('/api/v1/reviews')
            ->assertUnauthorized()
            ->assertJsonPath('title', 'Unauthenticated')
            ->assertJsonPath('status', 401)
            ->assertJsonPath('instance', 'api/v1/reviews');
    }

    public function test_an_unknown_token_is_a_401(): void
    {
        $this->withToken('1|not-a-real-token')
            ->getJson('/api/v1/chapter-reviews')
            ->assertUnauthorized();
    }

    public function test_a_signed_in_user_without_the_permission_still_gets_a_403(): void
    {
        $student = $this->makeAdmin('student@example.test', 'student', platformAdmin: false);

        $this->actingAs($student)
            ->getJson('/api/v1/reviews')
            ->assertForbidden()
            ->assertJsonPath('title', 'Forbidden');
    }

    public function test_a_missing_record_is_still_a_404(): void
    {
        $this->actingAs($this->makeAdmin())
            ->getJson('/api/v1/reviews/0193c8a0-0000-7000-8000-000000000000')
            ->assertNotFound()
            ->assertJsonPath('title', 'Not found');
    }

    public function test_a_finished_response_is_sent_as_it_is(): void
    {
        Route::middleware('api')->get('/api/__probe', function (): never {
            throw new HttpResponseException(response()->json(['kept' => true], 418));
        });

        $this->getJson('/api/__probe')
            ->assertStatus(418)
            ->assertExactJson(['kept' => true]);
    }
}
