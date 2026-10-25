<?php

namespace App\Support;

use App\Models\Setting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Render dokumen transaksi (PO, SO, surat jalan, SPK, dll) ke PDF A4.
 */
class DocumentPdf
{
    public static function stream(string $view, array $data, string $filename, string $orientation = 'portrait'): Response
    {
        $data['company'] = self::company();

        return Pdf::loadView($view, $data)
            ->setPaper('a4', $orientation)
            ->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false])
            ->stream($filename.'.pdf');
    }

    /**
     * Profil perusahaan + logo dalam bentuk data URI agar tidak perlu akses file dari dompdf.
     *
     * @return array<string, string|null>
     */
    public static function company(): array
    {
        $company = Setting::company();
        $company['logo_data'] = null;

        if ($company['logo'] && Storage::disk('public')->exists($company['logo'])) {
            $mime = Storage::disk('public')->mimeType($company['logo']);
            $company['logo_data'] = 'data:'.$mime.';base64,'.base64_encode(Storage::disk('public')->get($company['logo']));
        }

        return $company;
    }
}
