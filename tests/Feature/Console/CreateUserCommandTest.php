<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_user_and_audits_it(): void
    {
        $this->artisan('user:create', ['email' => 'Someone@Example.com', 'name' => 'Someone'])
            ->assertSuccessful();

        $user = User::where('email', 'someone@example.com')->sole();
        $this->assertSame('Someone', $user->name);
        $this->assertDatabaseHas('audit_log', [
            'action' => 'user.created',
            'subject_id' => $user->id,
            'actor_id' => null,
        ]);
    }

    public function test_the_name_defaults_to_the_email_local_part(): void
    {
        $this->artisan('user:create', ['email' => 'charlie@example.com'])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'charlie@example.com', 'name' => 'charlie']);
    }

    public function test_it_rejects_an_existing_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $this->artisan('user:create', ['email' => 'taken@example.com'])->assertFailed();

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseMissing('audit_log', ['action' => 'user.created']);
    }

    public function test_it_rejects_an_invalid_email(): void
    {
        $this->artisan('user:create', ['email' => 'not-an-email'])->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
