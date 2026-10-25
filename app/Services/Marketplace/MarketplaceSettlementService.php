<?php

namespace App\Services\Marketplace;

use App\Enums\MarketplaceSettlementStatus;
use App\Models\ActivityLog;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceSettlement;
use App\Models\MarketplaceStore;
use App\Services\Accounting\AutoJournal;
use App\Services\DocumentNumber;
use App\Support\SpreadsheetReader;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pencairan dana marketplace (PCD-). Laporan penghasilan / settlement dari Seller Center diimpor sebagai
 * draft (dicocokkan ke pesanan), diperiksa, lalu diposting:
 * Dr Saldo penjual (dana bersih) + Voucher penjual + Biaya marketplace + Ongkir ditanggung penjual
 * Cr Piutang marketplace (sisa piutang pesanan); selisihnya ke akun selisih pencairan.
 */
class MarketplaceSettlementService
{
    /** Alias judul kolom laporan penghasilan / settlement (ternormalisasi). Field biaya boleh dari beberapa kolom (dijumlah). */
    public const COLUMNS = [
        'order_number' => ['nopesanan', 'nomorpesanan', 'idpesanan', 'orderid', 'orderadjustmentid', 'ordersn', 'statementorderid'],
        'description' => ['keterangan', 'jenis', 'type', 'tipe', 'jenistransaksi', 'deskripsi', 'description'],
        'date' => ['tanggaldanadilepaskan', 'waktudanadilepaskan', 'tanggalpencairan', 'ordersettledtime', 'settledtime', 'settlementtime', 'tanggalpenyelesaian'],
        'original_price' => ['hargaaslproduk', 'hargaasliproduk'],
        'product_discount' => ['totaldiskonproduk', 'diskonproduk'],
        'order_amount' => ['nilaipesanan', 'totalhargaproduk', 'hargaproduk', 'subtotalaftersellerdiscounts', 'subtotalafterdiscount', 'pendapatanpesanan', 'totalrevenue'],
    ];

    /** Field biaya: daftar kolom yang dijumlahkan (nilai absolut, sebagai potongan). */
    public const FEE_COLUMNS = [
        'voucher' => ['voucherpenjual', 'voucherditanggungpenjual', 'voucherdisponsoriolehpenjual', 'voucherdisponsorolehpenjual', 'sellervoucher', 'sellercofundedvoucherdiscount', 'kodevoucherpenjual'],
        'commission' => ['komisi', 'biayaadministrasi', 'biayaadmin', 'komisiplatform', 'commissionfee', 'tiktokshopcommissionfee', 'platformcommissionfee', 'platformcommission'],
        'service_fee' => ['biayalayanan', 'biayalayanantermasukppn', 'servicefee', 'voucherxtraservicefee', 'freeshippingxtraservicefee', 'cashbackxtraservicefee', 'liveservicefee', 'biayaprogram'],
        'transaction_fee' => ['biayatransaksi', 'biayaprosespesanan', 'biayapembayaran', 'transactionfee', 'paymentfee', 'orderprocessingfee'],
        'shipping_fee' => ['ongkirditanggungpenjual', 'biayapengiriman', 'sellershippingfee', 'actualshippingfee', 'shippingfeesubsidyseller'],
        'other_fee' => ['biayalain', 'komisiaffiliate', 'komisiams', 'affiliatecommission', 'affiliatepartnercommission', 'affiliateadscommission', 'biayaiklan'],
    ];

    /** Komponen ongkir Shopee: diteruskan ke jasa kirim − dibayar pembeli − subsidi. */
    public const SHIPPING_PARTS = [
        'cost' => ['ongkiryangditeruskanolehshopeekejasakirim', 'ongkosyangditeruskankejasakirim', 'ongkirditeruskankejasakirim'],
        'buyer' => ['ongkirdibayarpembeli', 'ongkoskirimdibayarolehpembeli'],
        'rebate' => ['gratisongkirdarishopee', 'diskonongkirditanggungjasakirim', 'subsidiongkir'],
    ];

    public const ADJUSTMENT_COLUMNS = ['penyesuaian', 'adjustmentamount', 'kompensasi', 'refundamount'];

    public const NET_COLUMNS = ['totalpenghasilan', 'totalpencairan', 'danadilepaskan', 'totalsettlementamount', 'settlementamount', 'totaldanadilepaskan', 'jumlahdilepaskan'];

    public const TEMPLATE = ['No. Pesanan', 'Tanggal Dana Dilepaskan', 'Nilai Pesanan', 'Voucher Penjual', 'Komisi', 'Biaya Layanan', 'Biaya Transaksi',
        'Ongkir Ditanggung Penjual', 'Biaya Lain', 'Penyesuaian', 'Total Penghasilan', 'Keterangan'];

    public function __construct(private AutoJournal $journal) {}

    /**
     * Baca file laporan penghasilan dan buat draft pencairan.
     *
     * @param  array{date?: string|null, notes?: string|null}  $data
     */
    public function importFile(MarketplaceStore $store, string $path, ?string $originalName = null, array $data = []): MarketplaceSettlement
    {
        $table = SpreadsheetReader::withHeaders(SpreadsheetReader::read($path, $originalName), array_merge(self::COLUMNS['order_number'], self::NET_COLUMNS));
        $lines = $this->parse($store, $table['headers'], $table['rows']);

        if (empty($lines)) {
            throw ValidationException::withMessages(['file' => 'Tidak ada baris pencairan yang terbaca dari file ini.']);
        }

        $date = ! empty($data['date']) ? Carbon::parse($data['date']) : (collect($lines)->pluck('date')->filter()->max() ?? today());

        return $this->createDraft($store, $date, $lines, $data['notes'] ?? null, $originalName);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    public function createDraft(MarketplaceStore $store, \DateTimeInterface $date, array $lines, ?string $notes = null, ?string $sourceFile = null): MarketplaceSettlement
    {
        return DB::transaction(function () use ($store, $date, $lines, $notes, $sourceFile) {
            $settlement = MarketplaceSettlement::create([
                'number' => DocumentNumber::generate(MarketplaceSettlement::class, 'PCD', $date),
                'marketplace_store_id' => $store->id,
                'date' => $date,
                'status' => MarketplaceSettlementStatus::Draft,
                'source_file' => $sourceFile ? mb_substr($sourceFile, 0, 255) : null,
                'notes' => $notes,
                'created_by' => auth()->id(),
            ]);

            $orders = MarketplaceOrder::where('marketplace_store_id', $store->id)
                ->whereIn('order_number', collect($lines)->pluck('order_number')->filter()->unique())
                ->pluck('id', 'order_number');

            foreach ($lines as $line) {
                unset($line['date']);
                $settlement->lines()->create($line + ['marketplace_order_id' => $line['order_number'] ? ($orders[$line['order_number']] ?? null) : null]);
            }

            $this->refreshTotals($settlement);
            ActivityLog::record('created', "Impor pencairan dana {$store->display_name}: ".count($lines).' baris, dana '.rupiah($settlement->net_amount), $settlement);

            return $settlement;
        });
    }

    /** Cocokkan ulang baris draft yang belum terhubung ke pesanan (mis. setelah pesanan diimpor). */
    public function rematch(MarketplaceSettlement $settlement): int
    {
        $this->ensureDraft($settlement);
        $matched = 0;

        foreach ($settlement->lines()->whereNull('marketplace_order_id')->whereNotNull('order_number')->get() as $line) {
            $id = MarketplaceOrder::where('marketplace_store_id', $settlement->marketplace_store_id)->where('order_number', $line->order_number)->value('id');
            if ($id) {
                $line->update(['marketplace_order_id' => $id]);
                $matched++;
            }
        }

        return $matched;
    }

    /**
     * Posting: setiap baris pesanan menutup sisa piutang pesanan tsb; pesanan harus sudah dikirim.
     */
    public function post(MarketplaceSettlement $settlement): void
    {
        $this->ensureDraft($settlement);
        $settlement->load('lines.order', 'store');

        $notShipped = $settlement->lines->filter(fn ($l) => $l->order && ! $l->order->isShipped());
        if ($notShipped->isNotEmpty()) {
            throw ValidationException::withMessages(['lines' => 'Pesanan berikut belum dikirim di ERP: '.$notShipped->pluck('order_number')->take(10)->implode(', ').'. Impor ulang file pesanan terbaru (atau kirim pesanannya) sebelum posting.']);
        }

        DB::transaction(function () use ($settlement) {
            foreach ($settlement->lines as $line) {
                $receivable = 0.0;

                if ($line->order) {
                    $order = MarketplaceOrder::lockForUpdate()->find($line->order->id);
                    $receivable = $order->outstandingReceivable();
                    $order->update([
                        'settled_receivable' => round((float) $order->settled_receivable + $receivable, 2),
                        'settled_net' => round((float) $order->settled_net + (float) $line->net_amount, 2),
                    ]);
                }

                $line->update(['receivable' => $receivable]);
            }

            $this->refreshTotals($settlement);
            $settlement->update(['status' => MarketplaceSettlementStatus::Posted, 'posted_at' => now(), 'posted_by' => auth()->id()]);
            $this->journal->marketplaceSettlement($settlement->fresh('store'));

            ActivityLog::record('posted', 'Memposting pencairan dana: dana bersih '.rupiah($settlement->net_amount).', piutang ditutup '.rupiah($settlement->receivable), $settlement);
        });
    }

    /** Batalkan pencairan yang sudah diposting (jurnal dibalik, piutang pesanan dibuka kembali). */
    public function cancel(MarketplaceSettlement $settlement, string $reason): void
    {
        if (! $settlement->isPosted()) {
            throw ValidationException::withMessages(['status' => 'Hanya pencairan yang sudah diposting yang bisa dibatalkan.']);
        }

        DB::transaction(function () use ($settlement, $reason) {
            foreach ($settlement->lines()->whereNotNull('marketplace_order_id')->get() as $line) {
                $order = MarketplaceOrder::lockForUpdate()->find($line->marketplace_order_id);
                $order?->update([
                    'settled_receivable' => round((float) $order->settled_receivable - (float) $line->receivable, 2),
                    'settled_net' => round((float) $order->settled_net - (float) $line->net_amount, 2),
                ]);
            }

            $this->journal->reverse($settlement, $reason);
            $settlement->update(['status' => MarketplaceSettlementStatus::Cancelled, 'cancel_reason' => $reason]);
            ActivityLog::record('cancelled', "Membatalkan pencairan dana: {$reason}", $settlement);
        });
    }

    public function delete(MarketplaceSettlement $settlement): void
    {
        $this->ensureDraft($settlement);
        $settlement->delete();
    }

    /**
     * Ubah baris file menjadi baris pencairan. Dana bersih dari file dipakai apa adanya; bila komponen tidak
     * menjumlah ke dana bersih, sisanya dicatat sebagai penyesuaian.
     *
     * @param  list<string>  $headers
     * @param  list<array<string, string>>  $rows
     * @return list<array<string, mixed>>
     */
    public function parse(MarketplaceStore $store, array $headers, array $rows): array
    {
        $map = MarketplaceImporter::columnMap($headers, self::COLUMNS);
        $pick = fn (array $aliases) => array_values(array_intersect($aliases, $headers));
        $fees = collect(self::FEE_COLUMNS)->map($pick)->all();
        $shipping = collect(self::SHIPPING_PARTS)->map($pick)->all();
        $adjustments = $pick(self::ADJUSTMENT_COLUMNS);
        $netColumn = $pick(self::NET_COLUMNS)[0] ?? null;
        $slash = $store->platform->slashDateFormat();

        $num = fn (array $row, ?string $col) => $col ? SpreadsheetReader::number($row[$col] ?? '') : 0.0;
        $sumAbs = fn (array $row, array $cols) => round(array_sum(array_map(fn ($c) => abs($num($row, $c)), $cols)), 2);

        $lines = [];
        foreach ($rows as $row) {
            $number = ltrim(trim((string) (isset($map['order_number']) ? ($row[$map['order_number']] ?? '') : '')), "'");
            $hasNet = $netColumn && trim((string) ($row[$netColumn] ?? '')) !== '';

            if ($number === '' && ! $hasNet) {
                continue;
            }
            // Baris total / keterangan tanpa angka
            if ($number !== '' && ! preg_match('/[0-9]/', $number)) {
                continue;
            }

            $orderAmount = isset($map['order_amount']) ? abs($num($row, $map['order_amount'])) : 0.0;
            if (isset($map['original_price'])) {
                $orderAmount = round(abs($num($row, $map['original_price'])) - abs($num($row, $map['product_discount'] ?? null)), 2);
            }

            $line = [
                'order_number' => $number !== '' ? mb_substr($number, 0, 60) : null,
                'description' => isset($map['description']) ? (mb_substr(trim((string) ($row[$map['description']] ?? '')), 0, 255) ?: null) : null,
                'date' => isset($map['date']) ? SpreadsheetReader::date($row[$map['date']] ?? '', $slash) : null,
                'order_amount' => $orderAmount,
            ];

            foreach ($fees as $field => $cols) {
                $line[$field] = $sumAbs($row, $cols);
            }

            if ($shipping['cost'] && $line['shipping_fee'] == 0) {
                $line['shipping_fee'] = round($sumAbs($row, $shipping['cost']) - $sumAbs($row, $shipping['buyer']) - $sumAbs($row, $shipping['rebate']), 2);
            }

            $line['adjustment'] = round(array_sum(array_map(fn ($c) => $num($row, $c), $adjustments)), 2);
            $computed = round($line['order_amount'] - $line['voucher'] - $line['commission'] - $line['service_fee'] - $line['transaction_fee']
                - $line['shipping_fee'] - $line['other_fee'] + $line['adjustment'], 2);
            $line['net_amount'] = $hasNet ? round($num($row, $netColumn), 2) : $computed;
            // Komponen yang tidak terbaca → penyesuaian agar baris seimbang dengan dana yang benar-benar cair
            $line['adjustment'] = round($line['adjustment'] + $line['net_amount'] - $computed, 2);

            $lines[] = $line;
        }

        return $lines;
    }

    private function refreshTotals(MarketplaceSettlement $settlement): void
    {
        $lines = $settlement->lines()->get();

        $settlement->update([
            'order_amount' => round($lines->sum('order_amount'), 2),
            'voucher' => round($lines->sum('voucher'), 2),
            'fees' => round($lines->sum(fn ($l) => $l->fees()), 2),
            'shipping_fee' => round($lines->sum('shipping_fee'), 2),
            'adjustment' => round($lines->sum('adjustment'), 2),
            'net_amount' => round($lines->sum('net_amount'), 2),
            'receivable' => round($lines->sum('receivable'), 2),
        ]);
    }

    private function ensureDraft(MarketplaceSettlement $settlement): void
    {
        if (! $settlement->isDraft()) {
            throw ValidationException::withMessages(['status' => 'Pencairan ini sudah diposting / dibatalkan.']);
        }
    }
}
