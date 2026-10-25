<?php

namespace App\Http\Controllers;

use App\Enums\QuotationStatus;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\ProductVariant;
use App\Models\Quotation;
use App\Models\Setting;
use App\Services\QuotationService;
use App\Support\DocumentPdf;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;

/**
 * Penawaran harga ke pelanggan (PNW-): buat, kirim, revisi, dan jadikan Sales Order.
 */
class QuotationController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:sales.view', only: ['index', 'show', 'pdf']),
            new Middleware('can:sales.manage', except: ['index', 'show', 'pdf']),
        ];
    }

    public function index(Request $request)
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        $quotations = Quotation::with(['customer', 'salesOrder'])
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")->orWhere('subject', 'like', "%{$s}%")
                ->orWhereHas('customer', fn ($c) => $c->where('name', 'like', "%{$s}%"))))
            ->when($request->customer_id, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($request->status, fn ($q, $s) => match ($s) {
                'active' => $q->active(),
                'expired' => $q->expired(),
                default => $q->where('status', $s),
            })
            ->latest('quotation_date')->latest('id')
            ->paginate(15)
            ->withQueryString();

        // Ringkasan periode (default bulan berjalan): nilai ditawarkan, diterima, dan tingkat keberhasilan
        $from = $request->from ? Carbon::parse($request->from) : today()->startOfMonth();
        $to = $request->to ? Carbon::parse($request->to) : today();
        $period = Quotation::whereBetween('quotation_date', [$from->toDateString(), $to->format('Y-m-d 23:59:59')])
            ->whereNotIn('status', [QuotationStatus::Cancelled, QuotationStatus::Revised])->get();
        $decided = $period->whereIn('status', [QuotationStatus::Accepted, QuotationStatus::Rejected])->count();
        $accepted = $period->where('status', QuotationStatus::Accepted);

        $summary = [
            'count' => $period->count(),
            'value' => round($period->sum(fn ($q) => (float) $q->total), 2),
            'accepted' => round($accepted->sum(fn ($q) => (float) $q->total), 2),
            'win_rate' => $decided > 0 ? round($accepted->count() / $decided * 100, 1) : null,
            'active' => Quotation::active()->count(),
            'active_value' => round((float) Quotation::active()->sum('total'), 2),
        ];

        return view('quotations.index', [
            'quotations' => $quotations,
            'summary' => $summary,
            'from' => $from,
            'to' => $to,
            'customers' => Customer::orderBy('name')->pluck('name', 'id'),
        ]);
    }

    public function create(Request $request)
    {
        $settings = Setting::quotation();

        return view('quotations.form', $this->formData(new Quotation([
            'customer_id' => $request->integer('customer_id') ?: null,
            'quotation_date' => today(),
            'valid_until' => today()->addDays($settings['validity_days']),
            'discount' => 0,
            'tax_rate' => Setting::invoice()['tax_rate'],
            'terms' => $settings['terms'],
        ])));
    }

    public function store(Request $request, QuotationService $service)
    {
        [$data, $items] = $this->validated($request);
        $quotation = $service->save($data, $items);

        return redirect()->route('quotations.show', $quotation)->with('success', "Penawaran {$quotation->number} berhasil dibuat.");
    }

    public function show(Quotation $quotation)
    {
        $quotation->load(['customer', 'items.variant.product', 'salesOrder', 'revisedFrom', 'revisions', 'creator', 'activities.user']);

        return view('quotations.show', compact('quotation'));
    }

    public function edit(Quotation $quotation)
    {
        abort_unless($quotation->isEditable(), 403, 'Hanya penawaran draft yang dapat diubah.');
        $quotation->load('items');

        return view('quotations.form', $this->formData($quotation));
    }

    public function update(Request $request, Quotation $quotation, QuotationService $service)
    {
        [$data, $items] = $this->validated($request);
        $service->save($data, $items, $quotation);

        return redirect()->route('quotations.show', $quotation)->with('success', 'Penawaran berhasil diperbarui.');
    }

    public function destroy(Quotation $quotation)
    {
        abort_unless($quotation->isEditable() && $quotation->revisions()->doesntExist(), 403, 'Hanya penawaran draft yang dapat dihapus.');
        ActivityLog::record('deleted', "Menghapus penawaran {$quotation->number}");
        $quotation->delete();

        return redirect()->route('quotations.index')->with('success', 'Penawaran dihapus.');
    }

    public function send(Quotation $quotation, QuotationService $service)
    {
        $service->send($quotation);

        return back()->with('success', 'Penawaran ditandai terkirim ke pelanggan.');
    }

    public function convert(Request $request, Quotation $quotation, QuotationService $service)
    {
        $data = $request->validate([
            'order_date' => ['nullable', 'date'],
            'delivery_date' => ['nullable', 'date', 'after_or_equal:order_date'],
        ], [], ['order_date' => 'tanggal order', 'delivery_date' => 'target kirim']);

        $order = $service->convert($quotation, $data);

        return redirect()->route('sales-orders.show', $order)
            ->with('success', "SO {$order->number} dibuat dari penawaran {$quotation->number}. Periksa lalu konfirmasi SO.");
    }

    public function reject(Request $request, Quotation $quotation, QuotationService $service)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan']);
        $service->reject($quotation, $data['reason']);

        return back()->with('success', 'Penawaran ditandai ditolak pelanggan.');
    }

    public function cancel(Request $request, Quotation $quotation, QuotationService $service)
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $service->cancel($quotation, $data['reason'] ?? null);

        return back()->with('success', 'Penawaran dibatalkan.');
    }

    public function revise(Quotation $quotation, QuotationService $service)
    {
        $copy = $service->revise($quotation);

        return redirect()->route('quotations.edit', $copy)->with('success', "Revisi {$copy->number} dibuat. Ubah harga / qty lalu kirim ulang.");
    }

    public function pdf(Quotation $quotation)
    {
        $quotation->load(['customer', 'items.variant.product.unit', 'creator']);

        return DocumentPdf::stream('pdf.quotation', ['quotation' => $quotation, 'settings' => Setting::invoice()], $quotation->number);
    }

    private function formData(Quotation $quotation): array
    {
        return [
            'quotation' => $quotation,
            'customers' => Customer::where('is_active', true)->orderBy('name')->get(),
            'variants' => ProductVariant::with('product')
                ->whereHas('product', fn ($q) => $q->where('is_active', true))
                ->get()
                ->sortBy('display_name')
                ->values(),
        ];
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'customer_id' => ['required', 'exists:customers,id'],
            'quotation_date' => ['required', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:quotation_date'],
            'subject' => ['nullable', 'string', 'max:255'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_variant_id' => ['required', 'exists:product_variants,id'],
            'items.*.description' => ['nullable', 'string', 'max:255'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
        ], [
            'items.required' => 'Tambahkan minimal satu produk.',
        ], [
            'customer_id' => 'pelanggan', 'quotation_date' => 'tanggal penawaran', 'valid_until' => 'berlaku sampai',
            'lead_time_days' => 'estimasi waktu', 'tax_rate' => 'PPN', 'terms' => 'syarat & ketentuan',
        ]);

        $items = $data['items'];
        unset($data['items']);

        return [$data, $items];
    }
}
