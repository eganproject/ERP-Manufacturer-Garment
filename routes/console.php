<?php

use App\Services\Accounting\LedgerRebuilder;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('ledger:rebuild', function (LedgerRebuilder $rebuilder) {
    $count = $rebuilder->rebuild();
    $this->info("Jurnal otomatis dibangun ulang: {$count} jurnal.");
})->purpose('Bangun ulang jurnal otomatis dari seluruh transaksi yang tersimpan');
