<?php

namespace App\Services\Accounting;

use App\Enums\FixedAssetStatus;
use App\Enums\InvoiceStatus;
use App\Enums\JournalType;
use App\Enums\PaymentStatus;
use App\Enums\PayrollStatus;
use App\Enums\ProductionOrderStatus;
use App\Enums\ReturnStatus;
use App\Enums\StockMovementType;
use App\Enums\SubcontractStatus;
use App\Models\AccountingPeriod;
use App\Models\Bill;
use App\Models\CashTransaction;
use App\Models\DefectHandling;
use App\Models\Delivery;
use App\Models\DepreciationRun;
use App\Models\EmployeeAdvance;
use App\Models\FixedAsset;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\ProductionCost;
use App\Models\ProductionOrder;
use App\Models\PurchaseReturn;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Models\SubcontractOrder;
use App\Models\SubcontractTransfer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bangun ulang seluruh jurnal otomatis dari dokumen transaksi yang tersimpan (mis. untuk transaksi
 * yang dibuat sebelum modul akuntansi ada). Jurnal manual tidak disentuh. Dokumen yang dibatalkan
 * dilewati karena jurnal asal & pembaliknya saling meniadakan.
 */
class LedgerRebuilder
{
    public function __construct(private AutoJournal $auto, private JournalService $journals) {}

    /**
     * @return int jumlah jurnal otomatis yang terbentuk
     */
    public function rebuild(): int
    {
        if (AccountingPeriod::whereNotNull('closed_at')->exists()) {
            throw ValidationException::withMessages(['status' => 'Jurnal otomatis tidak dapat dibangun ulang karena sudah ada periode yang ditutup. Buka kembali semua periode terlebih dahulu.']);
        }

        return DB::transaction(function () {
            JournalEntry::where('type', JournalType::Auto)->whereNotNull('reversal_of_id')->delete();
            JournalEntry::where('type', JournalType::Auto)->delete();

            $this->journals->ignoreLock = true;

            try {
                GoodsReceipt::orderBy('receipt_date')->orderBy('id')->each(fn ($r) => $this->auto->goodsReceipt($r));
                Delivery::orderBy('delivery_date')->orderBy('id')->each(fn ($d) => $this->auto->delivery($d));
                Invoice::where('status', '!=', InvoiceStatus::Cancelled)->orderBy('invoice_date')->orderBy('id')->each(fn ($i) => $this->auto->invoice($i));
                Bill::where('status', '!=', InvoiceStatus::Cancelled)->orderBy('bill_date')->orderBy('id')->each(fn ($b) => $this->auto->bill($b));
                Payment::where('status', PaymentStatus::Posted)->orderBy('payment_date')->orderBy('id')->each(fn ($p) => $this->auto->payment($p));

                foreach ([SalesReturn::class, PurchaseReturn::class] as $model) {
                    $model::where('status', ReturnStatus::Posted)->orderBy('return_date')->orderBy('id')->each(function ($return) {
                        $return instanceof SalesReturn ? $this->auto->salesReturn($return) : $this->auto->purchaseReturn($return);

                        foreach ($return->applications()->with('payable')->orderBy('id')->get() as $application) {
                            $this->auto->creditApplication($return, $application);
                        }

                        if ((float) $return->refunded_amount > 0) {
                            $this->auto->returnRefund($return, (float) $return->refunded_amount, $return->refund_date ?? $return->return_date, $return->refund_method, $return->refund_account_id);
                        }
                    });
                }

                $active = [ProductionOrderStatus::InProgress, ProductionOrderStatus::Completed];
                ProductionOrder::whereIn('status', $active)->orderBy('id')->each(fn ($spk) => $this->auto->productionIssue($spk));
                ProductionCost::with('productionOrder', 'subcontractReceipt.subcontractOrder.supplier')
                    ->whereHas('productionOrder', fn ($q) => $q->whereIn('status', $active))
                    ->orderBy('date')->orderBy('id')
                    ->each(fn ($cost) => $this->auto->productionCost($cost));

                SubcontractTransfer::whereHas('subcontractOrder', fn ($q) => $q->where('status', '!=', SubcontractStatus::Cancelled))
                    ->orderBy('date')->orderBy('id')
                    ->each(fn ($t) => $this->auto->subcontractTransfer($t));

                EmployeeAdvance::where('status', ReturnStatus::Posted)->orderBy('date')->orderBy('id')->each(fn ($a) => $this->auto->employeeAdvance($a));
                Payroll::where('status', PayrollStatus::Paid)->orderBy('payment_date')->orderBy('id')->each(fn ($p) => $this->auto->payrollPayment($p));

                CashTransaction::where('status', PaymentStatus::Posted)->orderBy('date')->orderBy('id')->each(fn ($t) => $this->auto->cashTransaction($t));

                DefectHandling::where('status', ReturnStatus::Posted)->orderBy('date')->orderBy('id')->each(fn ($h) => $this->auto->defectHandling($h));

                // Aset tetap: perolehan → penyusutan → pelepasan
                FixedAsset::where('status', '!=', FixedAssetStatus::Cancelled)->orderBy('acquisition_date')->orderBy('id')->each(fn ($a) => $this->auto->fixedAssetAcquisition($a));
                DepreciationRun::where('status', ReturnStatus::Posted)->orderBy('period')->orderBy('id')->each(fn ($r) => $this->auto->depreciationRun($r));
                FixedAsset::where('status', FixedAssetStatus::Disposed)->orderBy('disposal_date')->orderBy('id')->each(fn ($a) => $this->auto->fixedAssetDisposal($a));

                StockMovement::where('type', StockMovementType::Adjustment)->orderBy('date')->orderBy('id')->each(fn ($m) => $this->auto->stockAdjustment($m));

                // Bergantung pada saldo rincian di atas, jadi diposting terakhir
                SubcontractOrder::where('status', SubcontractStatus::Closed)->orderBy('closed_at')->each(fn ($o) => $this->auto->subcontractClose($o));
                ProductionOrder::where('status', ProductionOrderStatus::Completed)->orderBy('completed_at')->each(fn ($spk) => $this->auto->productionComplete($spk));
            } finally {
                $this->journals->ignoreLock = false;
            }

            return JournalEntry::where('type', JournalType::Auto)->count();
        });
    }
}
