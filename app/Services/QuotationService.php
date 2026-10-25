<?php

namespace App\Services;

use App\Enums\QuotationStatus;
use App\Enums\SalesOrderStatus;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Quotation;
use App\Models\SalesOrder;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Penawaran harga (PNW-): Draft → Terkirim → Diterima (dibuatkan SO draft) / Ditolak.
 * Penawaran terkirim, ditolak, atau kedaluwarsa bisa direvisi menjadi penawaran baru (nomor -R1, -R2, ...).
 */
class QuotationService
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<array{product_variant_id: int|string, quantity: float|string, price: float|string, description?: string|null}>  $items
     */
    public function save(array $data, array $items, ?Quotation $quotation = null): Quotation
    {
        if ($quotation && ! $quotation->isEditable()) {
            throw ValidationException::withMessages(['status' => 'Hanya penawaran draft yang dapat diubah. Buat revisi untuk mengubah penawaran terkirim.']);
        }

        $date = Carbon::parse($data['quotation_date']);
        $validUntil = ! empty($data['valid_until'])
            ? Carbon::parse($data['valid_until'])
            : $date->copy()->addDays(Setting::quotation()['validity_days']);

        if ($validUntil->lt($date)) {
            throw ValidationException::withMessages(['valid_until' => 'Tanggal berlaku harus sama atau setelah tanggal penawaran.']);
        }

        $lines = array_map(fn ($row) => [
            'product_variant_id' => $row['product_variant_id'],
            'description' => $row['description'] ?? null,
            'quantity' => (float) $row['quantity'],
            'price' => (float) $row['price'],
            'subtotal' => round((float) $row['quantity'] * (float) $row['price'], 2),
        ], array_values($items));

        if (empty($lines)) {
            throw ValidationException::withMessages(['items' => 'Tambahkan minimal satu produk.']);
        }

        $subtotal = round(array_sum(array_column($lines, 'subtotal')), 2);
        $discount = round(min(max((float) ($data['discount'] ?? 0), 0), $subtotal), 2);
        $taxRate = (float) ($data['tax_rate'] ?? 0);
        $taxAmount = round(($subtotal - $discount) * $taxRate / 100);

        $attributes = [
            'customer_id' => $data['customer_id'],
            'quotation_date' => $date,
            'valid_until' => $validUntil,
            'subject' => $data['subject'] ?? null,
            'lead_time_days' => ($data['lead_time_days'] ?? null) !== null && $data['lead_time_days'] !== '' ? (int) $data['lead_time_days'] : null,
            'subtotal' => $subtotal,
            'discount' => $discount,
            'tax_rate' => $taxRate,
            'tax_amount' => $taxAmount,
            'total' => $subtotal - $discount + $taxAmount,
            'terms' => $data['terms'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];

        return DB::transaction(function () use ($quotation, $attributes, $lines, $date) {
            if ($quotation) {
                $quotation->update($attributes);
                $quotation->items()->delete();
                ActivityLog::record('updated', 'Mengubah penawaran', $quotation);
            } else {
                $quotation = Quotation::create($attributes + [
                    'number' => DocumentNumber::generate(Quotation::class, 'PNW', $date),
                    'status' => QuotationStatus::Draft,
                    'created_by' => auth()->id(),
                ]);
                ActivityLog::record('created', "Membuat penawaran {$quotation->number}", $quotation);
            }

            $quotation->items()->createMany($lines);

            return $quotation;
        });
    }

    /** Tandai sudah dikirim ke pelanggan (PDF/email/WA). */
    public function send(Quotation $quotation): void
    {
        if (! $quotation->canSend()) {
            throw ValidationException::withMessages(['status' => $quotation->isExpired()
                ? 'Penawaran sudah lewat masa berlaku. Ubah tanggal berlakunya dulu.'
                : 'Hanya penawaran draft yang dapat ditandai terkirim.']);
        }

        $quotation->update(['status' => QuotationStatus::Sent, 'sent_at' => now()]);
        ActivityLog::record('sent', 'Penawaran dikirim ke pelanggan', $quotation);
    }

    /**
     * Pelanggan setuju: buat Sales Order draft dengan item, harga & diskon yang sama.
     *
     * @param  array{order_date?: string|null, delivery_date?: string|null, notes?: string|null}  $data
     */
    public function convert(Quotation $quotation, array $data = []): SalesOrder
    {
        if (! $quotation->canConvert()) {
            throw ValidationException::withMessages(['status' => $quotation->isExpired()
                ? 'Penawaran sudah kedaluwarsa. Buat revisi dengan masa berlaku baru sebelum dijadikan SO.'
                : 'Penawaran ini sudah diproses.']);
        }

        if (! Customer::whereKey($quotation->customer_id)->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['customer_id' => 'Pelanggan pada penawaran ini sudah nonaktif.']);
        }

        return DB::transaction(function () use ($quotation, $data) {
            $quotation = Quotation::lockForUpdate()->findOrFail($quotation->id);

            if (! $quotation->isOpen()) {
                throw ValidationException::withMessages(['status' => 'Penawaran ini sudah diproses.']);
            }

            $orderDate = Carbon::parse($data['order_date'] ?? today());
            $deliveryDate = ! empty($data['delivery_date'])
                ? Carbon::parse($data['delivery_date'])
                : ($quotation->lead_time_days ? $orderDate->copy()->addDays($quotation->lead_time_days) : null);

            $order = SalesOrder::create([
                'number' => DocumentNumber::generate(SalesOrder::class, 'SO', $orderDate),
                'customer_id' => $quotation->customer_id,
                'quotation_id' => $quotation->id,
                'order_date' => $orderDate,
                'delivery_date' => $deliveryDate,
                'status' => SalesOrderStatus::Draft,
                'subtotal' => $quotation->subtotal,
                'discount' => $quotation->discount,
                'total' => round((float) $quotation->subtotal - (float) $quotation->discount, 2),
                'notes' => trim(($data['notes'] ?? '')."\nDari penawaran {$quotation->number}".($quotation->subject ? " – {$quotation->subject}" : '')),
                'created_by' => auth()->id(),
            ]);
            $order->items()->createMany($quotation->items->map(fn ($i) => [
                'product_variant_id' => $i->product_variant_id,
                'quantity' => $i->quantity,
                'price' => $i->price,
                'subtotal' => $i->subtotal,
            ])->all());

            $quotation->update(['status' => QuotationStatus::Accepted, 'decided_at' => now(), 'sent_at' => $quotation->sent_at ?? now()]);

            ActivityLog::record('accepted', "Penawaran diterima, dibuatkan SO {$order->number}", $quotation);
            ActivityLog::record('created', "Membuat SO {$order->number} dari penawaran {$quotation->number}", $order);

            return $order;
        });
    }

    public function reject(Quotation $quotation, string $reason): void
    {
        if (! $quotation->isOpen()) {
            throw ValidationException::withMessages(['status' => 'Penawaran ini sudah diproses.']);
        }

        $quotation->update(['status' => QuotationStatus::Rejected, 'decided_at' => now(), 'reject_reason' => $reason]);
        ActivityLog::record('rejected', "Penawaran ditolak pelanggan: {$reason}", $quotation);
    }

    public function cancel(Quotation $quotation, ?string $reason = null): void
    {
        if (! $quotation->isOpen()) {
            throw ValidationException::withMessages(['status' => 'Penawaran ini sudah diproses.']);
        }

        $quotation->update(['status' => QuotationStatus::Cancelled, 'reject_reason' => $reason]);
        ActivityLog::record('cancelled', 'Membatalkan penawaran'.($reason ? ": {$reason}" : ''), $quotation);
    }

    /**
     * Revisi: salin penawaran menjadi draft baru (nomor dasar + -R{n}) dengan masa berlaku baru;
     * penawaran lama berstatus Direvisi (kecuali yang sudah ditolak tetap Ditolak).
     */
    public function revise(Quotation $quotation): Quotation
    {
        if (! $quotation->canRevise()) {
            throw ValidationException::withMessages(['status' => 'Hanya penawaran terkirim, ditolak, atau kedaluwarsa yang dapat direvisi.']);
        }

        return DB::transaction(function () use ($quotation) {
            $root = $quotation->revisedFrom ? $this->root($quotation) : $quotation;
            $revision = (int) Quotation::where('id', $root->id)->orWhere('number', 'like', $root->number.'-R%')->max('revision') + 1;
            $validity = max(1, (int) $quotation->quotation_date->diffInDays($quotation->valid_until));

            $copy = $quotation->replicate(['number', 'status', 'sent_at', 'decided_at', 'reject_reason', 'created_by', 'revision', 'revised_from_id']);
            $copy->fill([
                'number' => $root->number.'-R'.$revision,
                'status' => QuotationStatus::Draft,
                'revision' => $revision,
                'revised_from_id' => $quotation->id,
                'quotation_date' => today(),
                'valid_until' => today()->addDays($validity),
                'created_by' => auth()->id(),
            ])->save();
            $copy->items()->createMany($quotation->items->map(fn ($i) => $i->only(['product_variant_id', 'description', 'quantity', 'price', 'subtotal']))->all());

            if ($quotation->isOpen()) {
                $quotation->update(['status' => QuotationStatus::Revised, 'decided_at' => now()]);
            }

            ActivityLog::record('revised', "Direvisi menjadi {$copy->number}", $quotation);
            ActivityLog::record('created', "Revisi ke-{$revision} dari {$quotation->number}", $copy);

            return $copy;
        });
    }

    private function root(Quotation $quotation): Quotation
    {
        while ($quotation->revisedFrom) {
            $quotation = $quotation->revisedFrom;
        }

        return $quotation;
    }
}
