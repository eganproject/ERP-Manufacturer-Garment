<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * Tooltip penjelasan field (<x-tip>) & panduan alur halaman (<x-guide>).
 */
class FormGuidanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_tip_component_renders_accessible_trigger_with_line_breaks(): void
    {
        $html = Blade::render('<x-tip title="Gudang" text="Baris satu.\nBaris dua." />');

        $this->assertStringContainsString('class="tip-trigger"', $html);
        $this->assertStringContainsString('aria-label="Penjelasan: Gudang"', $html);
        $this->assertStringContainsString('data-tip-title="Gudang"', $html);
        $this->assertStringContainsString("Baris satu.\nBaris dua.", $html);
    }

    public function test_empty_tip_renders_nothing(): void
    {
        $this->assertSame('', trim(Blade::render('<x-tip :text="null" />')));
    }

    public function test_form_fields_show_tip_next_to_label(): void
    {
        View::share('errors', new ViewErrorBag);
        $html = Blade::render('<x-form.input name="code" label="Kode" tip="Kode unik." required />');

        $this->assertStringContainsString('form-label-row', $html);
        $this->assertStringContainsString('data-tip="Kode unik."', $html);
        $this->assertStringNotContainsString('tip="Kode unik." class', $html);
    }

    public function test_main_forms_show_flow_guide_and_tips(): void
    {
        $this->seed(MasterDataSeeder::class);
        $this->actingAs(User::factory()->create());

        foreach (['purchase-orders.create', 'sales-orders.create', 'production-orders.create', 'boms.create', 'quotations.create'] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee('class="guide"', false)
                ->assertSee('tip-trigger', false);
        }
    }
}
