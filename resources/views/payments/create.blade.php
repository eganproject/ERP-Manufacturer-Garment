@php
    $isReceipt = $type === \App\Enums\PaymentType::Receipt;
    $createRoute = $isReceipt ? 'receipts.create' : 'disbursements.create';
    $docRoute = $isReceipt ? 'invoices.show' : 'bills.show';
    $rows = $documents->map(fn ($d) => [
        'id' => $d->id,
        'outstanding' => $d->outstanding(),
        'amount' => (float) old("allocations.{$d->id}", $preselect === $d->id ? $d->outstanding() : 0) ?: '',
    ])->values();
@endphp
<x-layouts.app :title="$isReceipt ? 'Catat Penerimaan Pelanggan' : 'Catat Pembayaran Supplier'">
    <x-guide title="Cara mencatat pembayaran" :steps="['Pilih pihak yang membayar/dibayar, lalu klik **Tampilkan Tagihan** untuk melihat dokumen yang belum lunas.', 'Isi tanggal, metode dan rekening kas/bank tempat uang masuk atau keluar.', 'Isi **Dibayar Sekarang** per dokumen. Satu pembayaran boleh melunasi beberapa dokumen sekaligus atau hanya sebagian.', 'Status dokumen otomatis menjadi Sebagian/Lunas dan saldo kas/bank ikut berubah.']"></x-guide>
    <x-card :title="'1. Pilih '.$type->partyLabel()" class="mb-6">
        <form method="GET" action="{{ route($createRoute) }}" class="flex flex-wrap items-end gap-2">
            <div class="w-full sm:w-96">
                <x-form.select name="party_id" :value="$party?->id" :placeholder="'Pilih '.strtolower($type->partyLabel()).'...'" data-select
                    :options="$parties->pluck('name', 'id')" />
            </div>
            <button type="submit" class="btn-secondary">Tampilkan Tagihan</button>
        </form>
    </x-card>

    @if ($party)
        @if ($documents->isEmpty())
            <x-card><p class="text-sm text-gray-500">{{ $party->name }} tidak memiliki {{ $isReceipt ? 'invoice' : 'tagihan' }} yang belum lunas.</p></x-card>
        @else
            <form method="POST" action="{{ route('payments.store') }}" class="space-y-6"
                  x-data="{ rows: @js($rows), get total() { return this.rows.reduce((s, r) => s + (Number(r.amount) || 0), 0) } }"
                  data-confirm="Pembayaran akan dicatat dan sisa tagihan berkurang." data-confirm-icon="question" data-confirm-button="Ya, simpan">
                @csrf
                <input type="hidden" name="type" value="{{ $type->value }}">
                <input type="hidden" name="party_id" value="{{ $party->id }}">

                <x-card title="2. Detail Pembayaran">
                    <div class="grid grid-cols-1 gap-4 md:grid-cols-5">
                        <x-form.input name="payment_date" label="Tanggal Bayar" tip="Tanggal uang benar-benar diterima/dikeluarkan (sesuai mutasi rekening). Dipakai di laporan kas dan jurnal." :value="now()" required data-date />
                        <x-form.select name="method" label="Metode" :options="$methods" tip="Cara pembayaran. Bila rekening kas/bank dikosongkan, Tunai dicatat ke Kas dan metode lain ke Bank." value="transfer" :placeholder="false" required />
                        <x-form.cash-account />
                        <x-form.input name="reference" label="No. Referensi" tip="Nomor bukti transfer, giro atau cek agar mudah dilacak saat rekonsiliasi bank (opsional)." placeholder="No. transfer / giro / cek" />
                        <div>
                            <span class="form-label">Total Dibayar</span>
                            <p class="py-2 text-lg font-semibold text-gray-900" x-text="'Rp ' + ERP.formatNumber(total)"></p>
                        </div>
                        <x-form.textarea name="notes" label="Catatan" rows="2" class="md:col-span-5" />
                    </div>
                </x-card>

                <x-card :title="'3. Alokasi ke '.($isReceipt ? 'Invoice' : 'Tagihan')" :padding="false">
                    <x-slot:header>
                        <button type="button" class="btn-secondary py-1.5" @click="rows.forEach(r => r.amount = r.outstanding)">Lunasi semua</button>
                    </x-slot:header>
                    <div class="overflow-x-auto">
                        <table class="table-base">
                            <thead><tr><th>No. Dokumen</th><th>Tanggal</th><th>Jatuh Tempo</th><th class="text-right">Total</th><th class="text-right">Sisa</th><th class="w-52">Dibayar Sekarang <x-tip title="Dibayar sekarang" text="Nominal yang dialokasikan ke dokumen ini. Maksimal sebesar sisa tagihan. Kosongkan untuk dokumen yang belum dibayar." /></th></tr></thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach ($documents as $idx => $doc)
                                    <tr>
                                        <td>
                                            <a href="{{ route($docRoute, $doc) }}" target="_blank" class="font-medium text-indigo-600 hover:underline">{{ $doc->number }}</a>
                                            @if (! $isReceipt && $doc->supplier_invoice_number)<div class="text-xs text-gray-500">Faktur {{ $doc->supplier_invoice_number }}</div>@endif
                                        </td>
                                        <td>{{ tanggal($isReceipt ? $doc->invoice_date : $doc->bill_date) }}</td>
                                        <td @class(['text-red-600 font-medium' => $doc->isOverdue()])>{{ tanggal($doc->due_date) }}</td>
                                        <td class="text-right whitespace-nowrap">{{ rupiah($doc->total) }}</td>
                                        <td class="text-right whitespace-nowrap font-medium">{{ rupiah($doc->outstanding()) }}</td>
                                        <td>
                                            <div class="flex items-center gap-1">
                                                <input type="number" step="any" min="0" max="{{ $doc->outstanding() }}" name="allocations[{{ $doc->id }}]" x-model.number="rows[{{ $idx }}].amount" class="form-control text-right">
                                                <button type="button" class="rounded px-2 py-1 text-xs text-indigo-600 hover:bg-indigo-50" @click="rows[{{ $idx }}].amount = rows[{{ $idx }}].outstanding" title="Lunasi">Lunas</button>
                                            </div>
                                            @error('allocations.'.$doc->id)<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-card>

                <div class="flex justify-end gap-2">
                    <a href="{{ route($isReceipt ? 'receipts.index' : 'disbursements.index') }}" class="btn-secondary">Batal</a>
                    <button type="submit" class="btn-primary">Simpan Pembayaran</button>
                </div>
            </form>
        @endif
    @endif
</x-layouts.app>
