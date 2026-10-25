<?php

namespace App\Http\Controllers\Master;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $customers = Customer::query()
            ->when($request->q, fn ($q, $s) => $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")
                ->orWhere('code', 'like', "%{$s}%")
                ->orWhere('phone', 'like', "%{$s}%")))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('master.customers.index', compact('customers'));
    }

    public function create()
    {
        return view('master.customers.form', ['customer' => new Customer(['is_active' => true])]);
    }

    public function store(Request $request)
    {
        Customer::create($this->validated($request));

        return redirect()->route('customers.index')->with('success', 'Pelanggan berhasil ditambahkan.');
    }

    public function edit(Customer $customer)
    {
        return view('master.customers.form', compact('customer'));
    }

    public function update(Request $request, Customer $customer)
    {
        $customer->update($this->validated($request, $customer));

        return redirect()->route('customers.index')->with('success', 'Pelanggan berhasil diperbarui.');
    }

    public function destroy(Customer $customer)
    {
        if ($customer->salesOrders()->exists()) {
            return back()->with('error', 'Pelanggan sudah memiliki transaksi, nonaktifkan saja.');
        }

        $customer->delete();

        return back()->with('success', 'Pelanggan berhasil dihapus.');
    }

    private function validated(Request $request, ?Customer $customer = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('customers')->ignore($customer)],
            'name' => ['required', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string'],
            'tax_number' => ['nullable', 'string', 'max:30'],
            'payment_terms' => ['nullable', 'integer', 'min:0', 'max:365'],
        ]);

        $data['payment_terms'] = (int) ($data['payment_terms'] ?? 30);

        return $data + ['is_active' => $request->boolean('is_active')];
    }
}
