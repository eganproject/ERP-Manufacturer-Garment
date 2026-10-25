<?php

namespace App\Services\Reports;

use App\Enums\PayrollStatus;
use App\Enums\ProductionOrderStatus;
use App\Enums\PurchaseOrderStatus;
use App\Enums\SalesOrderStatus;
use App\Enums\SubcontractStatus;
use App\Models\Invoice;
use App\Models\Material;
use App\Models\Payroll;
use App\Models\ProductionOrder;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\SubcontractOrder;
use App\Models\User;
use App\Services\DepreciationService;
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
