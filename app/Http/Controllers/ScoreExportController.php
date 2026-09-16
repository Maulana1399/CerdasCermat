<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Export nilai Cerdas Cermat sebagai CSV (No, Nama Tim, Nilai).
 *
 * Sumber nilai adalah kolom `score` pada tabel `teams` — total nilai yang
 * sama dengan yang tampil di halaman operator. Hanya regu aktif yang
 * diekspor, diurutkan sesuai tampilan operasional (sort_order, id).
 */
class ScoreExportController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        $teams = Team::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['name', 'score']);

        $filename = 'nilai-cerdas-cermat-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($teams): void {
            $handle = fopen('php://output', 'w');

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, ['No', 'Nama Tim', 'Nilai']);

            foreach ($teams as $index => $team) {
                fputcsv($handle, [$index + 1, $team->name, $team->score]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
