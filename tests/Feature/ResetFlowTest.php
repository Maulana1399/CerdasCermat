<?php

namespace Tests\Feature;

use App\Models\ScoreEvent;
use App\Models\Team;
use App\Services\GameEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Regression test: RESET harus bekerja di SEMUA mode dan state.
 */
class ResetFlowTest extends TestCase
{
    use RefreshDatabase;

    // ─── A. RESET dari BUZZER setelah ada skor/winner ───

    public function test_reset_buzzer_setelah_ada_skor_dan_winner(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->applyScore($teams[0], 10, 'benar');

        $before = $engine->snapshot();
        $this->assertSame('result', $before['state']);
        $this->assertSame(10, collect($before['teams'])->firstWhere('id', $teams[0]->id)['score']);
        $this->assertNotNull($before['winner']);
        $this->assertNotNull($before['last_score']);

        $engine->reset();

        $after = $engine->snapshot();
        $this->assertSame('idle', $after['state']);
        $this->assertSame(0, $after['question_number']);
        $this->assertSame(0, collect($after['teams'])->firstWhere('id', $teams[0]->id)['score']);
        $this->assertSame(0, collect($after['teams'])->firstWhere('id', $teams[1]->id)['score']);
        $this->assertNull($after['winner']);
        $this->assertNull($after['last_score']);
        $this->assertNull($after['buzz_deadline_at']);
        $this->assertNull($after['answer_deadline_at']);
    }

    // ─── B. RESET dari BUZZER pada Result ───

    public function test_reset_buzzer_saat_result(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $engine->resolveAnswer();

        $before = $engine->snapshot();
        $this->assertSame('result', $before['state']);

        $engine->reset();

        $after = $engine->snapshot();
        $this->assertSame('idle', $after['state']);
        $this->assertSame(0, $after['question_number']);
        $this->assertNull($after['winner']);
        $this->assertNull($after['last_score']);
    }

    // ─── C. RESET dari RANKING_1 setelah beberapa tim sudah dinilai ───

    public function test_reset_ranking1_setelah_beberapa_tim_dinilai(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);
        $this->postJson('/api/game/set-type', ['game_type' => 'ranking_1'])->assertOk();
        $this->postJson('/api/game/begin')->assertOk();

        $this->postJson('/api/game/judge', ['team_id' => $teams[0]->id, 'points' => 10])->assertOk();
        $this->postJson('/api/game/judge', ['team_id' => $teams[1]->id, 'points' => 5])->assertOk();

        $before = $this->getJson('/api/game/state')->json('snapshot');
        $this->assertSame('result', $before['state']);
        $this->assertSame(1, $before['question_number']);
        $this->assertCount(2, $before['ranking_answered_team_ids']);
        $this->assertSame(10, collect($before['teams'])->firstWhere('id', $teams[0]->id)['score']);
        $this->assertSame(5, collect($before['teams'])->firstWhere('id', $teams[1]->id)['score']);
        $this->assertSame(0, collect($before['teams'])->firstWhere('id', $teams[2]->id)['score']);
        $this->assertSame(0, collect($before['teams'])->firstWhere('id', $teams[3]->id)['score']);

        $reset = $this->postJson('/api/game/reset')->assertOk();
        $after = $reset->json('snapshot');

        $this->assertSame('idle', $after['state']);
        $this->assertSame(0, $after['question_number']);
        $this->assertSame(0, collect($after['teams'])->firstWhere('id', $teams[0]->id)['score']);
        $this->assertSame(0, collect($after['teams'])->firstWhere('id', $teams[1]->id)['score']);
        $this->assertSame(0, collect($after['teams'])->firstWhere('id', $teams[2]->id)['score']);
        $this->assertSame(0, collect($after['teams'])->firstWhere('id', $teams[3]->id)['score']);
        $this->assertEmpty($after['ranking_answered_team_ids']);
        $this->assertNull($after['winner']);
        $this->assertNull($after['last_score']);
        $this->assertNull($after['buzz_deadline_at']);
        $this->assertNull($after['answer_deadline_at']);
    }

    // ─── D. Pastikan skor kembali 0 ───

    public function test_reset_skor_kembali_nol(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->applyScore($teams[0], 20, 'benar');
        $engine->applyScore($teams[1], -5, 'salah');

        $engine->reset();

        $snap = $engine->snapshot();
        foreach ($snap['teams'] as $t) {
            $this->assertSame(0, $t['score'], 'Skor harus 0 setelah reset untuk '.$t['name']);
        }
    }

    // ─── E. Pastikan ranking_answered_team_ids kosong ───

    public function test_reset_ranking_answered_kosong(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $this->postJson('/api/game/set-type', ['game_type' => 'ranking_1'])->assertOk();
        $this->postJson('/api/game/begin')->assertOk();

        $this->postJson('/api/game/judge', ['team_id' => $teams[0]->id, 'points' => 10])->assertOk();
        $this->postJson('/api/game/judge', ['team_id' => $teams[1]->id, 'points' => 10])->assertOk();

        $before = $this->getJson('/api/game/state')->json('snapshot');
        $this->assertCount(2, $before['ranking_answered_team_ids']);

        $this->postJson('/api/game/reset')->assertOk();

        $after = $this->getJson('/api/game/state')->json('snapshot');
        $this->assertEmpty($after['ranking_answered_team_ids']);
    }

    // ─── F. Pastikan winner/deadline/hasil dibersihkan ───

    public function test_reset_bersihkan_winner_deadline_hasil(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->buzz($teams[0]);

        $snap = $engine->snapshot();
        $this->assertNotNull($snap['winner']);
        $this->assertNotNull($snap['buzz_deadline_at']);

        $engine->allowAnswer();
        $snap = $engine->snapshot();
        $this->assertNotNull($snap['answer_deadline_at']);

        $engine->resolveAnswer();
        $engine->applyScore($teams[0], 10, 'benar');
        $snap = $engine->snapshot();
        $this->assertNotNull($snap['last_score']);

        $engine->reset();

        $snap = $engine->snapshot();
        $this->assertNull($snap['winner']);
        $this->assertNull($snap['buzz_deadline_at']);
        $this->assertNull($snap['answer_deadline_at']);
        $this->assertNull($snap['last_score']);
        $this->assertNull($snap['phase_started_at']);
    }

    // ─── G. Pastikan setelah RESET bisa START lagi ───

    public function test_setelah_reset_bisa_start_lagi_buzzer(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->applyScore($teams[0], 10, 'benar');

        $engine->reset();
        $this->assertSame('idle', $engine->snapshot()['state']);

        $engine->beginQuestion();
        $snap = $engine->snapshot();
        $this->assertSame('buzzing', $snap['state']);
        $this->assertSame(1, $snap['question_number']);
        $this->assertTrue($snap['buzzer_open']);

        $engine->buzz($teams[1]);
        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->applyScore($teams[1], 5, 'benar');

        $snap = $engine->snapshot();
        $this->assertSame('result', $snap['state']);
        $this->assertSame(5, collect($snap['teams'])->firstWhere('id', $teams[1]->id)['score']);
    }

    public function test_setelah_reset_bisa_start_lagi_ranking1(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $this->postJson('/api/game/set-type', ['game_type' => 'ranking_1'])->assertOk();

        $this->postJson('/api/game/begin')->assertOk();
        $this->postJson('/api/game/judge', ['team_id' => $teams[0]->id, 'points' => 10])->assertOk();
        $this->postJson('/api/game/judge', ['team_id' => $teams[1]->id, 'points' => 5])->assertOk();

        $this->postJson('/api/game/reset')->assertOk()
            ->assertJsonPath('snapshot.state', 'idle')
            ->assertJsonPath('snapshot.question_number', 0);

        $this->postJson('/api/game/begin')->assertOk()
            ->assertJsonPath('snapshot.state', 'result')
            ->assertJsonPath('snapshot.question_number', 1);

        $this->postJson('/api/game/judge', ['team_id' => $teams[0]->id, 'points' => 10])->assertOk();
        $this->postJson('/api/game/judge', ['team_id' => $teams[1]->id, 'points' => 0])->assertOk();

        $snap = $this->getJson('/api/game/state')->json('snapshot');
        $this->assertSame(10, collect($snap['teams'])->firstWhere('id', $teams[0]->id)['score']);
        $this->assertSame(0, collect($snap['teams'])->firstWhere('id', $teams[1]->id)['score']);
    }

    // ─── H. Pastikan mode tetap konsisten ───

    public function test_reset_tidak_mengubah_game_type_buzzer(): void
    {
        $teams = Team::factory()->count(2)->create();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->reset();

        $snap = $engine->snapshot();
        $this->assertSame('buzzer', $snap['game_type']);
    }

    public function test_reset_tidak_mengubah_game_type_ranking1(): void
    {
        $teams = Team::factory()->count(2)->create();
        $this->postJson('/api/game/set-type', ['game_type' => 'ranking_1'])->assertOk();
        $this->postJson('/api/game/begin')->assertOk();
        $this->postJson('/api/game/judge', ['team_id' => $teams[0]->id, 'points' => 10])->assertOk();

        $this->postJson('/api/game/reset')->assertOk()
            ->assertJsonPath('snapshot.game_type', 'ranking_1')
            ->assertJsonPath('snapshot.state', 'idle');
    }

    // ─── Tidak membuat ScoreEvent baru ───

    public function test_reset_tidak_membuat_score_event_baru(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->applyScore($teams[0], 10, 'benar');

        $this->assertSame(1, ScoreEvent::count());

        $engine->reset();

        $this->assertSame(0, ScoreEvent::count());
    }

    // ─── Tidak merusak alur selanjutnya ───

    public function test_reset_tidak_merusak_alur_buzzer_selanjutnya(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $engine = app(GameEngine::class);

        $engine->reset();

        $engine->beginQuestion();
        $this->assertSame('buzzing', $engine->snapshot()['state']);

        $engine->buzz($teams[0]);
        $this->assertSame('buzzed', $engine->snapshot()['state']);

        $engine->allowAnswer();
        $this->assertSame('answering', $engine->snapshot()['state']);

        $engine->resolveAnswer();
        $engine->applyScore($teams[0], 10, 'benar');
        $this->assertSame('result', $engine->snapshot()['state']);

        $engine->beginQuestion();
        $this->assertSame('buzzing', $engine->snapshot()['state']);
        $this->assertSame(2, $engine->snapshot()['question_number']);
    }

    // ─── API: RESET bisa dipanggil dari berbagai state ───

    public function test_api_reset_from_buzzer_result(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);

        $this->postJson('/api/game/begin')->assertOk();
        $this->postJson('/api/game/buzz', ['team_id' => $teams[0]->id])->assertOk();
        $this->postJson('/api/game/allow')->assertOk();
        $this->postJson('/api/game/answer', ['team_id' => $teams[0]->id, 'points' => 10])->assertOk()
            ->assertJsonPath('snapshot.state', 'result');

        $this->postJson('/api/game/reset')->assertOk()
            ->assertJsonPath('snapshot.state', 'idle')
            ->assertJsonPath('snapshot.question_number', 0);
    }

    public function test_api_reset_from_ranking1_partial(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);

        $this->postJson('/api/game/set-type', ['game_type' => 'ranking_1'])->assertOk();
        $this->postJson('/api/game/begin')->assertOk();
        $this->postJson('/api/game/judge', ['team_id' => $teams[0]->id, 'points' => 10])->assertOk();
        $this->postJson('/api/game/judge', ['team_id' => $teams[1]->id, 'points' => 5])->assertOk();

        $this->postJson('/api/game/reset')->assertOk()
            ->assertJsonPath('snapshot.state', 'idle')
            ->assertJsonPath('snapshot.question_number', 0)
            ->assertJsonPath('snapshot.game_type', 'ranking_1');
    }

    // ─── Switch mode setelah reset ───

    public function test_switch_mode_setelah_reset(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);

        $this->postJson('/api/game/begin')->assertOk();
        $this->postJson('/api/game/buzz', ['team_id' => $teams[0]->id])->assertOk();
        $this->postJson('/api/game/allow')->assertOk();
        $this->postJson('/api/game/answer', ['team_id' => $teams[0]->id, 'points' => 10])->assertOk();
        $this->postJson('/api/game/reset')->assertOk();

        $this->postJson('/api/game/set-type', ['game_type' => 'ranking_1'])->assertOk()
            ->assertJsonPath('snapshot.game_type', 'ranking_1');

        $this->postJson('/api/game/begin')->assertOk()
            ->assertJsonPath('snapshot.state', 'result')
            ->assertJsonPath('snapshot.game_type', 'ranking_1');
    }
}
