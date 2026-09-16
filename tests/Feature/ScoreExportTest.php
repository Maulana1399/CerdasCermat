<?php

namespace Tests\Feature;

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Export nilai Cerdas Cermat: No, Nama Tim, Nilai (CSV).
 */
class ScoreExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_nilai_berisi_no_nama_tim_dan_nilai_sesuai_urutan_operator(): void
    {
        Team::factory()->create(['name' => 'Regu Bravo', 'score' => 30, 'sort_order' => 2, 'is_active' => true]);
        Team::factory()->create(['name' => 'Regu Alpha', 'score' => 10, 'sort_order' => 1, 'is_active' => true]);
        Team::factory()->create(['name' => 'Regu Charlie', 'score' => 0, 'sort_order' => 3, 'is_active' => true]);

        $response = $this->get('/operator/export-nilai');

        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('.csv', (string) $response->headers->get('content-disposition'));

        $rows = $this->parseCsv($response->streamedContent());

        $this->assertSame(['No', 'Nama Tim', 'Nilai'], $rows[0]);
        $this->assertCount(3, $rows[0]);
        $this->assertSame(['1', 'Regu Alpha', '10'], $rows[1]);
        $this->assertSame(['2', 'Regu Bravo', '30'], $rows[2]);
        $this->assertSame(['3', 'Regu Charlie', '0'], $rows[3]);
    }

    public function test_export_nilai_hanya_menyertakan_regu_aktif(): void
    {
        Team::factory()->create(['name' => 'Regu Aktif', 'score' => 15, 'sort_order' => 1, 'is_active' => true]);
        Team::factory()->create(['name' => 'Regu Nonaktif', 'score' => 99, 'sort_order' => 0, 'is_active' => false]);

        $rows = $this->parseCsv($this->get('/operator/export-nilai')->streamedContent());

        $this->assertCount(2, $rows);
        $this->assertSame(['1', 'Regu Aktif', '15'], $rows[1]);
    }

    public function test_export_nilai_tetap_menyediakan_header_saat_belum_ada_regu(): void
    {
        $rows = $this->parseCsv($this->get('/operator/export-nilai')->streamedContent());

        $this->assertSame([['No', 'Nama Tim', 'Nilai']], $rows);
    }

    /**
     * @return array<int, array<int, string|null>>
     */
    private function parseCsv(string $content): array
    {
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);

        $lines = array_values(array_filter(
            explode("\n", trim($content)),
            fn (string $line): bool => trim($line) !== '',
        ));

        return array_map('str_getcsv', $lines);
    }
}
