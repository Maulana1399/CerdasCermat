<?php

namespace Tests\Feature;

use App\Enums\GameType;
use App\Models\ScoreEvent;
use App\Models\Team;
use App\Services\GameEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Mode switching antara BUZZER dan RANKING 1.
 *
 * Aturan:
 * - Buzzer: boleh switch saat Result.
 * - Ranking 1: boleh switch saat semua regu aktif sudah dinilai.
 * - Skor, ScoreEvent, question_number dipertahankan.
 * - Tidak otomatis RESET / buat question baru / START.
 */
class ModeSwitchingTest extends TestCase
{
    use RefreshDatabase;

    private GameEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(GameEngine::class);
    }

    // ─── A. Buzzer Result → switch ke Ranking 1 berhasil ───

    public function test_buzzer_result_switch_ke_ranking_1(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);
        $engine = $this->engine;

        // Buzzer: begin → buzz → allow → resolve → score → Result
        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->applyScore($teams[0], 10, 'benar');

        $snap = $engine->snapshot();
        $this->assertSame('result', $snap['state']);
        $this->assertSame('buzzer', $snap['game_type']);

        // Switch
        $result = $engine->setGameType(GameType::Ranking1);
        $this->assertSame('ranking_1', $result->game_type->value);
        $this->assertSame('result', $result->state->value);
    }

    // ─── B. Ranking 1 4/4 selesai → switch ke Buzzer berhasil ───

    public function test_ranking_1_all_done_switch_ke_buzzer(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);
        $engine = $this->engine;

        $engine->setGameType(GameType::Ranking1);
        $engine->beginQuestion();

        // Judge all 4 teams
        foreach ($teams as $team) {
            $engine->judgeTeam($team, 10);
        }

        $snap = $engine->snapshot();
        $this->assertTrue($snap['ranking_all_answered']);

        // Switch
        $result = $engine->setGameType(GameType::Buzzer);
        $this->assertSame('buzzer', $result->game_type->value);
        $this->assertSame('result', $result->state->value);
    }

    // ─── C. Ranking 1 2/4 → switch ditolak ───

    public function test_ranking_1_partial_switch_ditolak(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);
        $engine = $this->engine;

        $engine->setGameType(GameType::Ranking1);
        $engine->beginQuestion();
        $engine->judgeTeam($teams[0], 10);
        $engine->judgeTeam($teams[1], 10);

        $snap = $engine->snapshot();
        $this->assertFalse($snap['ranking_all_answered']);

        $this->expectException(RuntimeException::class);
        $engine->setGameType(GameType::Buzzer);
    }

    // ─── D. Buzzer Buzzing → switch ditolak ───

    public function test_buzzer_buzzing_switch_ditolak(): void
    {
        $engine = $this->engine;

        $engine->beginQuestion();
        $snap = $engine->snapshot();
        $this->assertSame('buzzing', $snap['state']);

        $this->expectException(RuntimeException::class);
        $engine->setGameType(GameType::Ranking1);
    }

    // ─── E. Buzzer Answering → switch ditolak ───

    public function test_buzzer_answering_switch_ditolak(): void
    {
        $teams = Team::factory()->count(2)->create();
        $engine = $this->engine;

        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $snap = $engine->snapshot();
        $this->assertSame('answering', $snap['state']);

        $this->expectException(RuntimeException::class);
        $engine->setGameType(GameType::Ranking1);
    }

    // ─── F. Ranking 1 → Buzzer → Ranking 1 berhasil ───

    public function test_bolak_balik_ranking_buzzer_ranking(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);
        $engine = $this->engine;

        // Ranking 1 → all done
        $engine->setGameType(GameType::Ranking1);
        $engine->beginQuestion();
        foreach ($teams as $team) {
            $engine->judgeTeam($team, 5);
        }

        // Switch to Buzzer
        $result = $engine->setGameType(GameType::Buzzer);
        $this->assertSame('buzzer', $result->game_type->value);

        // Start a buzzer question and finish it
        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->applyScore($teams[0], 10, 'benar');

        // Switch back to Ranking 1
        $result = $engine->setGameType(GameType::Ranking1);
        $this->assertSame('ranking_1', $result->game_type->value);
        $this->assertSame('result', $result->state->value);
    }

    // ─── G. Skor tetap sama setelah setiap switch ───

    public function test_skor_tetap_sesudah_switch(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);
        $engine = $this->engine;

        // Buzzer: score team 0 = 10
        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->applyScore($teams[0], 10, 'benar');

        $before = $teams[0]->fresh()->score;
        $this->assertSame(10, $before);

        // Switch to ranking
        $engine->setGameType(GameType::Ranking1);

        $after = $teams[0]->fresh()->score;
        $this->assertSame(10, $after, 'Skor tidak berubah setelah switch');
    }

    // ─── H. ScoreEvent tetap ada ───

    public function test_score_event_tetap_ada_setelah_switch(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);
        $engine = $this->engine;

        // Buzzer: create score event
        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->applyScore($teams[0], 10, 'benar');

        $countBefore = ScoreEvent::count();
        $this->assertGreaterThan(0, $countBefore);

        // Switch
        $engine->setGameType(GameType::Ranking1);

        $this->assertSame($countBefore, ScoreEvent::count(), 'ScoreEvent tidak berubah setelah switch');
    }

    // ─── I. question_number tidak berubah hanya karena switch ───

    public function test_question_number_tidak_berubah_karena_switch(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);
        $engine = $this->engine;

        // Buzzer question
        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->applyScore($teams[0], 10, 'benar');

        $snap = $engine->snapshot();
        $qnum = $snap['question_number'];

        // Switch to ranking
        $engine->setGameType(GameType::Ranking1);

        $snap2 = $engine->snapshot();
        $this->assertSame($qnum, $snap2['question_number'], 'question_number tidak boleh berubah karena switch');
    }

    // ─── J. Switch tidak otomatis membuat question baru ───

    public function test_switch_tidak_otomatis_buat_question_baru(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);
        $engine = $this->engine;

        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->applyScore($teams[0], 10, 'benar');

        // Switch
        $engine->setGameType(GameType::Ranking1);

        $snap = $engine->snapshot();
        // State harus tetap result (soal selesai, bukan idle atau buzzing)
        $this->assertSame('result', $snap['state'], 'State harus tetap result setelah switch');
    }

    // ─── K. START setelah switch memulai mode tujuan dengan benar ───

    public function test_start_setelah_switch_memulai_mode_benar(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);
        $engine = $this->engine;

        // Finish buzzer question
        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->applyScore($teams[0], 10, 'benar');

        // Switch to ranking
        $engine->setGameType(GameType::Ranking1);

        // START ranking question
        $snap = $engine->beginQuestion();
        $this->assertSame('result', $snap->state->value, 'Ranking dimulai dalam state result');
        $this->assertSame('ranking_1', $snap->game_type->value);
        $this->assertSame(2, $snap->question_number, 'question_number naik saat START');
    }

    // ─── L. Existing Buzzer behavior tetap pass ───

    public function test_existing_buzzer_behavior_tetap_pass(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $engine = $this->engine;

        // Idle → Buzzer
        $snap = $engine->snapshot();
        $this->assertSame('buzzer', $snap['game_type']);
        $this->assertSame('idle', $snap['state']);

        // Idle boleh switch
        $engine->setGameType(GameType::Ranking1);
        $snap = $engine->snapshot();
        $this->assertSame('ranking_1', $snap['game_type']);

        // Switch back
        $engine->setGameType(GameType::Buzzer);
        $snap = $engine->snapshot();
        $this->assertSame('buzzer', $snap['game_type']);
    }

    // ─── M. Existing Ranking 1 behavior tetap pass ───

    public function test_existing_ranking_behavior_tetap_pass(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $engine = $this->engine;

        $engine->setGameType(GameType::Ranking1);
        $engine->beginQuestion();

        // Judge partial
        $engine->judgeTeam($teams[0], 10);
        $snap = $engine->snapshot();
        $this->assertFalse($snap['ranking_all_answered']);

        // Judge rest
        $engine->judgeTeam($teams[1], 5);
        $engine->judgeTeam($teams[2], 0);
        $snap = $engine->snapshot();
        $this->assertTrue($snap['ranking_all_answered']);

        // Scores correct
        $s0 = collect($snap['teams'])->firstWhere('id', $teams[0]->id);
        $s1 = collect($snap['teams'])->firstWhere('id', $teams[1]->id);
        $s2 = collect($snap['teams'])->firstWhere('id', $teams[2]->id);
        $this->assertSame(10, $s0['score']);
        $this->assertSame(5, $s1['score']);
        $this->assertSame(0, $s2['score']);
    }

    // ─── Additional: API integration tests ───

    public function test_api_switch_buzzer_result_ke_ranking(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);

        // Buzzer flow to Result
        $this->postJson('/api/game/begin')->assertOk();
        $this->postJson('/api/game/buzz', ['team_id' => $teams[0]->id])->assertOk();
        $this->postJson('/api/game/allow')->assertOk();
        $this->postJson('/api/game/answer', ['team_id' => $teams[0]->id, 'points' => 10, 'kind' => 'benar'])
            ->assertOk()
            ->assertJsonPath('snapshot.state', 'result');

        // Switch to ranking
        $this->postJson('/api/game/set-type', ['game_type' => 'ranking_1'])
            ->assertOk()
            ->assertJsonPath('snapshot.game_type', 'ranking_1')
            ->assertJsonPath('snapshot.state', 'result');
    }

    public function test_api_switch_ranking_partial_ditolak(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);

        $this->postJson('/api/game/set-type', ['game_type' => 'ranking_1'])->assertOk();
        $this->postJson('/api/game/begin')->assertOk();

        // Judge 2 of 4
        $this->postJson('/api/game/judge', ['team_id' => $teams[0]->id, 'points' => 10])->assertOk();
        $this->postJson('/api/game/judge', ['team_id' => $teams[1]->id, 'points' => 10])->assertOk();

        // Switch should fail
        $this->postJson('/api/game/set-type', ['game_type' => 'buzzer'])->assertStatus(422);
    }

    public function test_api_switch_ranking_all_done_ke_buzzer(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);

        $this->postJson('/api/game/set-type', ['game_type' => 'ranking_1'])->assertOk();
        $this->postJson('/api/game/begin')->assertOk();

        // Judge all
        $this->postJson('/api/game/judge', ['team_id' => $teams[0]->id, 'points' => 10])->assertOk();
        $this->postJson('/api/game/judge', ['team_id' => $teams[1]->id, 'points' => 5])->assertOk();

        // Switch
        $this->postJson('/api/game/set-type', ['game_type' => 'buzzer'])
            ->assertOk()
            ->assertJsonPath('snapshot.game_type', 'buzzer');
    }

    // ─── Lemparan → Buzzer/Ranking mode switching ───

    public function test_lemparan_benar_switch_ke_buzzer(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $engine = $this->engine;

        $engine->setGameType(GameType::Lemparan);
        $engine->beginThrowQuestion($teams[0]);
        $engine->judgeThrowTeam($teams[0], 10);

        $snap = $engine->snapshot();
        $this->assertSame('result', $snap['state']);
        $this->assertFalse($snap['throw_active']);

        // Switch to Buzzer — should succeed
        $result = $engine->setGameType(GameType::Buzzer);
        $this->assertSame('buzzer', $result->game_type->value);
    }

    public function test_lemparan_benar_switch_ke_ranking(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $engine = $this->engine;

        $engine->setGameType(GameType::Lemparan);
        $engine->beginThrowQuestion($teams[0]);
        $engine->judgeThrowTeam($teams[0], 10);

        $snap = $engine->snapshot();
        $this->assertFalse($snap['throw_active']);

        // Switch to Ranking 1 — should succeed
        $result = $engine->setGameType(GameType::Ranking1);
        $this->assertSame('ranking_1', $result->game_type->value);
    }

    public function test_lemparan_all_salah_switch_ke_buzzer(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $engine = $this->engine;

        $engine->setGameType(GameType::Lemparan);
        $engine->beginThrowQuestion($teams[0]);
        $engine->judgeThrowTeam($teams[0], 0);
        $engine->judgeThrowTeam(Team::find($engine->snapshot()['throw_current_team_id']), 0);
        $engine->judgeThrowTeam(Team::find($engine->snapshot()['throw_current_team_id']), 0);

        $snap = $engine->snapshot();
        $this->assertFalse($snap['throw_active']);
        $this->assertTrue($snap['throw_all_answered']);

        // Switch to Buzzer — should succeed
        $result = $engine->setGameType(GameType::Buzzer);
        $this->assertSame('buzzer', $result->game_type->value);
    }

    public function test_lemparan_all_salah_switch_ke_ranking(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $engine = $this->engine;

        $engine->setGameType(GameType::Lemparan);
        $engine->beginThrowQuestion($teams[0]);
        $engine->judgeThrowTeam($teams[0], 0);
        $engine->judgeThrowTeam(Team::find($engine->snapshot()['throw_current_team_id']), 0);
        $engine->judgeThrowTeam(Team::find($engine->snapshot()['throw_current_team_id']), 0);

        $snap = $engine->snapshot();
        $this->assertFalse($snap['throw_active']);

        // Switch to Ranking 1 — should succeed
        $result = $engine->setGameType(GameType::Ranking1);
        $this->assertSame('ranking_1', $result->game_type->value);
    }

    public function test_lemparan_active_switch_ditolak(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $engine = $this->engine;

        $engine->setGameType(GameType::Lemparan);
        $engine->beginThrowQuestion($teams[0]);
        $engine->judgeThrowTeam($teams[0], 0); // SALAH → throw_active=true

        $snap = $engine->snapshot();
        $this->assertTrue($snap['throw_active']);

        // Switch should fail — throw still active
        $this->expectException(RuntimeException::class);
        $engine->setGameType(GameType::Buzzer);
    }

    public function test_api_lemparan_benar_switch_ke_buzzer(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);

        $this->postJson('/api/game/set-type', ['game_type' => 'lemparan'])->assertOk();
        $this->postJson('/api/game/begin-throw', ['team_id' => $teams[0]->id])->assertOk();
        $this->postJson('/api/game/judge', ['team_id' => $teams[0]->id, 'points' => 10])
            ->assertOk()
            ->assertJsonPath('snapshot.throw_active', false);

        // Switch to buzzer
        $this->postJson('/api/game/set-type', ['game_type' => 'buzzer'])
            ->assertOk()
            ->assertJsonPath('snapshot.game_type', 'buzzer');
    }

    public function test_api_lemparan_all_salah_switch_ke_ranking(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);

        $this->postJson('/api/game/set-type', ['game_type' => 'lemparan'])->assertOk();
        $this->postJson('/api/game/begin-throw', ['team_id' => $teams[0]->id])->assertOk();
        $this->postJson('/api/game/judge', ['team_id' => $teams[0]->id, 'points' => 0])->assertOk();
        $this->postJson('/api/game/judge', ['team_id' => $teams[1]->id, 'points' => 0])
            ->assertOk()
            ->assertJsonPath('snapshot.throw_active', false)
            ->assertJsonPath('snapshot.throw_all_answered', true);

        // Switch to ranking
        $this->postJson('/api/game/set-type', ['game_type' => 'ranking_1'])
            ->assertOk()
            ->assertJsonPath('snapshot.game_type', 'ranking_1');
    }
}
