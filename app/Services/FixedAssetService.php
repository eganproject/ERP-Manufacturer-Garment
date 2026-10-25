<?php

namespace App\Services;

use App\Enums\AssetAcquisitionType;
use App\Enums\AssetDisposalType;
use App\Enums\DepreciationMethod;
use App\Enums\FixedAssetStatus;
use App\Models\Account;
use App\Models\ActivityLog;
use App\Models\AssetCategory;
use App\Models\FixedAsset;
use App\Models\Supplier;
use App\Services\Accounting\AutoJournal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Register aset tetap: perolehan (tunai / kredit lewat tagihan supplier / saldo awal), ubah data,
 * pembatalan, dan pelepasan (dijual / dihapus) dengan laba-rugi pelepasan.
 */
class FixedAssetService
{
    public function __construct(
        private AutoJournal $journal,
        private BillService $bills,
        private DepreciationService $depreciation,
    ) {}

    /**
     * @param  array<string, mixed>  $data  lihat FixedAssetController::store
     */
    public function create(array $data): FixedAsset
    {
        $category = AssetCategory::findOrFail($data['asset_category_id']);
        $type = AssetAcquisitionType::from($data['acquisition_type']);
        $date = Carbon::parse($data['acquisition_date']);
        $cost = round((float) $data['cost'], 2);
        $taxRate = $type === AssetAcquisitionType::Opening ? 0 : (float) ($data['tax_rate'] ?? 0);
        [$life, $method, $residual, $start, $opening] = $this->depreciationParams($category, $data, $type, $date, $cost);

        if ($cost <= 0) {
            throw ValidationException::withMessages(['cost' => 'Harga perolehan harus lebih dari 0.']);
        }

        $supplierId = null;
        $cashAccountId = null;

        if ($type === AssetAcquisitionType::Credit) {
            $supplierId = Supplier::whereKey($data['supplier_id'] ?? null)->value('id')
                ?? throw ValidationException::withMessages(['supplier_id' => 'Pilih supplier untuk pembelian kredit.']);
        } elseif ($type === AssetAcquisitionType::Cash) {
            $cashAccountId = Account::cash()->where('is_active', true)->whereKey($data['cash_account_id'] ?? null)->value('id')
                ?? throw ValidationException::withMessages(['cash_account_id' => 'Pilih rekening kas/bank pembayaran.']);
            $supplierId = Supplier::whereKey($data['supplier_id'] ?? null)->value('id');
        }

        return DB::transaction(function () use ($data, $category, $type, $date, $cost, $taxRate, $life, $method, $residual, $start, $opening, $supplierId, $cashAccountId) {
            $asset = FixedAsset::create([
                'number' => DocumentNumber::generate(FixedAsset::class, 'AT', $date),
                'name' => $data['name'],
                'asset_category_id' => $category->id,
                'serial_number' => $data['serial_number'] ?? null,
                'location' => $data['location'] ?? null,
                'acquisition_date' => $date,
                'acquisition_type' => $type,
                'supplier_id' => $supplierId,
                'cash_account_id' => $cashAccountId,
                'reference' => $data['reference'] ?? null,
                'tax_invoice_number' => $type !== AssetAcquisitionType::Cash ? null : VatService::normalizeNumber($data['tax_invoice_number'] ?? null),
                'cost' => $cost,
                'tax_rate' => $taxRate,
                'tax_amount' => $type === AssetAcquisitionType::Cash ? round($cost * $taxRate / 100) : 0,
                'residual_value' => $residual,
                'depreciation_method' => $method,
                'useful_life_months' => $life,
                'depreciation_start' => $start,
                'opening_accumulated' => $opening,
                'accumulated_depreciation' => $opening,
                'asset_account_id' => $category->asset_account_id,
                'accumulated_account_id' => $category->accumulated_account_id,
                'expense_account_id' => $category->expense_account_id,
                'status' => FixedAssetStatus::Active,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            $this->journal->fixedAssetAcquisition($asset);

            if ($type === AssetAcquisitionType::Credit) {
                $bill = $this->bills->createFromFixedAsset($asset, [
                    'bill_date' => $date->toDateString(),
                    'due_date' => $data['due_date'] ?? null,
                    'supplier_invoice_number' => $data['reference'] ?? null,
                    'tax_invoice_number' => $data['tax_invoice_number'] ?? null,
                    'tax_invoice_date' => $date->toDateString(),
                    'tax_rate' => $taxRate,
                ]);
                $asset->update(['bill_id' => $bill->id]);
            }

            ActivityLog::record('created', "Mencatat aset tetap {$asset->number} ({$type->label()}) senilai ".rupiah($cost), $asset);

            return $asset;
        });
    }

    /**
     * Ubah data aset. Parameter penyusutan hanya bisa diubah sebelum ada penyusutan yang diposting.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(FixedAsset $asset, array $data): void
    {
        if ($asset->status === FixedAssetStatus::Cancelled) {
            throw ValidationException::withMessages(['status' => 'Aset yang dibatalkan tidak dapat diubah.']);
        }

        DB::transaction(function () use ($asset, $data) {
            $attributes = [
                'name' => $data['name'],
                'serial_number' => $data['serial_number'] ?? null,
                'location' => $data['location'] ?? null,
                'notes' => $data['notes'] ?? null,
            ];

            if ($asset->canEditDepreciation() && isset($data['useful_life_months'])) {
                [$life, $method, $residual, $start, $opening] = $this->depreciationParams(
                    $asset->category, $data + ['opening_accumulated' => $asset->opening_accumulated], $asset->acquisition_type, $asset->acquisition_date, (float) $asset->cost,
                );
                $attributes += [
                    'useful_life_months' => $life,
                    'depreciation_method' => $method,
                    'residual_value' => $residual,
                    'depreciation_start' => $start,
                ];
            }

            $asset->update($attributes);
            ActivityLog::record('updated', 'Mengubah data aset', $asset);
        });
    }

    /** Batalkan pencatatan aset (salah input). Tagihan supplier ikut dibatalkan. */
    public function cancel(FixedAsset $asset, string $reason): void
    {
        $asset->loadMissing('bill');

        if (! $asset->canCancel()) {
            throw ValidationException::withMessages(['status' => 'Aset hanya bisa dibatalkan bila masih aktif, belum disusutkan, dan tagihannya belum dibayar.']);
        }

        DB::transaction(function () use ($asset, $reason) {
            if ($asset->bill) {
                $this->bills->cancel($asset->bill, "aset {$asset->number} dibatalkan – {$reason}", fromAsset: true);
            }

            $this->journal->reverse($asset, "aset dibatalkan – {$reason}");
            $asset->update(['status' => FixedAssetStatus::Cancelled, 'cancel_reason' => $reason]);

            ActivityLog::record('cancelled', "Membatalkan aset: {$reason}", $asset);
        });
    }

    /**
     * Lepas aset (dijual / dihapus). Penyusutan harus sudah diposting s.d. bulan sebelum tanggal pelepasan.
     *
     * @param  array{disposal_date: string, disposal_type: string, disposal_proceeds?: float|string|null, disposal_account_id?: int|string|null, disposal_notes?: string|null}  $data
     */
    public function dispose(FixedAsset $asset, array $data): void
    {
        if (! $asset->isActive()) {
            throw ValidationException::withMessages(['status' => 'Hanya aset aktif yang dapat dilepas.']);
        }

        $date = Carbon::parse($data['disposal_date']);
        $type = AssetDisposalType::from($data['disposal_type']);
        $proceeds = round(max(0, (float) ($data['disposal_proceeds'] ?? 0)), 2);

        if ($date->lt($asset->acquisition_date)) {
            throw ValidationException::withMessages(['disposal_date' => 'Tanggal pelepasan tidak boleh sebelum tanggal perolehan.']);
        }

        $month = $date->copy()->startOfMonth();
        $last = $asset->lastDepreciatedPeriod();

        if ($last && $last->gte($month)) {
            throw ValidationException::withMessages(['disposal_date' => 'Penyusutan aset ini sudah diposting s.d. '.$last->translatedFormat('F Y').'. Tanggal pelepasan harus setelah bulan tersebut atau batalkan posting penyusutannya.']);
        }

        if ($pending = $this->depreciation->pendingFor($asset, $month->copy()->subMonthNoOverflow())) {
            throw ValidationException::withMessages(['disposal_date' => 'Posting dulu penyusutan s.d. '.end($pending)['period']->translatedFormat('F Y').' (menu Aset Tetap → Penyusutan) sebelum melepas aset.']);
        }

        $accountId = null;
        if ($proceeds > 0) {
            $accountId = Account::cash()->where('is_active', true)->whereKey($data['disposal_account_id'] ?? null)->value('id')
                ?? throw ValidationException::withMessages(['disposal_account_id' => 'Pilih rekening penerimaan hasil penjualan aset.']);
        }

        DB::transaction(function () use ($asset, $date, $type, $proceeds, $accountId, $data) {
            $asset->update([
                'status' => FixedAssetStatus::Disposed,
                'disposal_date' => $date,
                'disposal_type' => $type,
                'disposal_proceeds' => $proceeds,
                'disposal_account_id' => $accountId,
                'disposal_notes' => $data['disposal_notes'] ?? null,
            ]);

            $this->journal->fixedAssetDisposal($asset);

            $gain = $asset->disposalGain();
            ActivityLog::record('disposed', "Melepas aset ({$type->label()}) – ".($gain >= 0 ? 'laba ' : 'rugi ').rupiah(abs($gain)), $asset);
        });
    }

    public function cancelDisposal(FixedAsset $asset, string $reason): void
    {
        if ($asset->status !== FixedAssetStatus::Disposed) {
            throw ValidationException::withMessages(['status' => 'Aset ini belum dilepas.']);
        }

        DB::transaction(function () use ($asset, $reason) {
            $this->journal->reverse($asset, "pelepasan dibatalkan – {$reason}", 'dispose');
            $asset->update([
                'status' => FixedAssetStatus::Active,
                'disposal_date' => null,
                'disposal_type' => null,
                'disposal_proceeds' => 0,
                'disposal_account_id' => null,
                'disposal_notes' => null,
            ]);

            ActivityLog::record('disposal_cancelled', "Membatalkan pelepasan aset: {$reason}", $asset);
        });
    }

    /**
     * @return array{0: int, 1: DepreciationMethod, 2: float, 3: Carbon, 4: float}
     */
    private function depreciationParams(AssetCategory $category, array $data, AssetAcquisitionType $type, Carbon $date, float $cost): array
    {
        $life = (int) ($data['useful_life_months'] ?? $category->useful_life_months);
        $method = DepreciationMethod::tryFrom((string) ($data['depreciation_method'] ?? '')) ?? $category->depreciation_method;
        $residual = array_key_exists('residual_value', $data) && $data['residual_value'] !== null && $data['residual_value'] !== ''
            ? round((float) $data['residual_value'], 2)
            : round($cost * (float) $category->residual_rate / 100, 2);
        $opening = $type === AssetAcquisitionType::Opening ? round(max(0, (float) ($data['opening_accumulated'] ?? 0)), 2) : 0.0;
        $start = ! empty($data['depreciation_start'])
            ? Carbon::parse($data['depreciation_start'])->startOfMonth()
            : ($type === AssetAcquisitionType::Opening ? today()->startOfMonth() : $date->copy()->startOfMonth());

        if ($life > 0 && (! $category->accumulated_account_id || ! $category->expense_account_id)) {
            throw ValidationException::withMessages(['asset_category_id' => "Kategori {$category->name} belum punya akun akumulasi & beban penyusutan."]);
        }
        if ($residual < 0 || $residual >= $cost && $life > 0) {
            throw ValidationException::withMessages(['residual_value' => 'Nilai sisa harus di bawah harga perolehan.']);
        }
        if ($opening > $cost - $residual + 0.005) {
            throw ValidationException::withMessages(['opening_accumulated' => 'Akumulasi penyusutan awal melebihi nilai yang dapat disusutkan.']);
        }
        if ($type !== AssetAcquisitionType::Opening && $start->lt($date->copy()->startOfMonth())) {
            throw ValidationException::withMessages(['depreciation_start' => 'Bulan mulai penyusutan tidak boleh sebelum bulan perolehan.']);
        }

        return [$life, $method, $residual, $start, $opening];
    }
}
