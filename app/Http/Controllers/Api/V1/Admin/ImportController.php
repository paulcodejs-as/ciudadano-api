<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreImportRequest;
use App\Http\Resources\ImportResource;
use App\Models\Import;
use App\Services\ImportService;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ImportController extends Controller
{
    use ApiResponse;

    public function index(): JsonResponse
    {
        $imports = Import::with('user')->latest('id')->paginate(20);

        return $this->success(
            ImportResource::collection($imports->getCollection())->resolve(),
            'Importaciones.',
            [
                'current_page' => $imports->currentPage(),
                'last_page' => $imports->lastPage(),
                'per_page' => $imports->perPage(),
                'total' => $imports->total(),
            ],
        );
    }

    public function show(Import $import): JsonResponse
    {
        return $this->success(
            ImportResource::make($import->load('user'))->withLog()->resolve(),
            "Importación #{$import->id}.",
        );
    }

    /** Sube un CSV SIDPOL (multipart, campo "file") y lo encola. Responde 202. */
    public function store(StoreImportRequest $request, ImportService $imports): JsonResponse
    {
        $import = $imports->queueSidpolUpload($request->file('file'), $request->user());

        return $this->success(
            ImportResource::make($import)->withLog()->resolve(),
            'Archivo recibido. La importación está en proceso.',
            ['status_url' => route('api.v1.admin.imports.show', $import)],
            202,
        );
    }
}
