<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\CrimeType;
use App\Models\Department;
use App\Services\CrimeStatsService;
use Illuminate\Contracts\View\View;

/** Página pública del mapa. Los datos los pide el navegador a /api/v1. */
class MapController extends Controller
{
    private const MONTHS = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'set', 'oct', 'nov', 'dic'];

    public function index(CrimeStatsService $stats): View
    {
        $latest = $stats->latestPeriod();
        $earliest = $stats->earliestPeriod();

        $years = [];
        if ($latest && $earliest) {
            for ($year = $latest->year; $year >= $earliest->year; $year--) {
                $partial = $year === $latest->year && $latest->month < 12;
                $years[] = [
                    'value' => $year,
                    'label' => $partial ? "{$year} (ene–".self::MONTHS[$latest->month - 1].')' : (string) $year,
                ];
            }
        }

        return view('map.index', [
            'crimeTypes' => CrimeType::orderBy('name')->get(['slug', 'name']),
            'departments' => Department::orderBy('name')->get(['code', 'name']),
            'years' => $years,
            'config' => [
                'apiBase' => url('/api/v1'),
                'cutoff' => config('ciudadano.virtual_reports_cutoff'),
                'center' => [-9.3, -75.0],
                'zoom' => 5,
                'months' => self::MONTHS,
                'firstPeriod' => $earliest?->format('Y-m'),
                'latestPeriod' => $latest?->format('Y-m'),
            ],
            'hasData' => $latest !== null,
        ]);
    }
}
