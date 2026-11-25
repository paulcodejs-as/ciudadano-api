<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreImportRequest;
use App\Models\Import;
use App\Services\ImportService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

class ImportController extends Controller
{
    public function index(ImportService $imports): View
    {
        return view('admin.imports.index', [
            'imports' => Import::with('user')->latest('id')->paginate(15),
            'summary' => $imports->dataSummary(),
            'uploadLimitMb' => round($imports->uploadLimitBytes() / 1024 / 1024, 1),
            'uploadLimitBytes' => $imports->uploadLimitBytes(),
        ]);
    }

    public function store(StoreImportRequest $request, ImportService $imports): RedirectResponse
    {
        $import = $imports->queueSidpolUpload($request->file('file'), $request->user());

        return redirect()->route('admin.imports.show', $import)
            ->with('status', "Archivo «{$import->file_name}» recibido.");
    }

    public function show(Import $import): View
    {
        return view('admin.imports.show', ['import' => $import->load('user')]);
    }
}
