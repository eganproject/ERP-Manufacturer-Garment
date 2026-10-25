<?php

namespace App\Services;

use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Bill;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Laporan umur piutang / hutang per tanggal tertentu, dikelompokkan per pelanggan/supplier.
 */
class AgingReport
{
    public const BUCKETS = [
        'current' => 'Belum Jatuh Tempo',
        '1_30' => '1–30 hari',
        '31_60' => '31–60 hari',
        '61_90' => '61–90 hari',
        'over_90' => '> 90 hari',
    ];

    /**
     * @param  'receivables'|'payables'  $kind
     * @return array{rows: Collection, totals: array<string, float>, documents: Collection}
     */
    public function build(string $kind, Carbon $asOf, ?int $partyId = null): array
    {
        [$model, $dateColumn, $partyColumn, $partyRelation] = $kind === 'receivables'
            ? [Invoice::class, 'invoice_date', 'customer_id', 'customer']
            : [Bill::class, 'bill_date', 'supplier_id', 'supplier'];

        $asOf = $asOf->copy()->startOfDay();

        // Saldo per tanggal laporan: total dikurangi pembayaran & nota retur yang terjadi sampai tanggal itu
        $documents = $model::query()
            ->with($partyRelation)
            ->where('status', '!=', InvoiceStatus::Cancelled)
            ->whereDate($dateColumn, '<=', $asOf)
            ->when($partyId, fn ($q) => $q->where($partyColumn, $partyId))
            ->withSum(['allocations as paid_as_of' => fn ($q) => $q->whereHas('payment', fn ($p) => $p
                ->where('status', PaymentStatus::Posted)
                ->whereDate('payment_date', '<=', $asOf))], 'amount')
            ->withSum(['creditApplications as credited_as_of' => fn ($q) => $q->whereDate('applied_date', '<=', $asOf)], 'amount')
            ->orderBy('due_date')
            ->get()
            ->map(function ($doc) use ($asOf) {
                $doc->balance = round((float) $doc->total - (float) $doc->paid_as_of - (float) $doc->credited_as_of, 2);
                $doc->age = $doc->daysOverdue($asOf);
                $doc->bucket = self::bucketFor($doc->age);

                return $doc;
            })
            ->filter(fn ($doc) => $doc->balance > 0.009)
            ->values();

        $rows = $documents->groupBy($partyColumn)->map(function (Collection $docs) use ($partyRelation) {
            $row = ['party' => $docs->first()->{$partyRelation}, 'count' => $docs->count(), 'total' => round($docs->sum('balance'), 2)];

            foreach (array_keys(self::BUCKETS) as $bucket) {
                $row[$bucket] = round($docs->where('bucket', $bucket)->sum('balance'), 2);
            }

            return $row;
        })->sortByDesc('total')->values();

        $totals = ['total' => round($rows->sum('total'), 2)];

        foreach (array_keys(self::BUCKETS) as $bucket) {
            $totals[$bucket] = round($rows->sum($bucket), 2);
        }

        return ['rows' => $rows, 'totals' => $totals, 'documents' => $documents];
    }

    public static function bucketFor(int $daysOverdue): string
    {
        return match (true) {
            $daysOverdue <= 0 => 'current',
            $daysOverdue <= 30 => '1_30',
            $daysOverdue <= 60 => '31_60',
            $daysOverdue <= 90 => '61_90',
            default => 'over_90',
        };
    }
}
