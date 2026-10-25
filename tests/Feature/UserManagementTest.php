<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\ActivityLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_update_and_delete_user(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $this->post(route('users.store'), [
            'name' => 'Budi Gudang', 'email' => 'budi@erp.test', 'role' => 'gudang',
            'password' => 'password123', 'password_confirmation' => 'password123', 'is_active' => 1,
        ])->assertRedirect(route('users.index'));

        $user = User::where('email', 'budi@erp.test')->first();
        $this->assertSame(Role::Gudang, $user->role);
        $this->assertTrue($user->is_active);

        $this->get(route('users.edit', $user))->assertOk();

        // Password kosong = tidak berubah
        $this->put(route('users.update', $user), [
            'name' => 'Budi', 'email' => 'budi@erp.test', 'role' => 'sales', 'password' => '', 'is_active' => 0,
        ])->assertRedirect(route('users.index'));

        $user->refresh();
        $this->assertSame(Role::Sales, $user->role);
        $this->assertFalse($user->is_active);
        $this->assertTrue(Hash::check('password123', $user->password));
        $this->assertSame(2, ActivityLog::where('subject_type', 'user')->count());

        $this->delete(route('users.destroy', $user))->assertRedirect();
        $this->assertModelMissing($user);
    }

    public function test_admin_cannot_demote_or_delete_self(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);

        $this->put(route('users.update', $admin), [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'sales', 'is_active' => 1,
        ])->assertSessionHas('error');
        $this->assertSame(Role::Admin, $admin->fresh()->role);

        $this->delete(route('users.destroy', $admin))->assertSessionHas('error');
        $this->assertModelExists($admin);
    }

    public function test_non_admin_cannot_manage_users(): void
    {
        $this->actingAs(User::factory()->role(Role::Sales)->create());

        $this->get(route('users.index'))->assertForbidden();
        $this->post(route('users.store'), [])->assertForbidden();
    }

    public function test_user_can_update_profile_and_password(): void
    {
        $user = User::factory()->role(Role::Gudang)->create(['password' => 'lama12345']);
        $this->actingAs($user);

        $this->get(route('profile.edit'))->assertOk()->assertSee('Gudang');

        $this->put(route('profile.update'), ['name' => 'Nama Baru', 'email' => 'baru@erp.test'])->assertSessionHas('success');
        $this->assertSame('Nama Baru', $user->fresh()->name);

        $this->put(route('profile.password'), [
            'current_password' => 'salah', 'password' => 'baru12345', 'password_confirmation' => 'baru12345',
        ])->assertSessionHasErrors('current_password');

        $this->put(route('profile.password'), [
            'current_password' => 'lama12345', 'password' => 'baru12345', 'password_confirmation' => 'baru12345',
        ])->assertSessionHas('success');
        $this->assertTrue(Hash::check('baru12345', $user->fresh()->password));
    }

    public function test_admin_can_update_company_settings_with_logo(): void
    {
        Storage::fake('public');
        $this->actingAs(User::factory()->create());

        $this->put(route('settings.update'), [
            'company_name' => 'PT Garmen Maju',
            'company_city' => 'Bandung',
            'logo' => UploadedFile::fake()->image('logo.png', 120, 120),
        ])->assertSessionHas('success');

        $this->assertSame('PT Garmen Maju', Setting::get('company_name'));
        Storage::disk('public')->assertExists(Setting::get('company_logo'));
        $this->get(route('settings.edit'))->assertOk()->assertSee('PT Garmen Maju');
    }
}
