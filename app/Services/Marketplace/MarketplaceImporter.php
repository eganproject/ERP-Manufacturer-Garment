<?php

namespace App\Services\Marketplace;

use App\Enums\MarketplacePlatform;
use App\Models\ActivityLog;
use App\Models\MarketplaceStore;
use App\Support\SpreadsheetReader;
use App\Support\XlsxWriter;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Impor file ekspor pesanan dari Seller Center Shopee / TikTok Shop (xlsx / csv) atau template ERP.
 * Judul kolom dikenali lewat daftar alias (Indonesia & Inggris); satu baris = satu barang, baris dengan
 * nomor pesanan sama digabung menjadi satu pesanan.
 */
class MarketplaceImporter
{
    /** Alias judul kolom pesanan (sudah dinormalisasi: huruf kecil tanpa spasi/tanda baca). */
    public const ORDER_COLUMNS = [
        'order_number' => ['nopesanan', 'nomorpesanan', 'idpesanan', 'orderid', 'ordernumber', 'ordersn'],
        'status' => ['statuspesanan', 'orderstatus', 'status', 'ordersubstatus', 'statuspembatalanpengembalian'],
        'order_date' => ['waktupesanandibuat', 'tanggalpesanan', 'createdtime', 'ordercreatedtime', 'ordercreatetime', 'waktupesanan', 'tanggalpembuatanpesanan'],
        'paid_at' => ['waktupembayarandilakukan', 'waktupembayaran', 'paidtime', 'tanggalpembayaran'],
        'shipped_at' => ['waktupengirimandiatur', 'waktupengiriman', 'shippedtime', 'tanggalpengiriman', 'waktupesanandikirim', 'rtstime'],
        'completed_at' => ['waktupesananselesai', 'waktuselesai', 'deliveredtime', 'completedtime', 'tanggalselesai'],
        'cancelled_at' => ['waktupembatalan', 'cancelledtime', 'canceltime'],
        'cancel_reason' => ['alasanpembatalan', 'cancelreason', 'cancelby'],
        'buyer' => ['usernamepembeli', 'username', 'buyerusername', 'namapembeli', 'usernamebuyer'],
        'recipient' => ['namapenerima', 'recipient', 'penerima'],
        'city' => ['kotakabupaten', 'kota', 'regencyandcity', 'city'],
        'courier' => ['opsipengiriman', 'kurir', 'jasakirim', 'shippingprovidername', 'shippingprovider', 'deliveryoption'],
        'tracking_number' => ['noresi', 'nomorresi', 'trackingid', 'trackingnumber', 'resi'],
        'shipping_fee' => ['ongkoskirimdibayarolehpembeli', 'ongkirdibayarpembeli', 'shippingfeeafterdiscount', 'ongkoskirim', 'ongkir'],
        'sku' => ['nomorreferensisku', 'sellersku', 'skupenjual', 'sku', 'skuinduk', 'skureferensi', 'skuid'],
        'name' => ['namaproduk', 'productname', 'produk'],
        'variation' => ['namavariasi', 'variation', 'variasi'],
        'quantity' => ['jumlah', 'quantity', 'qty', 'jumlahproduk'],
        'unit_price' => ['hargasetelahdiskon', 'hargasatuan', 'skuunitoriginalprice', 'hargaawal', 'unitprice'],
        'subtotal' => ['totalhargaproduk', 'skusubtotalafterdiscount', 'subtotal', 'subtotalproduk'],
    ];

    /** Judul kolom template ERP (urut sesuai file template). */
    public const ORDER_TEMPLATE = ['No. Pesanan', 'Status Pesanan', 'Waktu Pesanan Dibuat', 'Waktu Pembayaran', 'Waktu Pengiriman', 'Waktu Pesanan Selesai',
        'Username Pembeli', 'Nama Penerima', 'Kota', 'Kurir', 'No. Resi', 'Ongkir Dibayar Pembeli', 'SKU', 'Nama Produk', 'Nama Variasi', 'Jumlah', 'Harga Satuan', 'Total Harga Produk'];

    public function __construct(private MarketplaceOrderService $orders) {}

    /**
     * @return array{orders: int, created: int, updated: int, unchanged: int, errors: list<string>, warnings: list<string>}
     */
    public function importFile(MarketplaceStore $store, string $path, ?string $originalName = null): array
    {
        $table = SpreadsheetReader::withHeaders(SpreadsheetReader::read($path, $originalName), self::ORDER_COLUMNS['order_number']);
        $orders = $this->groupOrders($store->platform, $table['headers'], $table['rows']);

        if (empty($orders)) {
            throw ValidationException::withMessages(['file' => 'Tidak ada pesanan yang terbaca dari file ini.']);
        }

        $summary = $this->importOrders($store, $orders, 'import');
        ActivityLog::record('imported', "Impor pesanan {$store->display_name} dari file ".($originalName ?? basename($path)).": {$summary['created']} baru, {$summary['updated']} diperbarui", $store);

        return $summary;
    }

    /**
     * @param  list<array<string, mixed>>  $orders  format ternormalisasi
     * @return array{orders: int, created: int, updated: int, unchanged: int, errors: list<string>, warnings: list<string>}
     */
    public function importOrders(MarketplaceStore $store, array $orders, string $source): array
    {
        $summary = ['orders' => count($orders), 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'errors' => [], 'warnings' => []];

        foreach ($orders as $data) {
            try {
                $outcome = $this->orders->upsert($store, $data, $source);
                $summary[$outcome['result']]++;
                if ($outcome['warning']) {
                    $summary['warnings'][] = $outcome['warning'];
                }
            } catch (ValidationException $e) {
                $summary['errors'][] = "Pesanan {$data['order_number']}: ".collect($e->errors())->flatten()->first();
            }
        }

        return $summary;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<array<string, string>>  $rows
     * @return list<array<string, mixed>>
     */
    public function groupOrders(MarketplacePlatform $platform, array $headers, array $rows): array
    {
        $map = self::columnMap($headers, self::ORDER_COLUMNS);
        $slash = $platform->slashDateFormat();
        $get = fn (array $row, string $field) => isset($map[$field]) ? trim((string) ($row[$map[$field]] ?? '')) : '';
        $date = fn (array $row, string $field) => SpreadsheetReader::date($get($row, $field), $slash);
        $orders = [];

        foreach ($rows as $row) {
            $number = ltrim($get($row, 'order_number'), "'");
            // TikTok kadang menyisipkan baris keterangan di bawah judul kolom
            if ($number === '' || ! preg_match('/[0-9]/', $number) || mb_strlen($number) > 60) {
                continue;
            }

            if (! isset($orders[$number])) {
                $orders[$number] = [
                    'order_number' => $number,
                    'status' => $get($row, 'status'),
                    'order_date' => $date($row, 'order_date') ?? $date($row, 'paid_at') ?? Carbon::now(),
                    'paid_at' => $date($row, 'paid_at'),
                    'shipped_at' => $date($row, 'shipped_at'),
                    'completed_at' => $date($row, 'completed_at'),
                    'cancelled_at' => $date($row, 'cancelled_at'),
                    'cancel_reason' => $get($row, 'cancel_reason') ?: null,
                    'buyer' => $get($row, 'buyer') ?: null,
                    'recipient' => $get($row, 'recipient') ?: null,
                    'city' => $get($row, 'city') ?: null,
                    'courier' => $get($row, 'courier') ?: null,
                    'tracking_number' => ltrim($get($row, 'tracking_number'), "'") ?: null,
                    'shipping_fee' => abs(SpreadsheetReader::number($get($row, 'shipping_fee'))),
                    'items' => [],
                ];
            }

            $qty = SpreadsheetReader::number($get($row, 'quantity')) ?: 1;
            $price = abs(SpreadsheetReader::number($get($row, 'unit_price')));
            $subtotal = abs(SpreadsheetReader::number($get($row, 'subtotal')));

            // Shopee: "Harga Setelah Diskon" per barang lebih pasti per baris; TikTok: "SKU Subtotal After Discount" per baris
            if ($platform === MarketplacePlatform::Shopee && $price > 0) {
                $subtotal = round($price * $qty, 2);
            }

            $orders[$number]['items'][] = [
                'sku' => ltrim($get($row, 'sku'), "'") ?: null,
                'name' => $get($row, 'name') ?: null,
                'variation' => $get($row, 'variation') ?: null,
                'quantity' => $qty,
                'unit_price' => $price,
                'subtotal' => $subtotal,
            ];
        }

        return array_values($orders);
    }

    /**
     * Cocokkan judul kolom file dengan alias field. Field bisa dipetakan ke beberapa kolom (lihat $multi).
     *
     * @param  list<string>  $headers
     * @param  array<string, list<string>>  $columns
     * @return array<string, string> field => judul kolom ternormalisasi
     */
    public static function columnMap(array $headers, array $columns): array
    {
        $map = [];
        foreach ($columns as $field => $aliases) {
            foreach ($aliases as $alias) {
                if (in_array($alias, $headers, true)) {
                    $map[$field] = $alias;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Template Excel: sheet "Data" (judul kolom saja, isi di sini), sheet "Contoh" berisi satu baris contoh.
     * Contoh sengaja dipisah agar tidak ikut terimpor.
     *
     * @param  list<string>  $headers
     * @param  list<string|int|float>  $example
     * @param  list<string>  $notes
     */
    public static function template(array $headers, array $example, string $filename, array $notes = []): BinaryFileResponse
    {
        $writer = (new XlsxWriter)
            ->sheet('Data', [$headers], textColumns: [0])
            ->sheet('Contoh', [$headers, $example], textColumns: [0]);

        if ($notes) {
            $writer->sheet('Petunjuk', array_map(fn ($line) => [$line], $notes), header: false);
        }

        return $writer->download($filename);
    }
}
