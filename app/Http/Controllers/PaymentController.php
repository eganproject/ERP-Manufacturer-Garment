<?php

namespace App\Http\Controllers;

use App\Enums\PaymentMethod;
use App\Enums\PaymentType;
use App\Models\Account;
use App\Models\Bill;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Supplier;
use App\Services\PaymentService;
use App\Support\DocumentPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Penerimaan pelanggan (route receipts.*) dan pembayaran supplier (route disbursements.*).
 */
class PaymentController extends Controller
{
    public function index(Request $request)
    {
        $type = $this->type($request);
        Gate::authorize($type->viewAbility());

        $payments = Payment::with(['customer', 'supplier'])
            ->where('type', $type)
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")
                ->orWhere('reference', 'like', "%{$s}%")
                ->orWhereHas($type === PaymentType::Receipt ? 'customer' : 'supplier', fn ($c) => $c->where('name', 'like', "%{$s}%"))))
            ->when($request->status, fn ($q, $s) => $q->where('status', $s))
            ->latest('payment_date')->latest('id')
            ->paginate(15)
            ->withQueryString();

        return view('payments.index', compact('payments', 'type'));
    }

    public function create(Request $request)
    {
        $type = $this->type($request);
        Gate::authorize($type->manageAbility());

        $isReceipt = $type === PaymentType::Receipt;
        $partyId = $request->integer('party_id') ?: null;
        $preselect = $request->integer('document_id') ?: null;

        // Buka dari tombol "Catat Pembayaran" di invoice/tagihan: pihak diambil dari dokumen
        if ($preselect && ! $partyId) {
            $doc = $isReceipt ? Invoice::find($preselect) : Bill::find($preselect);
            $partyId = $doc?->{$isReceipt ? 'customer_id' : 'supplier_id'};
        }

        $parties = ($isReceipt ? Customer::query() : Supplier::query())->orderBy('name')->get();
        $party = $partyId ? $parties->firstWhere('id', $partyId) : null;

        $documents = $party
            ? ($isReceipt ? Invoice::query() : Bill::query())
                ->open()
                ->where($isReceipt ? 'customer_id' : 'supplier_id', $party->id)
                ->orderBy('due_date')
                ->get()
            : collect();

        return view('payments.create', [
            'type' => $type,
            'parties' => $parties,
            'party' => $party,
            'documents' => $documents,
            'preselect' => $preselect,
            'methods' => PaymentMethod::options(),
        ]);
    }

    public function store(Request $request, PaymentService $service)
    {
        $data = $request->validate([
            'type' => ['required', Rule::enum(PaymentType::class)],
            'party_id' => ['required', 'integer'],
            'payment_date' => ['required', 'date'],
            'method' => ['required', Rule::enum(PaymentMethod::class)],
            'reference' => ['nullable', 'string', 'max:100'],
            'cash_account_id' => ['nullable', Account::cashRule()],
            'notes' => ['nullable', 'string'],
            'allocations' => ['required', 'array'],
            'allocations.*' => ['nullable', 'numeric', 'min:0'],
        ], [], ['payment_date' => 'tanggal bayar', 'method' => 'metode', 'reference' => 'no. referensi', 'allocations' => 'jumlah bayar', 'party_id' => 'pelanggan/supplier']);

        $type = PaymentType::from($data['type']);
        Gate::authorize($type->manageAbility());

        $payment = $service->record($type, (int) $data['party_id'], $data['allocations'], $data);

        return redirect()->route('payments.show', $payment)->with('success', "{$type->label()} {$payment->number} tersimpan sebesar ".rupiah($payment->amount).'.');
    }

    public function show(Payment $payment)
    {
        Gate::authorize($payment->type->viewAbility());
        $payment->load(['customer', 'supplier', 'allocations.payable', 'creator', 'activities.user']);

        return view('payments.show', compact('payment'));
    }

    public function cancel(Request $request, Payment $payment, PaymentService $service)
    {
        Gate::authorize($payment->type->manageAbility());
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']], [], ['reason' => 'alasan pembatalan']);
        $service->cancel($payment, $data['reason']);

        return back()->with('success', 'Pembayaran dibatalkan, sisa tagihan dikembalikan.');
    }

    public function pdf(Payment $payment)
    {
        Gate::authorize($payment->type->viewAbility());
        $payment->load(['customer', 'supplier', 'allocations.payable', 'creator']);

        return DocumentPdf::stream('pdf.payment', ['payment' => $payment], $payment->number);
    }

    private function type(Request $request): PaymentType
    {
        return PaymentType::tryFrom((string) ($request->route('type') ?? $request->input('type'))) ?? PaymentType::Receipt;
    }
}
