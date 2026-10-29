@php
    use App\Enums\ProductionMaterialTransferType;
    $isIssue = $type === ProductionMaterialTransferType::Issue;
@endphp
<x-layouts.app :title="$type->label()" :subtitle="'SPK '.$order->number.' · '.$order->product->name">
    @if ($isIssue)
        <x-guide title="Bahan tambahan untuk SPK" :steps="['Pakai saat bahan dari BOM **kurang** (kain kurang, salah potong, ganti bahan rusak) atau butuh bahan **di luar BOM**.', 'Stok gudang berkurang dan nilainya **menambah HPP SPK** ini.', 'Bahan untuk vendor maklon tetap dikirim lewat order maklon, bukan dari sini.']"></x-guide>
    @else
        <x-guide title="Retur sisa bahan ke gudang" :steps="['Pakai saat produksi selesai memotong/menjahit dan masih ada **sisa bahan** yang layak dipakai lagi.', 'Stok gudang bertambah dan nilainya **mengurangi HPP SPK** ini.', 'Maksimal sebesar bahan yang dipegang bagian produksi internal. Sisa bahan di vendor maklon diretur lewat order maklon.']"></x-guide>
    @endif

    <form method="POST" action="{{ route('production-orders.materials.store', [$order, $type->value]) }}" class="space-y-6"
          data-confirm="{{ $isIssue ? 'Stok bahan di gudang berkurang dan nilainya ditambahkan ke biaya SPK.' : 'Stok bahan di gudang bertambah dan biaya SPK berkurang.' }}" data-confirm-icon="question" data-confirm-button="Ya, simpan">
        @csrf
        <x-card :title="$isIssue ? 'Pengeluaran Bahan Tambahan' : 'Retur Sisa Bahan Produksi'">
            <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                <x-form.input name="date" label="Tanggal" :value="now()" tip="Tanggal bahan diambil dari / dikembalikan ke gudang. Tercatat di kartu stok dan jurnal." required data-date />
                <x-form.warehouse :value="$order->warehouse_id" :label="$isIssue ? 'Dari Gudang' : 'Masuk ke Gudang'" :tip="$isIssue ? 'Gudang asal bahan tambahan. Stok gudang ini berkurang.' : 'Gudang yang menerima sisa bahan. Stok gudang ini bertambah.'" />
                <x-form.textarea name="notes" label="Alasan / Catatan" rows="2" :tip="$isIssue ? 'mis. kain kurang 3 m karena salah potong. Membantu menelusuri pemborosan bahan.' : 'mis. sisa kain setelah potong.'" />
            </div>
        </x-card>

        <x-card title="Bahan" :padding="false">
            <div class="overflow-x-auto">
                <table class="table-base">
                    <thead>
                        <tr>
                            <th>Bahan</th>
                            <th class="text-right">Kebutuhan BOM</th>
                            <th class="text-right">Dikeluarkan</th>
                            <th class="text-right">Diretur</th>
                            @if ($isIssue)
                                <th class="text-right">Stok Gudang</th>
                            @else
                                <th class="text-right">Di Produksi Internal <x-tip title="Di produksi internal" text="Bahan yang dikeluarkan untuk SPK dikurangi yang dikirim ke vendor maklon. Ini batas maksimal retur dari sini." /></th>
                            @endif
                            <th class="w-44">{{ $isIssue ? 'Tambah Sekarang' : 'Retur Sekarang' }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($order->materials as $line)
                            @php $available = (float) ($internal[$line->material_id] ?? 0); @endphp
                            @continue(! $isIssue && $available <= 0)
                            <tr>
                                <td>{{ $line->material->display_name }}</td>
                                <td class="text-right">{{ qty($line->required_quantity) }} {{ $line->material->unit?->code }}</td>
                                <td class="text-right">{{ qty($line->issued_quantity) }}</td>
                                <td class="text-right">{{ qty($line->returned_quantity) }}</td>
                                <td class="text-right font-medium">{{ $isIssue ? qty($line->material->stock) : qty($available) }}</td>
                                <td><input type="number" step="0.01" min="0" @unless ($isIssue) max="{{ $available }}" @endunless name="items[{{ $line->material_id }}]" value="{{ old('items.'.$line->material_id) }}" class="form-control" aria-label="Jumlah {{ $line->material->name }}"></td>
                            </tr>
                        @endforeach
                        @if (! $isIssue && collect($internal)->every(fn ($q) => $q <= 0))
                            <x-empty-row compact colspan="6" message="Tidak ada bahan di produksi internal yang bisa diretur." />
                        @endif
                    </tbody>
                </table>
            </div>
            @if ($isIssue && $materials->isNotEmpty())
                <div class="grid grid-cols-1 gap-3 border-t border-gray-200 p-4 sm:grid-cols-3 sm:items-end">
                    <x-form.select name="extra_material_id" label="Bahan di luar BOM" tip="Bahan yang tidak ada di BOM tetapi dipakai untuk SPK ini (mis. kain pengganti). Akan ditambahkan ke daftar bahan SPK." :options="$materials->mapWithKeys(fn ($m) => [$m->id => $m->display_name.' (stok '.qty($m->stock).' '.$m->unit?->code.')'])" placeholder="Pilih bahan (opsional)" class="sm:col-span-2" />
                    <x-form.input name="extra_quantity" type="number" min="0" step="0.01" label="Jumlah" />
                </div>
            @endif
        </x-card>

        <div class="flex justify-end gap-2">
            <a href="{{ route('production-orders.show', $order) }}" class="btn-secondary">Batal</a>
            <button type="submit" class="btn-primary">Simpan</button>
        </div>
    </form>
</x-layouts.app>
