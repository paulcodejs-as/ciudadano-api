<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;

/** Documentación interactiva de la API (Swagger UI sobre resources/docs/openapi.yaml). */
class ApiDocsController extends Controller
{
    public function index(): View
    {
        return view('docs.index', ['specUrl' => route('docs.spec')]);
    }

    public function spec(): Response
    {
        return response(file_get_contents(resource_path('docs/openapi.yaml')), 200, [
            'Content-Type' => 'application/yaml; charset=utf-8',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
