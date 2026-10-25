<?php

namespace App\Enums;

/**
 * Status pesanan marketplace di ERP. Stok keluar & penjualan diakui saat Dikirim;
 * Batal hanya sebelum dikirim; setelah dikirim, pembatalan / pengembalian menjadi Retur Diproses
 * sampai barang diterima gudang (Diretur).
 */
enum MarketplaceOrderStatus: string
{
    case Pending = 'pending';
    case ToShip = 'to_ship';
    case Shipped = 'shipped';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case ReturnRequested = 'return_requested';
    case Returned = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Belum Dibayar',
            self::ToShip => 'Perlu Dikirim',
            self::Shipped => 'Dikirim',
            self::Completed => 'Selesai',
            self::Cancelled => 'Batal',
            self::ReturnRequested => 'Retur Diproses',
            self::Returned => 'Diretur',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'gray',
            self::ToShip => 'amber',
            self::Shipped => 'blue',
            self::Completed => 'green',
            self::Cancelled => 'red',
            self::ReturnRequested => 'amber',
            self::Returned => 'indigo',
        };
    }

    /** Stok sudah keluar & penjualan sudah diakui. */
    public function isShipped(): bool
    {
        return in_array($this, [self::Shipped, self::Completed, self::ReturnRequested, self::Returned], true);
    }

    /** Status yang menahan (mengalokasikan) stok gudang toko. */
    public static function reserving(): array
    {
        return [self::ToShip];
    }

    /**
     * Terjemahkan status pada file ekspor / API Shopee & TikTok Shop (Indonesia / Inggris).
     */
    public static function fromPlatform(?string $raw): self
    {
        $s = mb_strtolower(trim((string) $raw));

        return match (true) {
            $s === '' => self::ToShip,
            str_contains($s, 'pengembalian') || str_contains($s, 'retur') || str_contains($s, 'return') || str_contains($s, 'refund') => self::ReturnRequested,
            str_contains($s, 'batal') || str_contains($s, 'cancel') || str_contains($s, 'gagal') || str_contains($s, 'failed') => self::Cancelled,
            str_contains($s, 'belum bayar') || str_contains($s, 'belum dibayar') || str_contains($s, 'unpaid') || str_contains($s, 'menunggu pembayaran') => self::Pending,
            str_contains($s, 'selesai') || str_contains($s, 'complete') || str_contains($s, 'delivered') || str_contains($s, 'diterima') => self::Completed,
            str_contains($s, 'perlu dikirim') || str_contains($s, 'akan dikirim') || str_contains($s, 'siap dikirim') || str_contains($s, 'to ship')
                || str_contains($s, 'awaiting') || str_contains($s, 'ready_to_ship') || str_contains($s, 'processed') || str_contains($s, 'dikemas') => self::ToShip,
            str_contains($s, 'kirim') || str_contains($s, 'ship') || str_contains($s, 'transit') || str_contains($s, 'perjalanan') => self::Shipped,
            default => self::ToShip,
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $s) => [$s->value => $s->label()])->all();
    }
}
