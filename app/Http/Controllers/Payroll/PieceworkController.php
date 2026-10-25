<?php

namespace App\Http\Controllers\Payroll;

use App\Enums\ProductionOrderStatus;
use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PieceworkEntry;
use App\Models\PieceworkSheet;
use App\Models\ProductionOrder;
use App\Services\PieceworkService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

/**
 * Input & daftar hasil kerja borongan harian per SPK.
 */
class PieceworkController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('can:payroll.view', only: ['index', 'show']),
            new Middleware('can:piecework.record', only: ['create', 'store', 'destroy']),
        ];
    }

    public function __construct(private PieceworkService $service) {}

    public function index(Request $request)
    {
        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);

        $sheets = PieceworkSheet::with(['productionOrder.product', 'entries', 'creator'])
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('number', 'like', "%{$s}%")
                ->orWhereHas('productionOrder', fn ($p) => $p->where('number', 'like', "%{$s}%"))))
            ->when($request->employee_id, fn ($q, $id) => $q->whereHas('entries', fn ($e) => $e->where('employee_id', $id)))
            ->when($request->from, fn ($q, $d) => $q->whereDate('date', '>=', $d))
            ->when($request->to, fn ($q, $d) => $q->whereDate('date', '<=', $d))
            ->when($request->status === 'unpaid', fn ($q) => $q->whereHas('entries', fn ($e) => $e->whereNull('payroll_line_id')))
            ->latest('date')->latest('id')
            ->paginate(20)
            ->withQueryString();

        $unpaid = PieceworkEntry::whereNull('payroll_line_id');

        return view('payroll.piecework.index', [
            'sheets' => $sheets,
            'employees' => Employee::orderBy('name')->get()->mapWithKeys(fn ($e) => [$e->id => $e->display_name]),
            'summary' => [
                'unpaid' => round((float) (clone $unpaid)->sum('amount'), 2),
                'unpaid_employees' => (clone $unpaid)->distinct('employee_id')->count('employee_id'),
                'week' => round((float) PieceworkEntry::whereHas('sheet', fn ($q) => $q->whereDate('date', '>=', today()->subDays(6)))->sum('amount'), 2),
            ],
        ]);
    }

    public function create(Request $request)
    {
        $orders = ProductionOrder::with('product')->where('status', ProductionOrderStatus::InProgress)->latest('start_date')->get();
        $spk = $request->integer('production_order_id') ? $orders->firstWhere('id', $request->integer('production_order_id')) : null;

        return view('payroll.piecework.create', [
            'orders' => $orders,
            'spk' => $spk?->load(['product.operations', 'stages.subcontractOrder.supplier']),
            'progress' => $spk ? $this->service->progress($spk) : collect(),
            'employees' => Employee::active()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'production_order_id' => ['required', 'exists:production_orders,id'],
            'date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
            'lines' => ['required', 'array'],
            'lines.*.employee_id' => ['nullable', 'integer'],
            'lines.*.operation' => ['nullable', 'string', 'max:20'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'lines.*.reject_quantity' => ['nullable', 'numeric', 'min:0'],
        ], [], ['production_order_id' => 'SPK', 'lines' => 'hasil kerja']);

        $spk = ProductionOrder::findOrFail($data['production_order_id']);
        $sheet = $this->service->record($spk, $data, array_values($data['lines']));

        return redirect()->route('piecework.show', $sheet)->with('success', "Hasil kerja {$sheet->number} dicatat, upah ".rupiah($sheet->total_amount).' dibebankan ke SPK.');
    }

    public function show(PieceworkSheet $piecework)
    {
        $piecework->load(['productionOrder.product', 'entries.employee', 'entries.payrollLine.payroll', 'cost', 'creator']);

        return view('payroll.piecework.show', ['sheet' => $piecework]);
    }

    public function destroy(PieceworkSheet $piecework)
    {
        $spk = $piecework->productionOrder;
        $this->service->delete($piecework);

        return redirect()->route('production-orders.show', $spk)->with('success', "Hasil kerja {$piecework->number} dihapus dan upahnya dikeluarkan dari HPP SPK.");
    }
}
