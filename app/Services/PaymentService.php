<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Enums\PaymentType;
use App\Models\ActivityLog;
use App\Models\Bill;
use App\Models\Invoice;
use App\Models\Payment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penerimaan pelanggan (RV) dan pembayaran supplier (PV).
 * Satu pembayaran dapat melunasi beberapa invoice/tagihan dari pihak yang sama.
 */
class PaymentService
{
    /**
     * @param  array<int, float|string|null>  $allocations  id invoice/tagihan => jumlah dibayar
     * @param  array{payment_date: string, method: string, reference?: string|null, notes?: string|null}  $data
     */
    public function record(PaymentType $type, int $partyId, array $allocations, array $data): Payment
    {
        $allocations = array_filter(array_map(fn ($a) => round((float) $a, 2), $allocations), fn ($a) => $a > 0);

        if (empty($allocations)) {
            throw ValidationException::withMessages(['allocations' => 'Isi jumlah bayar minimal pada satu dokumen.']);
        }

        return DB::transaction(function () use ($type, $partyId, $allocations, $data) {
            $partyColumn = $type === PaymentType::Receipt ? 'customer_id' : 'supplier_id';
            $model = $type === PaymentType::Receipt ? Invoice::class : Bill::class;

            $documents = $model::query()
                ->whereKey(array_keys($allocations))
                ->where($partyColumn, $partyId)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($allocations as $id => $amount) {
                $doc = $documents->get($id);

                if (! $doc || ! $doc->isOpen()) {
                    throw ValidationException::withMessages(["allocations.$id" => 'Dokumen tidak ditemukan atau sudah lunas/dibatalkan.']);
                }

                if ($amount > $doc->outstanding() + 0.009) {
                    throw ValidationException::withMessages([
                        "allocations.$id" => "Pembayaran {$doc->number} melebihi sisa tagihan (".rupiah($doc->outstanding()).').',
                    ]);
                }
            }

            $date = Carbon::parse($data['payment_date']);

            $payment = Payment::create([
                'number' => DocumentNumber::generate(Payment::class, $type->prefix(), $date),
                'type' => $type,
                $partyColumn => $partyId,
                'payment_date' => $date,
                'method' => $data['method'],
                'reference' => $data['reference'] ?? null,
                'amount' => array_sum($allocations),
                'status' => PaymentStatus::Posted,
                'notes' => $data['notes'] ?? null,
                'created_by' => auth()->id(),
            ]);

            foreach ($allocations as $id => $amount) {
                $doc = $documents->get($id);
                $payment->allocations()->create([
                    'payable_type' => $doc->getMorphClass(),
                    'payable_id' => $doc->id,
                    'amount' => $amount,
                ]);
                $doc->refreshPaymentStatus();

                ActivityLog::record('paid', "Pembayaran {$payment->number} sebesar ".rupiah($amount), $doc);
            }

            ActivityLog::record('created', "Mencatat {$type->label()} {$payment->number}", $payment);

            return $payment;
        });
    }

    public function cancel(Payment $payment, string $reason): void
    {
        if ($payment->isCancelled()) {
            throw ValidationException::withMessages(['status' => 'Pembayaran ini sudah dibatalkan.']);
        }

        DB::transaction(function () use ($payment, $reason) {
            $payment->update(['status' => PaymentStatus::Cancelled, 'cancel_reason' => $reason]);

            foreach ($payment->allocations()->with('payable')->get() as $allocation) {
                $doc = $allocation->payable()->lockForUpdate()->first();
                $doc->refreshPaymentStatus();

                ActivityLog::record('payment_cancelled', "Pembayaran {$payment->number} dibatalkan", $doc);
            }

            ActivityLog::record('cancelled', "Membatalkan pembayaran: {$reason}", $payment);
        });
    }
}
