<?php

namespace App\Services;

use App\Enums\SalesOrderStatus;
use App\Enums\StockMovementType;
use App\Models\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesService
{
    public function __construct(private StockService $stock) {}

    public function deliver(SalesOrder $order): void
    {
        if ($order->status !== SalesOrderStatus::Confirmed) {
            throw ValidationException::withMessages(['status' => 'Hanya SO berstatus Dikonfirmasi yang dapat dikirim.']);
        }

        DB::transaction(function () use ($order) {
            foreach ($order->items()->with('variant.product')->get() as $item) {
                $this->stock->move(
                    $item->variant, -1 * (float) $item->quantity, StockMovementType::SalesDelivery,
                    $order, $order->number, 'Pengiriman ke '.$order->customer->name,
                );
            }

            $order->update(['status' => SalesOrderStatus::Delivered]);
        });
    }
}
