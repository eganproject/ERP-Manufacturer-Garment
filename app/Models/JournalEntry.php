<?php

namespace App\Models;

use App\Enums\JournalType;
use App\Models\Concerns\HasActivityLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class JournalEntry extends Model
{
    use HasActivityLog;

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date',
        'type' => JournalType::class,
        'total' => 'decimal:2',
        'reversed_at' => 'datetime',
    ];

    public function lines()
    {
        return $this->hasMany(JournalLine::class)->orderBy('id');
    }

    /** Dokumen asal jurnal otomatis (invoice, penerimaan barang, SPK, dst). */
    public function source()
    {
        return $this->morphTo();
    }

    public function reversalOf()
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversal()
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Jurnal yang masih berlaku (bukan yang sudah dibalik maupun jurnal pembaliknya). */
    public function scopeEffective(Builder $query): Builder
    {
        return $query->whereNull('reversed_at')->whereNull('reversal_of_id');
    }

    public function isReversed(): bool
    {
        return $this->reversed_at !== null;
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    public function canReverse(): bool
    {
        return $this->type === JournalType::Manual && ! $this->isReversed() && ! $this->isReversal();
    }

    /** Tautan ke dokumen asal (bila masih ada). */
    public function sourceUrl(): ?string
    {
        $source = $this->source;

        return match (true) {
            $source instanceof Invoice => route('invoices.show', $source),
            $source instanceof Bill => route('bills.show', $source),
            $source instanceof Payment => route('payments.show', $source),
            $source instanceof GoodsReceipt => route('purchase-orders.show', $source->purchase_order_id),
            $source instanceof Delivery => route('sales-orders.show', $source->sales_order_id),
            $source instanceof SalesReturn => route('sales-returns.show', $source),
            $source instanceof PurchaseReturn => route('purchase-returns.show', $source),
            $source instanceof ProductionOrder => route('production-orders.show', $source),
            $source instanceof ProductionMaterialTransfer, $source instanceof ProductionReceipt => route('production-orders.show', $source->production_order_id),
            $source instanceof SubcontractOrder => route('subcontract-orders.show', $source),
            $source instanceof SubcontractTransfer => route('subcontract-orders.show', $source->subcontract_order_id),
            $source instanceof CashTransaction => route('cash-transactions.show', $source),
            $source instanceof EmployeeAdvance => route('employees.show', $source->employee_id),
            $source instanceof Payroll => route('payrolls.show', $source),
            $source instanceof StockMovement => $source->stockable_type === 'material'
                ? route('stock.materials.card', $source->stockable_id)
                : route('stock.products.card', $source->stockable_id),
            default => null,
        };
    }

    public function sourceLabel(): ?string
    {
        if (! $this->source_type) {
            return null;
        }

        return match ($this->source_type) {
            'invoice' => 'Invoice',
            'bill' => 'Tagihan Supplier',
            'payment' => 'Pembayaran',
            'goods_receipt' => 'Penerimaan Barang',
            'delivery' => 'Surat Jalan',
            'sales_return' => 'Retur Penjualan',
            'purchase_return' => 'Retur Pembelian',
            'production_order' => 'SPK Produksi',
            'subcontract_order' => 'Order Maklon',
            'subcontract_transfer' => 'Bahan Maklon',
            'production_material_transfer' => 'Bahan SPK',
            'production_receipt' => 'Setor Hasil Produksi',
            'stock_movement' => 'Penyesuaian Stok',
            'cash_transaction' => 'Bukti Kas & Bank',
            'employee_advance' => 'Kasbon Karyawan',
            'payroll' => 'Penggajian Borongan',
            default => $this->source_type,
        };
    }
}
