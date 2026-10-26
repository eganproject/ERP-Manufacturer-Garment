<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class SettingController extends Controller
{
    public function edit()
    {
        return view('settings.edit', [
            'company' => Setting::company(),
            'invoice' => Setting::invoice(),
            'poApprovalThreshold' => Setting::poApprovalThreshold(),
            'payroll' => Setting::payroll(),
            'quotation' => Setting::quotation(),
            'signers' => Setting::signers(),
            'purchaseTerms' => Setting::purchaseTerms(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'company_address' => ['nullable', 'string', 'max:500'],
            'company_city' => ['nullable', 'string', 'max:100'],
            'company_phone' => ['nullable', 'string', 'max:50'],
            'company_email' => ['nullable', 'email', 'max:255'],
            'company_npwp' => ['nullable', 'string', 'max:30'],
            'company_website' => ['nullable', 'string', 'max:255'],
            'document_footer' => ['nullable', 'string', 'max:300'],
            'purchase_terms' => ['nullable', 'string', 'max:1000'],
            'signers' => ['nullable', 'array'],
            'signers.*.name' => ['nullable', 'string', 'max:100'],
            'signers.*.title' => ['nullable', 'string', 'max:100'],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:1024'],
            'remove_logo' => ['nullable', 'boolean'],
            'invoice_tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'invoice_bank_info' => ['nullable', 'string', 'max:500'],
            'invoice_notes' => ['nullable', 'string', 'max:500'],
            'po_approval_threshold' => ['nullable', 'numeric', 'min:0'],
            'quotation_validity_days' => ['nullable', 'integer', 'between:1,365'],
            'quotation_terms' => ['nullable', 'string', 'max:1000'],
            'payroll_cycle' => ['nullable', 'in:'.implode(',', array_keys(Setting::PAYROLL_CYCLES))],
            'payroll_week_start' => ['nullable', 'integer', 'between:1,7'],
        ], [], ['invoice_tax_rate' => 'PPN default', 'invoice_bank_info' => 'rekening pembayaran', 'po_approval_threshold' => 'batas persetujuan PO', 'payroll_cycle' => 'siklus penggajian', 'quotation_validity_days' => 'masa berlaku penawaran', 'quotation_terms' => 'syarat penawaran', 'company_website' => 'website', 'document_footer' => 'catatan kaki dokumen', 'purchase_terms' => 'syarat PO', 'signers.*.name' => 'nama penanda tangan', 'signers.*.title' => 'jabatan penanda tangan']);

        foreach (array_keys(Setting::SIGNERS) as $key) {
            $data["signer_{$key}_name"] = $data['signers'][$key]['name'] ?? null;
            $data["signer_{$key}_title"] = $data['signers'][$key]['title'] ?? null;
        }
        unset($data['signers']);

        $data['payroll_cycle'] ??= 'weekly';
        $data['payroll_week_start'] = (int) ($data['payroll_week_start'] ?? 1);

        $data['po_approval_threshold'] = (float) ($data['po_approval_threshold'] ?? 0);

        $old = Setting::get('company_logo');

        if ($request->hasFile('logo')) {
            $data['company_logo'] = $request->file('logo')->store('company', 'public');
        } elseif ($request->boolean('remove_logo')) {
            $data['company_logo'] = null;
        }

        if (array_key_exists('company_logo', $data) && $old) {
            Storage::disk('public')->delete($old);
        }

        unset($data['logo'], $data['remove_logo']);
        Setting::put($data);
        ActivityLog::record('settings', 'Mengubah pengaturan perusahaan');

        return back()->with('success', 'Pengaturan perusahaan tersimpan.');
    }
}
