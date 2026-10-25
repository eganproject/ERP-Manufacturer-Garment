@if ($warehouses->count() > 1)
    <div class="w-56"><x-form.select name="warehouse_id" :options="$warehouses->mapWithKeys(fn ($w) => [$w->id => $w->display_name])" :value="request('warehouse_id')" placeholder="Semua gudang" onchange="this.form.submit()" /></div>
@endif
