<?php

namespace App\Services\Reports;

use App\Enums\InvoiceStatus;
use App\Enums\MarketplaceOrderStatus;
use App\Enums\MarketplaceSettlementStatus;
use App\Enums\PayrollStatus;
use App\Enums\ProductionOrderStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\QuotationStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\SubcontractStatus;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\MarketplaceOrder;
use App\Models\MarketplaceSettlement;
use App\Models\Material;
use App\Models\Payroll;
use App\Models\ProductionOrder;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\SubcontractOrder;
use App\Models\User;
use App\Services\DepreciationService;
use App\Services\VatService;
use Illuminate\Support\Collection;

/**
 * Daftar pekerjaan berikutnya di alur ERP (per hak akses pengguna), ditampilkan di dashboard.
 */
class WorkQueue
{
    /**
     * @return Collection<int, array{label: string, count: int, url: string, hint: string, tone: string}>
     */
    public function for(User $user): Collection
    {
        $tasks = [];
        $add = function (string $ability, string $label, callable $count, string $url, string $hint, string $tone = 'amber') use ($user, &$tasks) {
            if ($user->can($ability) && ($n = (int) $count()) > 0) {
                $tasks[] = compact('label', 'url', 'hint', 'tone') + ['count' => $n];
            }
        };

        // Penjualan
        $add('sales.manage', 'Penawaran menunggu jawaban pelanggan', fn () => Quotation::active()->where('status', QuotationStatus::Sent)->count(),
            route('quotations.index', ['status' => 'active']), 'Follow up pelanggan, jadikan SO bila setuju', 'blue');
        $add('sales.manage', 'Penawaran kedaluwarsa', fn () => Quotation::expired()->count(),
            route('quotations.index', ['status' => 'expired']), 'Buat revisi atau batalkan', 'gray');
        $add('sales.manage', 'SO draft belum dikonfirmasi', fn () => SalesOrder::where('status', SalesOrderStatus::Draft)->count(),
            route('sales-orders.index', ['status' => SalesOrderStatus::Draft->value]), 'Konfirmasi agar bisa diproduksi & dikirim', 'gray');
        $add('production.manage', 'SO terbuka belum dibuatkan SPK', fn () => SalesOrder::whereIn('status', SalesOrderStatus::open())
            ->whereDoesntHave('productionOrders', fn ($q) => $q->where('status', '!=', ProductionOrderStatus::Cancelled))->count(),
            route('sales-orders.index', ['status' => 'open']), 'Buat SPK dari halaman SO (atau cek MRP)');
        $add('sales.deliver', 'SO menunggu pengiriman', fn () => SalesOrder::whereIn('status', SalesOrderStatus::open())->count(),
            route('sales-orders.index', ['status' => 'open']), 'Kirim barang jadi & cetak surat jalan', 'blue');
        $add('receivables.manage', 'SO terkirim belum diinvoice', fn () => $this->openDocuments(SalesOrder::class, 'delivered_quantity', 'invoiced_quantity')
            ->filter->canInvoice()->count(), route('invoices.create'), 'Buat invoice dari barang yang sudah dikirim');
        $add('receivables.view', 'Invoice lewat jatuh tempo', fn () => Invoice::overdue()->count(),
            route('invoices.index', ['status' => 'overdue']), 'Tagih pelanggan / catat penerimaan', 'red');

        $add('tax.manage', 'Faktur pajak keluaran belum diisi', fn () => Invoice::where('status', '!=', InvoiceStatus::Cancelled)
            ->where('tax_amount', '>', 0)->whereNull('tax_invoice_number')->count(),
            route('reports.vat'), 'Isi nomor faktur pajak di halaman invoice', 'gray');
        $add('tax.manage', 'Masa PPN belum ditutup', fn () => $this->vatPeriodPending(),
            route('reports.vat'), 'Tutup masa PPN bulan lalu & setor kurang bayar', 'gray');

        // Marketplace (TikTok Shop & Shopee)
        $add('marketplace.manage', 'Pesanan marketplace perlu dikirim', fn () => MarketplaceOrder::where('status', MarketplaceOrderStatus::ToShip)->count(),
            route('marketplace-orders.index', ['status' => MarketplaceOrderStatus::ToShip->value]), 'Kemas & kirim, lalu impor ulang file pesanan / tandai dikirim', 'blue');
        $add('marketplace.manage', 'SKU marketplace belum dipetakan', fn () => MarketplaceOrder::whereNull('shipped_at')->where('status', '!=', MarketplaceOrderStatus::Cancelled)
            ->whereHas('items', fn ($q) => $q->whereNull('product_variant_id'))->count(),
            route('marketplace-mappings.index'), 'Petakan SKU toko ke varian ERP agar stok bisa dikirim', 'red');
        $add('marketplace.manage', 'Retur marketplace menunggu barang', fn () => MarketplaceOrder::where('status', MarketplaceOrderStatus::ReturnRequested)->count(),
            route('marketplace-orders.index', ['status' => MarketplaceOrderStatus::ReturnRequested->value]), 'Catat barang retur yang diterima gudang');
        $add('marketplace.manage', 'Retur marketplace bermasalah belum dibanding', fn () => MarketplaceOrder::whereNotNull('returned_at')->whereNull('claim_status')
            ->where(fn ($q) => $q->where('returned_lost_cost', '>', 0)->orWhereIn('return_condition', ['wrong_item', 'empty', 'not_received']))->count(),
            route('marketplace-orders.index', ['status' => 'claimable']), 'Ajukan banding ke platform (batas waktu banding biasanya beberapa hari)', 'red');
        $add('marketplace.manage', 'Banding marketplace menunggu keputusan', fn () => MarketplaceOrder::where('claim_status', 'open')->count(),
            route('marketplace-orders.index', ['status' => 'claim_open']), 'Pantau di Seller Center & catat hasilnya', 'gray');
        $add('marketplace.settle', 'Pencairan marketplace belum diposting', fn () => MarketplaceSettlement::where('status', MarketplaceSettlementStatus::Draft)->count(),
            route('marketplace-settlements.index', ['status' => MarketplaceSettlementStatus::Draft->value]), 'Periksa & posting agar piutang dan biaya platform tercatat');
        $add('marketplace.settle', 'Pesanan marketplace selesai > 14 hari belum cair', fn () => MarketplaceOrder::whereIn('status', [MarketplaceOrderStatus::Completed, MarketplaceOrderStatus::Returned])
            ->where('completed_at', '<', today()->subDays(14))->whereRaw('ROUND(total - returned_total + (CASE WHEN claim_status = \'won\' THEN claim_amount ELSE 0 END) - settled_receivable, 2) > 0')->count(),
            route('marketplace-orders.index', ['status' => 'unsettled']), 'Impor laporan penghasilan / cek dana tertahan di Seller Center', 'gray');

        // Pembelian
        $add('purchase.approve', 'PO menunggu persetujuan', fn () => PurchaseOrder::where('status', PurchaseOrderStatus::PendingApproval)->count(),
            route('purchase-orders.index', ['status' => PurchaseOrderStatus::PendingApproval->value]), 'Setujui atau tolak PO bernilai besar');
        $add('purchase.manage', 'PO draft belum dipesan', fn () => PurchaseOrder::where('status', PurchaseOrderStatus::Draft)->count(),
            route('purchase-orders.index', ['status' => PurchaseOrderStatus::Draft->value]), 'Periksa lalu tandai dipesan / ajukan persetujuan', 'gray');
        $add('mrp.view', 'Bahan di bawah stok minimum', fn () => Material::where('is_active', true)->whereColumn('stock', '<', 'min_stock')->count(),
            route('mrp.index'), 'Hitung kebutuhan & buat PO dari MRP');
        $add('purchase.receive', 'PO menunggu barang datang', fn () => PurchaseOrder::whereIn('status', [PurchaseOrderStatus::Ordered, PurchaseOrderStatus::Partial])->count(),
            route('purchase-orders.index', ['status' => 'open']), 'Catat penerimaan barang saat datang', 'blue');
        $add('payables.manage', 'Penerimaan barang belum ditagih', fn () => $this->openDocuments(PurchaseOrder::class, 'received_quantity', 'billed_quantity')
            ->filter->canBill()->count(), route('bills.create'), 'Catat tagihan supplier dari barang diterima');
        $add('payables.manage', 'Hasil maklon belum ditagih', fn () => SubcontractOrder::where('status', '!=', SubcontractStatus::Cancelled)
            ->whereHas('receipts', fn ($q) => $q->whereNull('bill_id')->where('amount', '>', 0))->count(),
            route('bills.create'), 'Catat tagihan ongkos jasa vendor maklon');

        // Produksi
        $add('production.manage', 'SPK draft belum dimulai', fn () => ProductionOrder::where('status', ProductionOrderStatus::Draft)->count(),
            route('production-orders.index', ['status' => ProductionOrderStatus::Draft->value]), 'Buat order maklon dulu bila perlu, lalu mulai produksi', 'gray');
        $add('production.manage', 'SPK sedang berjalan', fn () => ProductionOrder::where('status', ProductionOrderStatus::InProgress)->count(),
            route('production-orders.index', ['status' => ProductionOrderStatus::InProgress->value]), 'Perbarui tahapan & input hasil kerja harian', 'blue');
        $add('subcontract.transfer', 'Order maklon berjalan', fn () => SubcontractOrder::where('status', SubcontractStatus::Open)->count(),
            route('subcontract-orders.index', ['status' => SubcontractStatus::Open->value]), 'Kirim bahan, terima hasil, lalu tutup order', 'blue');

        // Upah borongan
        $add('payroll.pay', 'Penggajian menunggu persetujuan', fn () => Payroll::where('status', PayrollStatus::Draft)->count(),
            route('payrolls.index', ['status' => PayrollStatus::Draft->value]), 'Periksa bonus & potongan lalu setujui');
        $add('payroll.pay', 'Penggajian siap dibayar', fn () => Payroll::where('status', PayrollStatus::Approved)->count(),
            route('payrolls.index', ['status' => PayrollStatus::Approved->value]), 'Bayar upah & cetak slip');

        // Persediaan & aset tetap
        $add('defects.manage', 'Barang cacat menunggu diproses', fn () => ProductVariant::where('defect_stock', '>', 0)->count(),
            route('defects.index'), 'Perbaiki, kembalikan ke stok jual, atau musnahkan');
        $add('assets.manage', 'Penyusutan aset belum diposting', fn () => $this->pendingDepreciation(),
            route('depreciation-runs.index'), 'Posting penyusutan s.d. bulan lalu', 'gray');

        return collect($tasks);
    }

    /** 1 bila masa PPN bulan lalu ada transaksi ber-PPN dan bisa (belum) ditutup. */
    private function vatPeriodPending(): int
    {
        $month = today()->startOfMonth()->subMonthNoOverflow();
        $range = [$month->toDateString(), $month->copy()->endOfMonth()->format('Y-m-d 23:59:59')];
        $taxed = Invoice::where('status', '!=', InvoiceStatus::Cancelled)->where('tax_amount', '>', 0)->whereBetween('invoice_date', $range)->exists()
            || Bill::where('status', '!=', InvoiceStatus::Cancelled)->where('tax_amount', '>', 0)->whereBetween('bill_date', $range)->exists();

        return $taxed && app(VatService::class)->closeBlocker($month) === null ? 1 : 0;
    }

    /** Jumlah aset aktif yang penyusutannya belum diposting s.d. bulan lalu. */
    private function pendingDepreciation(): int
    {
        $lastMonth = today()->startOfMonth()->subMonthNoOverflow();

        return app(DepreciationService::class)->preview($lastMonth)->count();
    }

    /**
     * Dokumen (SO/PO) yang masih punya qty diproses tapi belum difakturkan; disaring lagi lewat canInvoice/canBill.
     */
    private function openDocuments(string $model, string $doneColumn, string $billedColumn): Collection
    {
        return $model::with('items')
            ->whereNotIn('status', ['draft', 'cancelled'])
            ->whereHas('items', fn ($q) => $q->whereColumn($doneColumn, '>', $billedColumn))
            ->get();
    }
}
