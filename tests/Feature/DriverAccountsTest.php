<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DriverAccountsTest extends TransportDatabaseTest
{
    private function input(array $overrides = []): array
    {
        return array_replace(['fullname' => 'New Driver', 'email' => 'new-driver@example.test', 'license_number' => 'DRIVER-LICENSE-001', 'password' => 'driver-password', 'password_confirmation' => 'driver-password'], $overrides);
    }

    public function test_admin_can_create_a_driver_without_switching_their_own_session(): void
    {
        $admin = $this->user('Admin');
        $response = $this->actingAs($admin)->postJson('/api/admin/drivers', $this->input(['fullname' => ' New Driver ', 'email' => ' New-Driver@example.test ']));
        $response->assertCreated()->assertJsonPath('driver.fullname', 'New Driver')->assertJsonPath('driver.email', 'new-driver@example.test')->assertDontSee('driver-password');
        $this->assertAuthenticatedAs($admin);
        $id = $response->json('driver.user_id');
        $this->assertDatabaseHas('Users', ['user_id' => $id, 'role' => 'Driver']);
        $this->assertDatabaseHas('Drivers', ['user_id' => $id, 'license_number' => 'DRIVER-LICENSE-001']);
        $this->assertTrue(Hash::check('driver-password', DB::table('Users')->where('user_id', $id)->value('password')));
        $this->getJson('/api/admin/drivers')->assertOk()->assertJsonCount(1, 'drivers')->assertJsonPath('drivers.0.email', 'new-driver@example.test')->assertDontSee('password');
        $this->postJson('/logout')->assertOk();
        $this->postJson('/login', ['email' => 'new-driver@example.test', 'password' => 'driver-password'])->assertOk()->assertJsonPath('redirect', '/driver/home');
        $this->getJson('/api/transport/snapshot')->assertJsonPath('profile.role', 'Driver')->assertJsonPath('driver.license_number', 'DRIVER-LICENSE-001')->assertJsonCount(0, 'trips');
    }

    public function test_guests_passengers_and_drivers_cannot_manage_driver_accounts(): void
    {
        $this->getJson('/api/admin/drivers')->assertUnauthorized();
        $this->postJson('/api/admin/drivers', $this->input())->assertUnauthorized();
        foreach (['Passenger', 'Driver'] as $role) {
            $this->actingAs($this->user($role))->getJson('/api/admin/drivers')->assertForbidden();
            $this->postJson('/api/admin/drivers', $this->input())->assertForbidden();
        }
        $this->assertDatabaseCount('Drivers', 0);
    }

    public function test_duplicate_email_or_license_does_not_leave_an_unlinked_account(): void
    {
        $this->actingAs($this->user('Admin'))->postJson('/api/admin/drivers', $this->input())->assertCreated();
        $this->postJson('/api/admin/drivers', $this->input(['license_number' => 'OTHER-LICENSE']))->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->postJson('/api/admin/drivers', $this->input(['email' => 'another@example.test']))->assertUnprocessable()->assertJsonValidationErrors('license_number');
        $this->assertDatabaseCount('Users', 2);
        $this->assertDatabaseCount('Drivers', 1);
    }

    public function test_validation_rejects_invalid_password_role_and_malformed_fields(): void
    {
        $this->actingAs($this->user('Admin'));
        $this->postJson('/api/admin/drivers', $this->input(['password_confirmation' => 'different']))->assertUnprocessable()->assertJsonValidationErrors('password');
        $this->postJson('/api/admin/drivers', $this->input(['password' => 'short', 'password_confirmation' => 'short']))->assertUnprocessable();
        $this->postJson('/api/admin/drivers', $this->input(['role' => 'Admin']))->assertUnprocessable()->assertJsonValidationErrors('role');
        $this->postJson('/api/admin/drivers', $this->input(['fullname' => ['unexpected']]))->assertUnprocessable()->assertJsonValidationErrors('fullname');
        $this->assertDatabaseCount('Users', 1);
        $this->assertDatabaseCount('Drivers', 0);
    }
}
