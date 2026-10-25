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
        return view('settings.edit', ['company' => Setting::company()]);
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
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg', 'max:1024'],
            'remove_logo' => ['nullable', 'boolean'],
        ]);

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
