<?php

namespace Tests\Feature;

use App\Models\ScoreEvent;
use App\Models\Team;
use App\Services\GameEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ScoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_skor_diupdate_dan_riwayat_tercatat_termasuk_last_score(): void
    {
        $team = Team::factory()->create(['score' => 0]);
        $engine = app(GameEngine::class);

        $engine->applyScore($team, 10, 'benar');
        $engine->applyScore($team, 5, 'benar');
        $engine->applyScore($team, -1, 'salah');

        $this->assertSame(14, $team->fresh()->score);
        $this->assertSame(3, ScoreEvent::count());
        $this->assertSame([10, 5, -1], ScoreEvent::query()->orderBy('id')->pluck('points')->all());

        $lastScore = $engine->snapshot()['last_score'];
        $this->assertSame($team->id, $lastScore['team']['id']);
        $this->assertSame(-1, $lastScore['points']);
    }

    public function test_skor_antar_regu_independen_dengan_nilai_custom_boleh_negatif(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $engine = app(GameEngine::class);

        $engine->applyScore($teams[0], 10, 'benar');
        $engine->applyScore($teams[1], -5, 'salah');
        $engine->applyScore($teams[0], -2, 'custom');

        $this->assertSame(8, $teams[0]->fresh()->score);
        $this->assertSame(-5, $teams[1]->fresh()->score);
    }

    public function test_pemenang_buzzer_dapat_diberi_nilai_setelah_menjawab(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $winner = $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $engine->resolveAnswer();

        $engine->applyScore($winner, 10, 'benar');

        $snapshot = $engine->snapshot();
        $this->assertSame('result', $snapshot['state']);
        $this->assertSame(10, $teams[0]->fresh()->score);
    }

    public function test_skor_tetap_nol_ketika_points_nol(): void
    {
        $team = Team::factory()->create(['score' => 0]);
        $engine = app(GameEngine::class);

        $engine->applyScore($team, 0, 'custom');

        $this->assertSame(0, $team->fresh()->score);
        $this->assertSame(0, ScoreEvent::count());
    }
}
