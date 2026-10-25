<x-layouts.app title="Pengaturan Perusahaan">
    <x-card class="max-w-3xl" title="Profil Perusahaan (kop dokumen PDF)">
        <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data">
            @csrf
            @method('PUT')
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form.input name="company_name" label="Nama Perusahaan" :value="$company['name']" required class="sm:col-span-2" />
                <x-form.textarea name="company_address" label="Alamat" :value="$company['address']" rows="2" class="sm:col-span-2" />
                <x-form.input name="company_city" label="Kota" :value="$company['city']" help="Dipakai pada tanda tangan dokumen, mis. Bandung, 25 Okt 2026." />
                <x-form.input name="company_phone" label="Telepon" :value="$company['phone']" />
                <x-form.input name="company_email" type="email" label="Email" :value="$company['email']" />
                <x-form.input name="company_npwp" label="NPWP" :value="$company['npwp']" />
                <div class="sm:col-span-2">
                    <label for="f_logo" class="form-label">Logo (PNG/JPG, maks. 1 MB)</label>
                    <div class="flex items-center gap-4">
                        @if ($company['logo'])
                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($company['logo']) }}" alt="Logo" class="h-14 w-auto rounded border border-gray-200 bg-white p-1">
                        @endif
                        <input type="file" id="f_logo" name="logo" accept="image/png,image/jpeg" class="form-control">
                    </div>
                    @error('logo')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    @if ($company['logo'])
                        <x-form.checkbox name="remove_logo" label="Hapus logo" class="mt-2" />
                    @endif
                </div>
            </div>
            <x-form.actions />
        </form>
    </x-card>
</x-layouts.app>
