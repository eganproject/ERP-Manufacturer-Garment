<x-layouts.app :title="$category->exists ? 'Ubah Kategori' : 'Tambah Kategori'">
    <x-card class="max-w-xl">
        <form method="POST" action="{{ $category->exists ? route('categories.update', $category) : route('categories.store') }}" class="space-y-4">
            @csrf
            @if ($category->exists) @method('PUT') @endif
            <x-form.input name="name" label="Nama Kategori" :value="$category->name" required placeholder="mis. Kain, Aksesoris, Kemeja" />
            <x-form.select name="type" label="Jenis" :options="\App\Models\Category::TYPES" :value="$category->type" required tip="Menentukan kategori ini dipakai untuk apa: Bahan Baku (kain, benang, kancing) atau Produk jadi (kemeja, kaos). Kategori hanya muncul di form yang sesuai jenisnya." :placeholder="false" />
            <x-form.actions :cancel="route('categories.index')" />
        </form>
    </x-card>
</x-layouts.app>
