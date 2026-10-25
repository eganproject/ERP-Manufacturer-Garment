@props(['action', 'title', 'text' => null, 'placeholder' => 'Alasan', 'button' => 'Ya, lanjutkan', 'danger' => true])

{{-- Tombol aksi yang meminta alasan lewat dialog sebelum form dikirim --}}
<form method="POST" action="{{ $action }}" class="inline" x-data x-ref="form">
    @csrf
    <input type="hidden" name="reason">
    <button type="button" {{ $attributes->merge(['class' => 'btn-secondary']) }}
            @click="Swal.fire({ title: @js($title), text: @js($text), input: 'text', inputPlaceholder: @js($placeholder), icon: @js($danger ? 'warning' : 'question'), showCancelButton: true, confirmButtonText: @js($button), cancelButtonText: 'Batal', confirmButtonColor: @js($danger ? '#dc2626' : '#4f46e5'), reverseButtons: true, inputValidator: v => !v && 'Alasan wajib diisi' }).then(r => { if (r.isConfirmed) { $refs.form.querySelector('[name=reason]').value = r.value; $refs.form.submit(); } })">{{ $slot }}</button>
</form>
