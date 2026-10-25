@props(['name' => 'cash_account_id', 'label' => 'Rekening Kas/Bank', 'value' => null])

{{-- Pilihan rekening kas & bank; kosong = akun default sesuai metode bayar (Tunai → Kas, lainnya → Bank) --}}
<x-form.select :name="$name" :label="$label" :options="\App\Models\Account::cashOptions()" :value="$value"
               placeholder="Otomatis sesuai metode" {{ $attributes }} />
