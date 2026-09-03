<?php

namespace App\Http\Controllers;

use App\Enums\ConversionDirection;
use App\Models\SpedConversion;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        $conversions = SpedConversion::query()
            ->where('user_id', $user->id)
            ->succeeded();

        $toSpreadsheet = (clone $conversions)->where('direction', ConversionDirection::ToSpreadsheet);
        $toText = (clone $conversions)->where('direction', ConversionDirection::ToText);

        return Inertia::render('Dashboard', [
            'metrics' => [
                [
                    'label' => 'Arquivos convertidos',
                    'value' => (int) (clone $toSpreadsheet)->sum('input_count'),
                    'hint' => 'SPED → Excel',
                ],
                [
                    'label' => 'Planilhas geradas',
                    'value' => (clone $toSpreadsheet)->count(),
                    'hint' => 'Uma por lote convertido',
                ],
                [
                    'label' => 'Arquivos reconstruídos',
                    'value' => (clone $toText)->count(),
                    'hint' => 'Excel → SPED',
                ],
                [
                    'label' => 'Conversões no mês',
                    'value' => (clone $conversions)->whereBetween('created_at', [
                        now()->startOfMonth(), now()->endOfMonth(),
                    ])->count(),
                    'hint' => now()->translatedFormat('F/Y'),
                ],
            ],
            'lastLoginAt' => $user->last_login_at?->toIso8601String(),
        ]);
    }
}
