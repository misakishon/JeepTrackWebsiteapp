<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AccountConnectionTest extends TransportDatabaseTest
{
    public function test_profile_update_is_persistent_and_does_not_change_the_account_role(): void
    {
        $user = $this->user('Passenger');
        $this->actingAs($user)->patchJson('/api/transport/profile', ['fullname' => 'Updated passenger', 'email' => 'updated@example.test', 'role' => 'Admin'])
            ->assertOk()->assertJsonPath('user.fullname', 'Updated passenger')->assertJsonPath('user.role', 'Passenger');
        $this->assertDatabaseHas('Users', ['user_id' => $user->getKey(), 'fullname' => 'Updated passenger', 'email' => 'updated@example.test', 'role' => 'Passenger']);
        $this->getJson('/api/transport/snapshot')->assertJsonPath('profile.email', 'updated@example.test');
    }

    public function test_password_change_requires_the_old_password_and_allows_a_new_login(): void
    {
        $user = $this->user('Passenger');
        $payload = ['password' => 'replacement-password', 'password_confirmation' => 'replacement-password'];
        $this->actingAs($user)->putJson('/api/transport/password', $payload + ['current_password' => 'wrong'])->assertUnprocessable();
        $this->putJson('/api/transport/password', $payload + ['current_password' => 'test-password'])->assertOk();
        $this->assertTrue(Hash::check('replacement-password', $user->fresh()->getRawOriginal('password')));
        $this->postJson('/logout')->assertOk()->assertJsonPath('redirect', '/login');
        $this->postJson('/login', ['email' => $user->email, 'password' => 'test-password'])->assertUnprocessable();
        $this->postJson('/login', ['email' => $user->email, 'password' => 'replacement-password'])->assertOk()->assertJsonPath('redirect', '/passenger/home');
    }

    public function test_login_redirects_all_three_roles_to_their_actual_workspaces(): void
    {
        foreach (['Driver' => '/driver/home', 'Passenger' => '/passenger/home', 'Admin' => '/admin/analytics'] as $role => $path) {
            $user = $this->user($role);
            $this->postJson('/login', ['email' => $user->email, 'password' => 'test-password'])->assertOk()->assertJsonPath('redirect', $path);
            $this->postJson('/logout')->assertOk();
            $this->getJson('/api/transport/snapshot')->assertUnauthorized();
        }
    }
}
