<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get('/settings/profile');

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch('/settings/profile', [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/settings/profile');

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_timezone_can_be_updated()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'timezone' => 'America/New_York',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('America/New_York', $user->refresh()->timezone);
    }

    public function test_timezone_must_be_a_real_iana_zone()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patch('/settings/profile', [
                'name' => $user->name,
                'email' => $user->email,
                'timezone' => 'Mars/Olympus_Mons',
            ])
            ->assertSessionHasErrors('timezone');

        $this->assertSame('Europe/London', $user->refresh()->timezone);
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete('/settings/profile');

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertGuest();
        $this->assertNull($user->fresh());
    }
}
