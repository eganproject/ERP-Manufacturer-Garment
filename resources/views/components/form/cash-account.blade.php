@props(['name' => 'cash_account_id', 'label' => 'Rekening Kas/Bank', 'value' => null, 'tip' => "Rekening tempat uang benar-benar keluar atau masuk. Saldo rekening ini di laporan kas akan berubah.\nKosongkan bila ingin otomatis: Tunai → Kas, metode lain → Bank."])

{{-- Pilihan rekening kas & bank; kosong = akun default sesuai metode bayar (Tunai → Kas, lainnya → Bank) --}}
<x-form.select :name="$name" :label="$label" :options="\App\Models\Account::cashOptions()" :value="$value"
               placeholder="Otomatis sesuai metode" :tip="$tip" {{ $attributes }} />
