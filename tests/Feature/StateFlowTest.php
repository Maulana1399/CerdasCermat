<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Services\GameEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class StateFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_alur_lengkap_sesuai_flow_spec(): void
    {
        $teams = Team::factory()->count(4)->create();
        $engine = app(GameEngine::class);

        $this->assertSame('idle', $engine->snapshot()['state']);

        // START -> pertanyaan dimulai
        $engine->beginQuestion();
        $this->assertSame('buzzing', $engine->snapshot()['state']);

        // peserta menekan buzzer
        $engine->buzz($teams[2]);
        $this->assertSame('buzzed', $engine->snapshot()['state']);
        $this->assertSame($teams[2]->id, $engine->snapshot()['winner']['id']);

        // operator mempersilakan tim pemenang menjawab
        $engine->allowAnswer();
        $this->assertSame('answering', $engine->snapshot()['state']);

        // operator menutup sesi menjawab
        $engine->resolveAnswer();
        $this->assertSame('result', $engine->snapshot()['state']);

        // operator memilih nilai
        $engine->applyScore($teams[2], 10, 'benar');

        // LANJUT -> pertanyaan berikutnya dimulai
        $engine->beginQuestion();
        $snapshot = $engine->snapshot();
        $this->assertSame('buzzing', $snapshot['state']);
        $this->assertSame(2, $snapshot['question_number']);
        $this->assertNull($snapshot['winner']);
    }

    public function test_transisi_ilegal_ditolak(): void
    {
        $engine = app(GameEngine::class);

        $this->expectException(RuntimeException::class);

        $engine->allowAnswer();
    }

    public function test_memulai_kembali_saat_fase_berjalan_ditolak(): void
    {
        $teams = Team::factory()->count(2)->create();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();

        $this->expectException(RuntimeException::class);

        $engine->beginQuestion();
    }

    public function test_reset_membersihkan_skor_dan_state(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 5]);
        $engine = app(GameEngine::class);

        $engine->applyScore($teams[0], 10, 'benar');
        $engine->beginQuestion();
        $engine->reset();

        $snapshot = $engine->snapshot();
        $this->assertSame('idle', $snapshot['state']);
        $this->assertSame(0, $snapshot['question_number']);
        $this->assertNull($snapshot['winner']);
        $this->assertSame([0, 0, 0], array_column($snapshot['teams'], 'score'));
    }
}
