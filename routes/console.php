<?php

use App\Models\MarketplaceStore;
use App\Services\Accounting\LedgerRebuilder;
use App\Services\DepreciationService;
use App\Services\Marketplace\MarketplaceSyncService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Validation\ValidationException;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ledger:rebuild', function (LedgerRebuilder $rebuilder) {
    $count = $rebuilder->rebuild();
    $this->info("Jurnal otomatis dibangun ulang: {$count} jurnal.");
})->purpose('Bangun ulang jurnal otomatis dari seluruh transaksi yang tersimpan');

Artisan::command('assets:depreciate {period? : Bulan YYYY-MM, default bulan lalu}', function (DepreciationService $service) {
    $period = $this->argument('period')
        ? Carbon::createFromFormat('Y-m', $this->argument('period'))->startOfMonth()
        : today()->startOfMonth()->subMonthNoOverflow();

    if ($service->preview($period)->isEmpty()) {
        $this->info('Tidak ada penyusutan yang perlu diposting s.d. '.$period->translatedFormat('F Y').'.');

        return;
    }

    try {
        $run = DB::transaction(fn () => $service->run($period));
        $this->info("Penyusutan {$run->periodLabel()} diposting ({$run->number}): {$run->asset_count} aset, ".rupiah($run->total).'.');
    } catch (ValidationException $e) {
        // Mis. periode sudah ditutup: catat saja, posting manual dari menu Aset Tetap → Penyusutan
        $this->warn(collect($e->errors())->flatten()->implode(' '));
    }
})->purpose('Posting penyusutan aset tetap s.d. bulan tertentu (otomatis tiap tanggal 1 untuk bulan lalu)');

// Penyusutan bulan lalu diposting otomatis tiap awal bulan (butuh cron `php artisan schedule:run`)
Schedule::command('assets:depreciate')->monthlyOn(1, '01:00');

Artisan::command('marketplace:sync {store? : ID toko, kosong = semua toko ber-API aktif}', function (MarketplaceSyncService $service) {
    $stores = MarketplaceStore::active()->where('api_enabled', true)
        ->when($this->argument('store'), fn ($q, $id) => $q->whereKey($id))->get();

    foreach ($stores as $store) {
        try {
            $s = $service->sync($store);
            $this->info("{$store->display_name}: {$s['created']} baru, {$s['updated']} diperbarui, ".count($s['errors']).' gagal.');
        } catch (ValidationException $e) {
            $this->warn("{$store->display_name}: ".collect($e->errors())->flatten()->implode(' '));
        }
    }
})->purpose('Tarik pesanan TikTok Shop / Shopee lewat API resmi (opsional, bila kredensial toko diisi)');

// Sinkron pesanan marketplace ber-API tiap 30 menit (toko tanpa API tetap lewat impor file)
Schedule::command('marketplace:sync')->everyThirtyMinutes()->withoutOverlapping();
