<?php

namespace App\Services\Accounting;

use App\Enums\CashTransactionType;
use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Enums\ProductionCostType;
use App\Enums\StockMovementType;
use App\Enums\SubcontractTransferType;
use App\Models\Bill;
use App\Models\CashTransaction;
use App\Models\CreditApplication;
use App\Models\Delivery;
use App\Models\EmployeeAdvance;
use App\Models\GoodsReceipt;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Material;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\ProductionCost;
use App\Models\ProductionOrder;
use App\Models\PurchaseReturn;
use App\Models\SalesReturn;
use App\Models\StockMovement;
use App\Models\SubcontractOrder;
use App\Models\SubcontractTransfer;
use App\Support\LedgerAccounts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Jurnal otomatis dari setiap transaksi operasional. Nilai selalu dibaca dari data dokumen yang
 * tersimpan, sehingga jurnal yang sama bisa dibangun ulang dari riwayat ({@see LedgerRebuilder}).
 *
 * Ringkasan pola jurnal:
 * - Penerimaan barang  : Dr Persediaan bahan          / Cr Hutang belum ditagih (qty × harga PO)
 * - Tagihan supplier   : Dr Hutang belum ditagih + PPN masukan ± selisih harga / Cr Hutang usaha
 * - Surat jalan        : Dr HPP                      / Cr Persediaan barang jadi (HPP rata-rata)
 * - Invoice            : Dr Piutang + potongan       / Cr Penjualan + PPN keluaran
 * - Penerimaan / bayar : Kas/Bank ↔ Piutang / Hutang usaha
 * - Retur penjualan    : Dr Persediaan barang jadi   / Cr HPP; Dr Retur penjualan + PPN / Cr Saldo nota kredit
 * - Retur pembelian    : Dr Hutang belum ditagih / Piutang nota debit ± selisih / Cr Persediaan bahan + PPN masukan
 * - SPK mulai          : Dr WIP                      / Cr Persediaan bahan
 * - Biaya produksi     : Dr WIP                      / Cr Hutang upah / Overhead dibebankan / Hutang belum ditagih (maklon)
 * - Kirim bahan maklon : Dr Bahan di vendor          / Cr Persediaan bahan (retur sebaliknya)
 * - Tutup order maklon : Dr WIP                      / Cr Bahan di vendor (bahan terpakai/susut)
 * - SPK selesai        : Dr Persediaan barang jadi ± selisih biaya produksi / Cr WIP
 * - Upah borongan      : Dr WIP / Cr Hutang upah (lewat biaya produksi per lembar hasil kerja)
 * - Kasbon karyawan    : Dr Piutang karyawan         / Cr Kas/Bank
 * - Bayar upah borongan: Dr Hutang upah + bonus/tunjangan / Cr Kas/Bank + Piutang karyawan (potong kasbon) + pendapatan lain (potongan lain)
 * - Penyesuaian stok   : Persediaan ↔ Selisih persediaan (penyesuaian pertama = saldo awal → Ekuitas saldo awal)
 * - Kas masuk / keluar : Dr Kas/Bank / Cr akun lawan (pendapatan, pinjaman, modal, ...) dan sebaliknya
 * - Transfer rekening  : Dr rekening tujuan / Cr rekening asal
 */
class AutoJournal
{
    public function __construct(private JournalService $journals) {}

    public function goodsReceipt(GoodsReceipt $receipt): ?JournalEntry
    {
        $receipt->load('items.purchaseOrderItem', 'purchaseOrder.supplier');
        $po = $receipt->purchaseOrder;
        $value = round($receipt->items->sum(fn ($i) => (float) $i->quantity * (float) $i->purchaseOrderItem->price), 2);

        return $this->post($receipt, $receipt->receipt_date, "Penerimaan barang {$receipt->number} – {$po->number} ({$po->supplier->name})", [
            ['account' => 'raw_material', 'amount' => $value],
            ['account' => 'unbilled_payable', 'amount' => -$value, 'reference' => $po->supplier],
        ]);
    }

    public function delivery(Delivery $delivery): ?JournalEntry
    {
        $delivery->load('items', 'salesOrder.customer');
        $cost = round((float) $delivery->items->sum('cost'), 2);

        return $this->post($delivery, $delivery->delivery_date, "HPP surat jalan {$delivery->number} – {$delivery->salesOrder->number} ({$delivery->salesOrder->customer->name})", [
            ['account' => 'cogs', 'amount' => $cost],
            ['account' => 'finished_goods', 'amount' => -$cost],
        ]);
    }

    public function invoice(Invoice $invoice): ?JournalEntry
    {
        $invoice->loadMissing('customer');

        return $this->post($invoice, $invoice->invoice_date, "Invoice {$invoice->number} – {$invoice->customer->name}", [
            ['account' => 'receivable', 'amount' => (float) $invoice->total, 'reference' => $invoice->customer],
            ['account' => 'sales_discount', 'amount' => (float) $invoice->discount],
            ['account' => 'sales', 'amount' => -(float) $invoice->subtotal],
            ['account' => 'vat_out', 'amount' => -(float) $invoice->tax_amount],
        ]);
    }

    public function bill(Bill $bill): ?JournalEntry
    {
        $bill->load('items.purchaseOrderItem', 'supplier');

        // Nilai yang sudah dicatat saat barang diterima / hasil maklon diterima
        $accrued = round($bill->items->sum(fn ($i) => $i->purchase_order_item_id
            ? (float) $i->quantity * (float) $i->purchaseOrderItem->price
            : (float) $i->subtotal), 2);
        $net = round((float) $bill->subtotal - (float) $bill->discount, 2);

        return $this->post($bill, $bill->bill_date, "Tagihan {$bill->number} – {$bill->supplier->name}".($bill->supplier_invoice_number ? " (faktur {$bill->supplier_invoice_number})" : ''), [
            ['account' => 'unbilled_payable', 'amount' => $accrued, 'reference' => $bill->supplier],
            ['account' => 'purchase_variance', 'amount' => $net - $accrued],
            ['account' => 'vat_in', 'amount' => (float) $bill->tax_amount],
            ['account' => 'payable', 'amount' => -(float) $bill->total, 'reference' => $bill->supplier],
        ]);
    }

    public function payment(Payment $payment): ?JournalEntry
    {
        $party = $payment->party();
        $cash = $this->cashAccount($payment->method, $payment->cash_account_id);
        $amount = (float) $payment->amount;

        $lines = $payment->type === PaymentType::Receipt
            ? [['account' => $cash, 'amount' => $amount], ['account' => 'receivable', 'amount' => -$amount, 'reference' => $party]]
            : [['account' => 'payable', 'amount' => $amount, 'reference' => $party], ['account' => $cash, 'amount' => -$amount]];

        return $this->post($payment, $payment->payment_date, "{$payment->type->label()} {$payment->number} – {$party?->name}".($payment->reference ? " ({$payment->reference})" : ''), $lines);
    }

    public function salesReturn(SalesReturn $return): ?JournalEntry
    {
        $return->load('items', 'customer', 'salesOrder');
        $cost = round((float) $return->items->sum('cost'), 2);

        return $this->post($return, $return->return_date, "Retur penjualan {$return->number} – {$return->salesOrder->number} ({$return->customer->name})", [
            ['account' => 'finished_goods', 'amount' => $cost],
            ['account' => 'cogs', 'amount' => -$cost],
            ['account' => 'sales_returns', 'amount' => (float) $return->amount],
            ['account' => 'vat_out', 'amount' => (float) $return->tax_amount],
            ['account' => 'customer_credit', 'amount' => -(float) $return->total, 'reference' => $return->customer],
        ]);
    }

    public function purchaseReturn(PurchaseReturn $return): ?JournalEntry
    {
        $return->load('items.purchaseOrderItem', 'supplier', 'purchaseOrder');
        $cost = round((float) $return->items->sum('cost'), 2);
        // Bagian yang belum ditagih mengurangi hutang belum ditagih sebesar harga PO
        $unbilled = round($return->items->sum(fn ($i) => ((float) $i->quantity - (float) $i->billed_quantity) * (float) $i->purchaseOrderItem->price), 2);

        return $this->post($return, $return->return_date, "Retur pembelian {$return->number} – {$return->purchaseOrder->number} ({$return->supplier->name})", [
            ['account' => 'unbilled_payable', 'amount' => $unbilled, 'reference' => $return->supplier],
            ['account' => 'supplier_debit', 'amount' => (float) $return->total, 'reference' => $return->supplier],
            ['account' => 'purchase_variance', 'amount' => $cost - $unbilled - (float) $return->amount],
            ['account' => 'vat_in', 'amount' => -(float) $return->tax_amount],
            ['account' => 'raw_material', 'amount' => -$cost],
        ]);
    }

    /** Pemakaian nota kredit/debit retur untuk mengurangi invoice/tagihan. */
    public function creditApplication(SalesReturn|PurchaseReturn $return, CreditApplication $application): ?JournalEntry
    {
        $doc = $application->payable;
        $amount = (float) $application->amount;

        $lines = $return instanceof SalesReturn
            ? [['account' => 'customer_credit', 'amount' => $amount, 'reference' => $return->customer], ['account' => 'receivable', 'amount' => -$amount, 'reference' => $return->customer]]
            : [['account' => 'payable', 'amount' => $amount, 'reference' => $return->supplier], ['account' => 'supplier_debit', 'amount' => -$amount, 'reference' => $return->supplier]];

        $label = $return instanceof SalesReturn ? 'Nota kredit' : 'Nota debit';

        return $this->post($return, $application->applied_date, "{$label} {$return->number} dipakai untuk {$doc->number}", $lines, 'apply');
    }

    /** Pengembalian sisa nota sebagai uang. */
    public function returnRefund(SalesReturn|PurchaseReturn $return, float $amount, \DateTimeInterface $date, PaymentMethod|string|null $method, ?int $cashAccountId = null): ?JournalEntry
    {
        $cash = $this->cashAccount($method, $cashAccountId);

        $lines = $return instanceof SalesReturn
            ? [['account' => 'customer_credit', 'amount' => $amount, 'reference' => $return->customer], ['account' => $cash, 'amount' => -$amount]]
            : [['account' => $cash, 'amount' => $amount], ['account' => 'supplier_debit', 'amount' => -$amount, 'reference' => $return->supplier]];

        $verb = $return instanceof SalesReturn ? 'Pengembalian dana ke '.$return->customer->name : 'Pengembalian dana dari '.$return->supplier->name;

        return $this->post($return, $date, "{$verb} atas retur {$return->number}", $lines, 'refund');
    }

    /** Pengeluaran bahan dari gudang ke SPK saat produksi dimulai. */
    public function productionIssue(ProductionOrder $spk): ?JournalEntry
    {
        $movements = StockMovement::where('reference_type', $spk->getMorphClass())
            ->where('reference_id', $spk->id)
            ->where('type', StockMovementType::ProductionIssue)
            ->get();

        if ($movements->isEmpty()) {
            return null;
        }

        $value = round($movements->sum(fn ($m) => -(float) $m->quantity * (float) $m->unit_cost), 2);

        return $this->post($spk, $movements->min('date'), "Bahan baku dikeluarkan ke {$spk->number}", [
            ['account' => 'wip', 'amount' => $value, 'reference' => $spk],
            ['account' => 'raw_material', 'amount' => -$value],
        ], 'issue');
    }

    /** Biaya konversi (upah, overhead, jasa maklon) dibebankan ke barang dalam proses. */
    public function productionCost(ProductionCost $cost): ?JournalEntry
    {
        $spk = $cost->productionOrder;

        $credit = match (true) {
            $cost->subcontract_receipt_id !== null => 'unbilled_payable',
            $cost->type === ProductionCostType::Labor => 'wages_payable',
            $cost->type === ProductionCostType::Overhead => 'overhead_applied',
            default => 'accrued_expense',
        };
        $reference = $credit === 'unbilled_payable' ? $cost->subcontractReceipt?->subcontractOrder?->supplier : null;

        return $this->post($spk, $cost->date, "{$cost->type->label()} {$spk->number}: {$cost->description}", [
            ['account' => 'wip', 'amount' => (float) $cost->amount, 'reference' => $spk],
            ['account' => $credit, 'amount' => -(float) $cost->amount, 'reference' => $reference],
        ], self::costEvent($cost));
    }

    public static function costEvent(ProductionCost $cost): string
    {
        return 'cost:'.$cost->id;
    }

    public function subcontractTransfer(SubcontractTransfer $transfer): ?JournalEntry
    {
        $transfer->load('items', 'subcontractOrder.supplier');
        $order = $transfer->subcontractOrder;
        $value = round($transfer->items->sum(fn ($i) => (float) $i->quantity * (float) $i->unit_cost), 2);
        $dispatch = $transfer->type === SubcontractTransferType::Dispatch;
        $sign = $dispatch ? 1 : -1;

        return $this->post($transfer, $transfer->date, ($dispatch ? 'Kirim bahan ke ' : 'Retur sisa bahan dari ')."{$order->supplier->name} {$transfer->number} ({$order->number})", [
            ['account' => 'material_at_vendor', 'amount' => $sign * $value, 'reference' => $order],
            ['account' => 'raw_material', 'amount' => -$sign * $value],
        ]);
    }

    /** Tutup order maklon: sisa bahan di vendor (terpakai + susut) masuk ke barang dalam proses SPK. */
    public function subcontractClose(SubcontractOrder $order): ?JournalEntry
    {
        $order->loadMissing('productionOrder', 'supplier');
        $balance = $this->referenceBalance('material_at_vendor', $order);

        return $this->post($order, $order->closed_at ?? today(), "Bahan terpakai di {$order->supplier->name} ({$order->number}) dibebankan ke {$order->productionOrder->number}", [
            ['account' => 'wip', 'amount' => $balance, 'reference' => $order->productionOrder],
            ['account' => 'material_at_vendor', 'amount' => -$balance, 'reference' => $order],
        ], 'close');
    }

    /**
     * SPK selesai: barang jadi masuk persediaan sebesar HPP; sisa saldo WIP SPK (pembulatan,
     * selisih harga rata-rata) dibebankan ke selisih biaya produksi agar WIP SPK menjadi nol.
     */
    public function productionComplete(ProductionOrder $spk): ?JournalEntry
    {
        $spk->load('items');
        $finishedValue = round($spk->items->sum(fn ($i) => (float) $i->finished_quantity * (float) $spk->unit_cost), 2);
        $wip = $this->referenceBalance('wip', $spk);

        return $this->post($spk, $spk->completed_at ?? today(), "Hasil produksi {$spk->number} masuk gudang (".qty($spk->finished_quantity).' pcs × '.rupiah($spk->unit_cost).')', [
            ['account' => 'finished_goods', 'amount' => $finishedValue],
            ['account' => 'production_variance', 'amount' => $wip - $finishedValue],
            ['account' => 'wip', 'amount' => -$wip, 'reference' => $spk],
        ], 'complete');
    }

    public function stockAdjustment(StockMovement $movement): ?JournalEntry
    {
        $movement->loadMissing('stockable');
        $item = $movement->stockable;
        $isMaterial = $item instanceof Material;
        $value = round((float) $movement->quantity * (float) $movement->unit_cost, 2);
        $name = $isMaterial ? $item->name : $item->display_name;

        // Penyesuaian pertama sebuah barang = saldo awal stok, lawannya ekuitas saldo awal
        $opening = $movement->quantity > 0 && ! StockMovement::where('stockable_type', $movement->stockable_type)
            ->where('stockable_id', $movement->stockable_id)
            ->where('id', '<', $movement->id)
            ->exists();

        return $this->post($movement, $movement->date, ($opening ? 'Saldo awal stok ' : 'Penyesuaian stok ')."{$name}: ".qty($movement->quantity).($movement->notes && ! $opening ? " – {$movement->notes}" : ''), [
            ['account' => $isMaterial ? 'raw_material' : 'finished_goods', 'amount' => $value],
            ['account' => $opening ? 'opening_equity' : 'stock_adjustment', 'amount' => -$value],
        ], sourceNumber: 'ADJ-'.$movement->id);
    }

    /** Kasbon karyawan dibayarkan. */
    public function employeeAdvance(EmployeeAdvance $advance): ?JournalEntry
    {
        $advance->loadMissing('employee');

        return $this->post($advance, $advance->date, "Kasbon {$advance->number} – {$advance->employee->name}".($advance->notes ? " ({$advance->notes})" : ''), [
            ['account' => 'employee_advance', 'amount' => (float) $advance->amount, 'reference' => $advance->employee],
            ['account' => $this->cashAccount($advance->payment_method, $advance->cash_account_id), 'amount' => -(float) $advance->amount],
        ]);
    }

    /** Pembayaran upah borongan satu periode: melunasi hutang upah yang sudah dibebankan ke SPK. */
    public function payrollPayment(Payroll $payroll): ?JournalEntry
    {
        $payroll->loadMissing('lines.employee');

        $lines = [
            ['account' => 'wages_payable', 'amount' => (float) $payroll->total_gross],
            ['account' => 'wage_allowance', 'amount' => (float) $payroll->total_allowance],
            ['account' => $this->cashAccount($payroll->payment_method, $payroll->cash_account_id), 'amount' => -(float) $payroll->total_net],
            ['account' => 'payroll_other_income', 'amount' => -round((float) $payroll->lines->sum('other_deduction'), 2)],
        ];

        foreach ($payroll->lines as $line) {
            $lines[] = ['account' => 'employee_advance', 'amount' => -(float) $line->advance_deduction, 'reference' => $line->employee];
        }

        return $this->post($payroll, $payroll->payment_date ?? today(), "Pembayaran upah borongan {$payroll->number} periode {$payroll->periodLabel()}", $lines, 'paid');
    }

    /** Bukti kas masuk, kas keluar, atau transfer antar rekening. */
    public function cashTransaction(CashTransaction $transaction): ?JournalEntry
    {
        $transaction->loadMissing('lines', 'cashAccount', 'toAccount');
        $amount = (float) $transaction->amount;

        if ($transaction->type === CashTransactionType::Transfer) {
            return $this->post($transaction, $transaction->date, "Transfer {$transaction->cashAccount->name} ke {$transaction->toAccount->name}: {$transaction->description}", [
                ['account' => $transaction->to_account_id, 'amount' => $amount],
                ['account' => $transaction->cash_account_id, 'amount' => -$amount],
            ]);
        }

        $sign = $transaction->type === CashTransactionType::In ? 1 : -1;
        $lines = [['account' => $transaction->cash_account_id, 'amount' => $sign * $amount]];

        foreach ($transaction->lines as $line) {
            $lines[] = ['account' => $line->account_id, 'amount' => -$sign * (float) $line->amount, 'description' => $line->description];
        }

        $party = $transaction->party_name ? ($sign > 0 ? " dari {$transaction->party_name}" : " ke {$transaction->party_name}") : '';

        return $this->post($transaction, $transaction->date, "{$transaction->type->label()}{$party}: {$transaction->description}", $lines);
    }

    /** Batalkan semua jurnal dokumen (invoice/tagihan/pembayaran/retur dibatalkan). */
    public function reverse(Model $source, ?string $reason = null, ?string $event = null): void
    {
        $this->journals->reverseSource($source, $event, today(), $reason);
    }

    /** Rekening kas/bank yang dipilih di transaksi; bila kosong akun default menurut metode bayar. */
    private function cashAccount(PaymentMethod|string|null $method, ?int $accountId = null): string|int
    {
        if ($accountId) {
            return $accountId;
        }

        $method = is_string($method) ? PaymentMethod::tryFrom($method) : $method;

        return $method === PaymentMethod::Cash ? 'cash' : 'bank';
    }

    /** Saldo (debit − kredit) akun peran tertentu untuk satu rincian pembantu (SPK / order maklon). */
    private function referenceBalance(string $role, Model $reference): float
    {
        $accountId = app(LedgerAccounts::class)->id($role);

        $sum = JournalLine::where('account_id', $accountId)
            ->where('reference_type', $reference->getMorphClass())
            ->where('reference_id', $reference->getKey())
            ->selectRaw('COALESCE(SUM(debit), 0) - COALESCE(SUM(credit), 0) AS balance')
            ->value('balance');

        return round((float) $sum, 2);
    }

    private function post(Model $source, \DateTimeInterface|string $date, string $description, array $lines, ?string $event = 'post', ?string $sourceNumber = null): ?JournalEntry
    {
        return $this->journals->post([
            'date' => Carbon::parse($date),
            'description' => $description,
            'source' => $source,
            'source_number' => $sourceNumber ?? $source->getAttribute('number'),
            'event' => $event,
        ], $lines);
    }
}
