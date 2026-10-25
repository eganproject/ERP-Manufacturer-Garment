<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\ReturnStatus;
use App\Models\ActivityLog;
use App\Models\Bill;
use App\Models\FixedAsset;
use App\Models\Invoice;
use App\Models\VatPeriod;
use App\Services\Accounting\AutoJournal;
use App\Services\Accounting\JournalService;
use App\Services\Reports\VatReport;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * PPN: nomor faktur pajak dan penutupan masa PPN.
 *
 * Tutup masa (PPN-, berurutan per bulan, jurnal tanggal akhir bulan):
 * Dr PPN keluaran / Cr PPN masukan / Cr PPN lebih bayar (kompensasi dipakai) dan selisihnya
 * Cr Hutang PPN (kurang bayar, disetor lewat kas keluar) atau Dr PPN lebih bayar (dikompensasikan).
 */
class VatService
{
    public function __construct(private AutoJournal $journal, private JournalService $journals) {}

    /**
     * Nomor faktur pajak: 13–20 digit (format lama 010.000-26.12345678 atau 17 digit Coretax).
     */
    public static function normalizeNumber(?string $number): ?string
    {
        $number = trim((string) $number);

        if ($number === '') {
            return null;
        }

        $digits = strlen(preg_replace('/\D/', '', $number));

        if (! preg_match('/^[0-9][0-9.\- ]*$/', $number) || $digits < 13 || $digits > 20) {
            throw ValidationException::withMessages(['tax_invoice_number' => 'Nomor faktur pajak tidak valid (13–20 digit, boleh memakai titik / tanda hubung).']);
        }

        return $number;
    }

    /** Isi / ubah nomor faktur pajak keluaran (invoice) atau masukan (tagihan supplier, aset tetap). */
    public function setTaxInvoice(Invoice|Bill|FixedAsset $doc, ?string $number, ?string $date = null): void
    {
        $number = self::normalizeNumber($number);

        if ($number && $doc instanceof Invoice && Invoice::where('tax_invoice_number', $number)
            ->where('id', '!=', $doc->id)->where('status', '!=', InvoiceStatus::Cancelled)->exists()) {
            throw ValidationException::withMessages(['tax_invoice_number' => "Nomor faktur pajak {$number} sudah dipakai invoice lain."]);
        }

        if ($number && $doc instanceof Bill && Bill::where('supplier_id', $doc->supplier_id)->where('tax_invoice_number', $number)
            ->where('id', '!=', $doc->id)->where('status', '!=', InvoiceStatus::Cancelled)->exists()) {
            throw ValidationException::withMessages(['tax_invoice_number' => "Nomor faktur pajak {$number} dari supplier ini sudah dicatat."]);
        }

        $attributes = ['tax_invoice_number' => $number];

        if (! $doc instanceof FixedAsset) {
            $attributes['tax_invoice_date'] = $number && $date ? Carbon::parse($date) : null;
        }

        DB::transaction(function () use ($doc, $attributes, $number) {
            $doc->update($attributes);
            ActivityLog::record('tax_invoice', $number ? "Mengisi no. faktur pajak {$number}" : 'Menghapus no. faktur pajak', $doc);
        });
    }

    /** Masa terakhir yang sudah ditutup. */
    public function lastClosedPeriod(): ?VatPeriod
    {
        return VatPeriod::posted()->orderByDesc('period')->orderByDesc('id')->first();
    }

    /** Lebih bayar masa sebelumnya yang bisa dikompensasikan ke $period. */
    public function compensationFor(Carbon $period): float
    {
        $previous = VatPeriod::posted()->whereDate('period', $period->copy()->startOfMonth()->subMonthNoOverflow())->latest('id')->first();

        return $previous ? $previous->overpaid() : 0.0;
    }

    /** Alasan masa belum bisa ditutup (null = bisa). */
    public function closeBlocker(Carbon $period): ?string
    {
        $period = $period->copy()->startOfMonth();

        if ($period->gte(today()->startOfMonth())) {
            return 'Masa pajak baru bisa ditutup setelah bulannya berakhir.';
        }

        $last = $this->lastClosedPeriod();

        if ($last && $period->lte($last->period)) {
            return 'Masa '.$last->periodLabel().' sudah ditutup. Masa PPN ditutup berurutan.';
        }

        if ($last && ! $period->equalTo($last->period->copy()->addMonthNoOverflow())) {
            return 'Tutup dulu masa '.$last->period->copy()->addMonthNoOverflow()->translatedFormat('F Y').' (masa PPN ditutup berurutan).';
        }

        return null;
    }

    public function close(Carbon $period, ?string $notes = null): VatPeriod
    {
        $period = $period->copy()->startOfMonth();

        if ($reason = $this->closeBlocker($period)) {
            throw ValidationException::withMessages(['period' => $reason]);
        }

        return DB::transaction(function () use ($period, $notes) {
            $totals = app(VatReport::class)->build($period)['totals'];
            $date = $period->copy()->endOfMonth()->startOfDay();

            $vat = VatPeriod::create([
                'number' => DocumentNumber::generate(VatPeriod::class, 'PPN', $date),
                'period' => $period,
                'date' => $date,
                'output_tax' => $totals['output'],
                'input_tax' => $totals['input'],
                'compensation' => $totals['compensation'],
                'net' => $totals['net'],
                'status' => ReturnStatus::Posted,
                'notes' => $notes,
                'created_by' => auth()->id(),
            ]);

            $this->journal->vatPeriod($vat);

            $result = $vat->net >= 0 ? 'kurang bayar '.rupiah($vat->payable()) : 'lebih bayar '.rupiah($vat->overpaid());
            ActivityLog::record('created', "Menutup masa PPN {$vat->periodLabel()}: {$result}", $vat);

            return $vat;
        });
    }

    /** Batalkan penutupan masa terakhir (jurnal dibalik). */
    public function cancel(VatPeriod $vat, string $reason): void
    {
        if ($vat->isCancelled()) {
            throw ValidationException::withMessages(['status' => 'Penutupan masa PPN ini sudah dibatalkan.']);
        }

        $last = $this->lastClosedPeriod();

        if ($last && $last->id !== $vat->id) {
            throw ValidationException::withMessages(['status' => "Batalkan dulu penutupan masa yang lebih baru ({$last->number})."]);
        }

        DB::transaction(function () use ($vat, $reason) {
            $this->journals->reverseSource($vat, null, $vat->date, "tutup masa PPN dibatalkan – {$reason}");
            $vat->update(['status' => ReturnStatus::Cancelled, 'cancel_reason' => $reason]);

            ActivityLog::record('cancelled', "Membatalkan penutupan masa PPN {$vat->periodLabel()}: {$reason}", $vat);
        });
    }
}
