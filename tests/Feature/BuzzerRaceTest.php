<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Services\GameEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuzzerRaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_hanya_regu_pertama_yang_menjadi_pemenang_dan_lainnya_terkunci(): void
    {
        $teams = Team::factory()->count(4)->create();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();

        $this->assertSame($teams[0]->id, $engine->buzz($teams[0])->id);
        $this->assertNull($engine->buzz($teams[1]));
        $this->assertNull($engine->buzz($teams[2]));
        $this->assertNull($engine->buzz($teams[3]));

        $snapshot = $engine->snapshot();
        $this->assertSame('buzzed', $snapshot['state']);
        $this->assertSame($teams[0]->id, $snapshot['winner']['id']);
    }

    public function test_deretan_buzzer_banyak_regu_tetap_satu_pemenang(): void
    {
        $teams = Team::factory()->count(6)->create();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->buzz($teams[4]);
        $engine->buzz($teams[0]);
        $engine->buzz($teams[5]);

        $snapshot = $engine->snapshot();
        $this->assertSame($teams[4]->id, $snapshot['winner']['id']);
        $this->assertSame('buzzed', $snapshot['state']);
    }

    public function test_buzzer_dibuka_kembali_disertai_pemenang_baru_pada_soal_berikutnya(): void
    {
        $teams = Team::factory()->count(3)->create();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $this->assertSame($teams[1]->id, $engine->buzz($teams[1])->id);

        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->beginQuestion();

        $this->assertSame($teams[2]->id, $engine->buzz($teams[2])->id);
        $snapshot = $engine->snapshot();
        $this->assertSame(2, $snapshot['question_number']);
        $this->assertSame('buzzed', $snapshot['state']);
    }
}
