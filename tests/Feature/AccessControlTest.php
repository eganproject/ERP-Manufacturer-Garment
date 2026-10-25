<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterDataSeeder::class);
    }

    private function as(Role $role): static
    {
        return $this->actingAs(User::factory()->role($role)->create());
    }

    public function test_each_role_only_reaches_its_own_modules(): void
    {
        $matrix = [
            // url name => roles yang boleh
            'purchase-orders.index' => [Role::Purchasing, Role::Gudang],
            'purchase-orders.create' => [Role::Purchasing],
            'sales-orders.index' => [Role::Sales, Role::Gudang, Role::Produksi],
            'sales-orders.create' => [Role::Sales],
            'production-orders.index' => [Role::Produksi, Role::Sales, Role::Gudang],
            'production-orders.create' => [Role::Produksi],
            'stock.materials' => [Role::Purchasing, Role::Gudang, Role::Produksi, Role::Sales],
            'stock.adjustment' => [Role::Gudang],
            'suppliers.index' => [Role::Purchasing],
            'customers.index' => [Role::Sales],
            'boms.index' => [Role::Produksi],
            'users.index' => [],
            'settings.edit' => [],
            'activity-logs.index' => [],
        ];

        foreach ([Role::Purchasing, Role::Gudang, Role::Produksi, Role::Sales] as $role) {
            $this->as($role);

            foreach ($matrix as $route => $allowed) {
                $expected = in_array($role, $allowed, true) ? 200 : 403;
                $this->get(route($route))->assertStatus($expected);
            }

            // Dashboard selalu bisa dibuka
            $this->get(route('dashboard'))->assertOk();
        }
    }

    public function test_admin_can_open_everything(): void
    {
        $this->as(Role::Admin);

        foreach (['users.index', 'users.create', 'settings.edit', 'activity-logs.index', 'purchase-orders.create', 'sales-orders.create', 'production-orders.create', 'stock.adjustment'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    public function test_menu_hides_modules_without_permission(): void
    {
        $this->as(Role::Sales)->get(route('dashboard'))
            ->assertSee('Sales Order (SO)')
            ->assertDontSee('Purchase Order (PO)')
            ->assertDontSee('Pengguna & Hak Akses')
            ->assertDontSee('Penyesuaian Stok');
    }

    public function test_inactive_user_cannot_login_and_is_kicked_out(): void
    {
        $user = User::factory()->inactive()->create(['password' => 'rahasia123']);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'rahasia123'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_login_records_last_login(): void
    {
        $user = User::factory()->create(['password' => 'rahasia123']);

        $this->post(route('login'), ['email' => $user->email, 'password' => 'rahasia123']);

        $this->assertNotNull($user->fresh()->last_login_at);
    }
}
