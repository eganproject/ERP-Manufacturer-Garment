<?php

namespace App\Http\Controllers;

use App\Services\Import\Importer;
use App\Services\Import\ImportRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Pusat impor data massal (Excel .xlsx / .xls atau CSV): template, ekspor data untuk ubah massal, unggah.
 */
class ImportController extends Controller
{
    public function index()
    {
        return view('imports.index', ['importers' => ImportRegistry::allowed()]);
    }

    public function show(string $type)
    {
        return view('imports.show', ['importer' => $this->importer($type)]);
    }

    public function template(Request $request, string $type)
    {
        return $this->importer($type)->template($request->boolean('data'));
    }

    public function store(Request $request, string $type)
    {
        $importer = $this->importer($type);

        $request->validate([
            'file' => ['required', 'file', 'max:20480', 'extensions:xlsx,xls,csv,txt'],
        ], [
            'file.extensions' => 'File harus berformat Excel (.xlsx / .xls) atau CSV.',
        ], ['file' => 'file']);

        $result = $importer->import($request->file('file'));

        return redirect()->route('imports.show', $type)->with('success', "Impor {$importer->label()} selesai: {$result['created']} data baru, {$result['updated']} diperbarui.");
    }

    private function importer(string $type): Importer
    {
        $importer = ImportRegistry::find($type);
        abort_unless($importer, 404);
        Gate::authorize($importer->ability());

        return $importer;
    }
}
