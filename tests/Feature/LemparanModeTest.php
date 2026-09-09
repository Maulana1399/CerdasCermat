<?php

namespace Tests\Feature;

use App\Enums\GameType;
use App\Models\ScoreEvent;
use App\Models\Team;
use App\Services\GameEngine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Mode Lemparan: pertanyaan diberikan ke satu tim,
 * jika salah dilempar ke tim berikutnya.
 */
class LemparanModeTest extends TestCase
{
    use RefreshDatabase;

    private GameEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(GameEngine::class);
    }

    private function setLemparan(): void
    {
        $this->engine->setGameType(GameType::Lemparan);
    }

    private function currentThrowTeamId(): ?int
    {
        return $this->engine->snapshot()['throw_current_team_id'] ?? null;
    }

    private function findTeam(Collection|array $teams, int $id): Team
    {
        foreach (is_array($teams) ? $teams : $teams->all() as $t) {
            if ($t->id === $id) {
                return $t;
            }
        }
        throw new RuntimeException("Team with id {$id} not found in array");
    }

    // ─── 1. Set mode LEMPARAN ───

    public function test_set_mode_lemparan(): void
    {
        Team::factory()->count(2)->create();
        $this->setLemparan();

        $snap = $this->engine->snapshot();
        $this->assertSame('lemparan', $snap['game_type']);
    }

    // ─── 2. START memilih tim pertama ───

    public function test_start_memilih_tim_pertama(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $this->setLemparan();

        $result = $this->engine->beginThrowQuestion($teams[0]);
        $this->assertSame('result', $result->state->value);
        $this->assertSame(1, $result->question_number);
        $this->assertSame($teams[0]->id, $result->last_score_team_id);
    }

    // ─── 3. Tim pertama benar → dapat poin → Result ───

    public function test_tim_pertama_benar_dapat_poin(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $result = $this->engine->judgeThrowTeam($teams[0], 10);

        $this->assertSame(10, $teams[0]->fresh()->score);
        $this->assertSame($teams[0]->id, $result->winner_team_id);
        $this->assertSame(10, $result->last_score_points);
    }

    // ─── 4. Tim pertama salah → giliran pindah ke tim berikutnya ───

    public function test_tim_pertama_salah_pindah_ke_tim_berikutnya(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $result = $this->engine->judgeThrowTeam($teams[0], 0);

        $this->assertNull($result->winner_team_id);
        $this->assertNotSame($teams[0]->id, $result->last_score_team_id);
        $this->assertNotNull($result->last_score_team_id);
        $this->assertSame(0, $teams[0]->fresh()->score);
    }

    // ─── 5. Tim kedua benar → tim kedua dapat poin → Result ───

    public function test_tim_kedua_benar_dapat_poin(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->engine->judgeThrowTeam($teams[0], 0);
        $nextId = $this->currentThrowTeamId();
        $this->assertNotNull($nextId);

        $result = $this->engine->judgeThrowTeam(Team::find($nextId), 5);

        $this->assertSame(5, Team::find($nextId)->fresh()->score);
        $this->assertSame($nextId, $result->winner_team_id);
    }

    // ─── 6. Beberapa tim salah lalu tim berikutnya benar ───

    public function test_bberapa_salah_lalu_benar(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->engine->judgeThrowTeam($teams[0], 0);

        $nextId = $this->currentThrowTeamId();
        $this->engine->judgeThrowTeam(Team::find($nextId), 0);

        $nextId2 = $this->currentThrowTeamId();
        $this->assertNotNull($nextId2);

        $result = $this->engine->judgeThrowTeam(Team::find($nextId2), 10);

        $this->assertSame($nextId2, $result->winner_team_id);
        $this->assertSame(10, Team::find($nextId2)->fresh()->score);
    }

    // ─── 7. Semua tim salah → Result tanpa poin ───

    public function test_semua_salah_tanpa_poin(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->engine->judgeThrowTeam($teams[0], 0);

        $nextId = $this->currentThrowTeamId();
        $this->engine->judgeThrowTeam(Team::find($nextId), 0);

        $nextId2 = $this->currentThrowTeamId();
        $this->assertNotNull($nextId2);

        $result = $this->engine->judgeThrowTeam(Team::find($nextId2), 0);

        $this->assertNull($result->winner_team_id);

        $snap = $this->engine->snapshot();
        $this->assertTrue($snap['throw_all_answered']);
    }

    // ─── 8. Tim yang sudah salah tidak bisa mendapat giliran kedua ───

    public function test_tim_salah_tidak_bisa_dapat_giliran_lagi(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->engine->judgeThrowTeam($teams[0], 0);

        $this->expectException(RuntimeException::class);
        $this->engine->judgeThrowTeam($teams[0], 10);
    }

    // ─── 9. Tim yang sudah dinilai tidak bisa dinilai ulang ───

    public function test_tim_sudah_dinilai_tidak_bisa_dinilai_ulang(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->engine->judgeThrowTeam($teams[0], 10);

        $this->expectException(RuntimeException::class);
        $this->engine->judgeThrowTeam($teams[0], 10);
    }

    // ─── 10. BENAR tidak bisa dieksekusi dua kali ───

    public function test_benar_tidak_bisa_dieksekusi_dua_kali(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->engine->judgeThrowTeam($teams[0], 10);

        $this->expectException(RuntimeException::class);
        $this->engine->judgeThrowTeam($teams[0], 10);
    }

    // ─── 11. SALAH tidak membuat ScoreEvent ───

    public function test_salah_tidak_membuat_score_event(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->engine->judgeThrowTeam($teams[0], 0);

        $this->assertSame(0, ScoreEvent::count());
    }

    // ─── 12. BENAR membuat tepat satu ScoreEvent ───

    public function test_benar_membuat_tepat_satu_score_event(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->engine->judgeThrowTeam($teams[0], 0);

        $nextId = $this->currentThrowTeamId();
        $this->assertNotNull($nextId);
        $this->engine->judgeThrowTeam(Team::find($nextId), 10);

        $this->assertSame(1, ScoreEvent::count());
        $event = ScoreEvent::first();
        $this->assertSame($nextId, $event->team_id);
        $this->assertSame(10, $event->points);
    }

    // ─── 13. Skor existing tetap dipertahankan ───

    public function test_skor_existing_tetap_dipertahankan(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 5]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->engine->judgeThrowTeam($teams[0], 10);

        $this->assertSame(15, $teams[0]->fresh()->score);
    }

    // ─── 14. question_number benar setelah LANJUT ───

    public function test_question_number_benar_setelah_lanjut(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->engine->judgeThrowTeam($teams[0], 10);

        $snap = $this->engine->snapshot();
        $this->assertSame(1, $snap['question_number']);

        $nextId = $this->currentThrowTeamId() ?? $teams[1]->id;
        $this->engine->beginThrowQuestion(Team::find($nextId));
        $snap = $this->engine->snapshot();
        $this->assertSame(2, $snap['question_number']);
    }

    // ─── 15. LANJUT hanya bisa setelah selesai ───

    public function test_lanjut_hanya_bisa_setelah_selesai(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);

        $this->expectException(RuntimeException::class);
        $this->engine->beginThrowQuestion($teams[1]);
    }

    // ─── 16. Lemparan dengan 2 tim ───

    public function test_lemparan_2_tim(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->engine->judgeThrowTeam($teams[0], 0);

        $nextId = $this->currentThrowTeamId();
        $this->assertNotNull($nextId);
        $result = $this->engine->judgeThrowTeam(Team::find($nextId), 10);

        $this->assertSame($nextId, $result->winner_team_id);
        $this->assertSame(10, Team::find($nextId)->fresh()->score);
    }

    // ─── 17. Lemparan dengan 3 tim ───

    public function test_lemparan_3_tim(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->engine->judgeThrowTeam($teams[0], 0);

        $nextId = $this->currentThrowTeamId();
        $this->engine->judgeThrowTeam(Team::find($nextId), 0);

        $nextId2 = $this->currentThrowTeamId();
        $this->assertNotNull($nextId2);
        $result = $this->engine->judgeThrowTeam(Team::find($nextId2), 10);

        $this->assertSame($nextId2, $result->winner_team_id);
    }

    // ─── 18. Lemparan dengan 4+ tim ───

    public function test_lemparan_4_tim(): void
    {
        $teams = Team::factory()->count(5)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->engine->judgeThrowTeam($teams[0], 0);

        // Judge 3 more wrong, then last one correct
        for ($i = 0; $i < 3; $i++) {
            $nextId = $this->currentThrowTeamId();
            $this->assertNotNull($nextId);
            $this->engine->judgeThrowTeam(Team::find($nextId), 0);
        }

        $lastId = $this->currentThrowTeamId();
        $this->assertNotNull($lastId);
        $result = $this->engine->judgeThrowTeam(Team::find($lastId), 10);

        $this->assertSame($lastId, $result->winner_team_id);
        $this->assertSame(10, Team::find($lastId)->fresh()->score);
    }

    // ─── 19. Tim inactive tidak ikut lemparan ───

    public function test_tim_inactive_tidak_ikut(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $teams[1]->update(['is_active' => false]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->engine->judgeThrowTeam($teams[0], 0);

        $nextId = $this->currentThrowTeamId();
        $this->assertNotSame($teams[1]->id, $nextId, 'Inactive team should not be next');
        $this->assertNotNull($nextId);

        $result = $this->engine->judgeThrowTeam(Team::find($nextId), 10);
        $this->assertSame($nextId, $result->winner_team_id);
    }

    // ─── 20. Jika hanya 1 tim aktif dan salah → Result ───

    public function test_satu_tim_aktif_salah(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $teams[1]->update(['is_active' => false]);
        $teams[2]->update(['is_active' => false]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $result = $this->engine->judgeThrowTeam($teams[0], 0);

        $this->assertNull($result->winner_team_id);
        $snap = $this->engine->snapshot();
        $this->assertTrue($snap['throw_all_answered']);
    }

    // ─── 21. Reset dari Lemparan partial ───

    public function test_reset_dari_lemparan_partial(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 5]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->engine->judgeThrowTeam($teams[0], 10);

        $result = $this->engine->reset();
        $this->assertSame('idle', $result->state->value);
        $this->assertSame('lemparan', $result->game_type->value);
        $this->assertSame(0, $result->question_number);
        $this->assertNull($result->last_score_team_id);

        foreach ($teams as $t) {
            $this->assertSame(0, $t->fresh()->score);
        }
    }

    // ─── 22. Reset dari Lemparan Result ───

    public function test_reset_dari_lemparan_result(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->engine->judgeThrowTeam($teams[0], 10);

        $result = $this->engine->reset();
        $this->assertSame('idle', $result->state->value);
        $this->assertSame(0, $result->question_number);
    }

    // ─── 23. Mode switching Lemparan selesai → Buzzer ───

    public function test_switch_lemparan_selesai_ke_buzzer(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->engine->judgeThrowTeam($teams[0], 10);

        $result = $this->engine->setGameType(GameType::Buzzer);
        $this->assertSame('buzzer', $result->game_type->value);
    }

    // ─── 24. Mode switching Lemparan selesai → Ranking 1 ───

    public function test_switch_lemparan_selesai_ke_ranking(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->engine->judgeThrowTeam($teams[0], 10);

        $result = $this->engine->setGameType(GameType::Ranking1);
        $this->assertSame('ranking_1', $result->game_type->value);
    }

    // ─── 25. Mode switching Lemparan partial → ditolak ───

    public function test_switch_lemparan_partial_ditolak(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->engine->judgeThrowTeam($teams[0], 0);

        $this->expectException(RuntimeException::class);
        $this->engine->setGameType(GameType::Buzzer);
    }

    // ─── 26. Existing Buzzer regression tetap pass ───

    public function test_existing_buzzer_tetap_pass(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $engine = $this->engine;

        $snap = $engine->snapshot();
        $this->assertSame('buzzer', $snap['game_type']);

        $engine->beginQuestion();
        $snap = $engine->snapshot();
        $this->assertSame('buzzing', $snap['state']);

        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->applyScore($teams[0], 10, 'benar');

        $snap = $engine->snapshot();
        $this->assertSame(10, collect($snap['teams'])->firstWhere('id', $teams[0]->id)['score']);
    }

    // ─── 27. Existing Ranking 1 regression tetap pass ───

    public function test_existing_ranking_tetap_pass(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $engine = $this->engine;

        $engine->setGameType(GameType::Ranking1);
        $engine->beginQuestion();

        $engine->judgeTeam($teams[0], 10);
        $engine->judgeTeam($teams[1], 5);
        $engine->judgeTeam($teams[2], 0);

        $snap = $engine->snapshot();
        $this->assertTrue($snap['ranking_all_answered']);

        $s0 = collect($snap['teams'])->firstWhere('id', $teams[0]->id);
        $s1 = collect($snap['teams'])->firstWhere('id', $teams[1]->id);
        $s2 = collect($snap['teams'])->firstWhere('id', $teams[2]->id);
        $this->assertSame(10, $s0['score']);
        $this->assertSame(5, $s1['score']);
        $this->assertSame(0, $s2['score']);
    }

    // ─── API integration tests ───

    public function test_api_full_lemparan_flow(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);

        $this->postJson('/api/game/set-type', ['game_type' => 'lemparan'])->assertOk()
            ->assertJsonPath('snapshot.game_type', 'lemparan');

        $this->postJson('/api/game/begin-throw', ['team_id' => $teams[0]->id])->assertOk()
            ->assertJsonPath('snapshot.state', 'result')
            ->assertJsonPath('snapshot.throw_current_team_id', $teams[0]->id)
            ->assertJsonPath('snapshot.question_number', 1);

        // Team 0 wrong → advance
        $res = $this->postJson('/api/game/judge', ['team_id' => $teams[0]->id, 'points' => 0])->assertOk();
        $nextId = $res->json('snapshot.throw_current_team_id');
        $this->assertNotSame($teams[0]->id, $nextId);

        // Next team correct
        $this->postJson('/api/game/judge', ['team_id' => $nextId, 'points' => 10])->assertOk()
            ->assertJsonPath('snapshot.winner.id', $nextId)
            ->assertJsonPath('snapshot.last_score.points', 10);
    }

    public function test_api_begin_throw_wrong_mode_ditolak(): void
    {
        $teams = Team::factory()->count(2)->create();

        $this->postJson('/api/game/begin-throw', ['team_id' => $teams[0]->id])->assertStatus(422);
    }

    public function test_api_judge_throw_wrong_team_ditolak(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);

        $this->postJson('/api/game/set-type', ['game_type' => 'lemparan'])->assertOk();
        $this->postJson('/api/game/begin-throw', ['team_id' => $teams[0]->id])->assertOk();

        $this->postJson('/api/game/judge', ['team_id' => $teams[1]->id, 'points' => 10])->assertStatus(422);
    }

    public function test_api_lemparan_all_wrong(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);

        $this->postJson('/api/game/set-type', ['game_type' => 'lemparan'])->assertOk();
        $this->postJson('/api/game/begin-throw', ['team_id' => $teams[0]->id])->assertOk();

        $res = $this->postJson('/api/game/judge', ['team_id' => $teams[0]->id, 'points' => 0])->assertOk();
        $nextId = $res->json('snapshot.throw_current_team_id');

        $this->postJson('/api/game/judge', ['team_id' => $nextId, 'points' => 0])->assertOk()
            ->assertJsonPath('snapshot.throw_all_answered', true)
            ->assertJsonPath('snapshot.winner', null);
    }

    /**
     * Regression: after BENAR, snapshot must show throw_active=false so frontend
     * knows throw is complete and can show LANJUT / team picker.
     */
    public function test_snapshot_after_benar_throw_active_false(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $result = $this->engine->judgeThrowTeam($teams[0], 10);

        $snap = $this->engine->snapshot();

        $this->assertFalse($snap['throw_active']);
        $this->assertFalse($snap['throw_all_answered']);
        $this->assertSame('result', $snap['state']);
        $this->assertSame($teams[0]->id, $snap['winner']['id']);
        $this->assertSame(10, $snap['last_score']['points']);
    }

    /**
     * Regression: after all SALAH, snapshot must show throw_active=false and
     * throw_all_answered=true so frontend can show LANJUT.
     */
    public function test_snapshot_after_all_salah_throw_active_false(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion(Team::find($teams[0]->id));
        $this->engine->judgeThrowTeam(Team::find($teams[0]->id), 0);
        $nextId = $this->currentThrowTeamId();
        $this->assertNotNull($nextId);
        $this->engine->judgeThrowTeam(Team::find($nextId), 0);
        $nextId2 = $this->currentThrowTeamId();
        $this->assertNotNull($nextId2);
        $this->engine->judgeThrowTeam(Team::find($nextId2), 0);

        $snap = $this->engine->snapshot();

        $this->assertFalse($snap['throw_active']);
        $this->assertTrue($snap['throw_all_answered']);
        $this->assertSame('result', $snap['state']);
        $this->assertNull($snap['winner']);
        $this->assertNull($snap['last_score']);
    }

    /**
     * Regression: snapshot after throw active (partial SALAH) must show throw_active=true
     * so frontend can show judge buttons (not LANJUT).
     */
    public function test_snapshot_during_throw_active_true(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->engine->judgeThrowTeam($teams[0], 0);

        $snap = $this->engine->snapshot();

        $this->assertTrue($snap['throw_active']);
        $this->assertFalse($snap['throw_all_answered']);
        $this->assertSame('result', $snap['state']);
        $this->assertNotNull($snap['throw_current_team_id']);
        $this->assertNotSame($teams[0]->id, $snap['throw_current_team_id']);
    }

    /**
     * Regression: after BENAR + LANJUT, beginThrow starts next question
     * and question_number increments by exactly 1.
     */
    public function test_benar_then_lanjut_starts_next_question(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->assertSame(1, $this->engine->snapshot()['question_number']);

        $this->engine->judgeThrowTeam($teams[0], 10);
        $this->assertSame(10, $teams[0]->fresh()->score);

        // LANJUT: start next throw question
        $nextTeam = $teams[1];
        $this->engine->beginThrowQuestion(Team::find($nextTeam->id));

        $snap = $this->engine->snapshot();
        $this->assertSame(2, $snap['question_number']);
        $this->assertTrue($snap['throw_active']);
        $this->assertSame($nextTeam->id, $snap['throw_current_team_id']);
        // Score preserved
        $this->assertSame(10, $teams[0]->fresh()->score);
    }

    /**
     * Regression: after all SALAH + LANJUT, beginThrow starts next question
     * with question_number incremented by 1 and scores preserved.
     */
    public function test_all_salah_then_lanjut_starts_next_question(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->engine->judgeThrowTeam($teams[0], 0);
        $this->engine->judgeThrowTeam(Team::find($this->currentThrowTeamId()), 0);
        $this->engine->judgeThrowTeam(Team::find($this->currentThrowTeamId()), 0);

        $this->assertSame(0, $teams[0]->fresh()->score);
        $this->assertSame(0, $teams[1]->fresh()->score);
        $this->assertSame(0, $teams[2]->fresh()->score);

        // LANJUT: start next throw question
        $this->engine->beginThrowQuestion(Team::find($teams[1]->id));

        $snap = $this->engine->snapshot();
        $this->assertSame(2, $snap['question_number']);
        $this->assertTrue($snap['throw_active']);
        $this->assertSame($teams[1]->id, $snap['throw_current_team_id']);
    }

    /**
     * Regression: no auto-start before team is picked.
     * After LANJUT (throw_active=false, state=result), beginThrow is required.
     */
    public function test_no_auto_start_before_team_picked(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->engine->judgeThrowTeam($teams[0], 10);

        $snap = $this->engine->snapshot();
        $this->assertFalse($snap['throw_active']);
        $this->assertSame('result', $snap['state']);

        // Cannot judge another team without beginThrow (no auto-start)
        $this->expectException(RuntimeException::class);
        $this->engine->judgeThrowTeam($teams[1], 10);
    }

    /**
     * Regression: after LANJUT, beginThrow requires team selection.
     */
    public function test_lanjut_then_begin_throw_requires_team(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $this->setLemparan();

        $this->engine->beginThrowQuestion($teams[0]);
        $this->engine->judgeThrowTeam($teams[0], 10);

        // beginThrow must be called explicitly with a team
        $this->engine->beginThrowQuestion(Team::find($teams[1]->id));

        $snap = $this->engine->snapshot();
        $this->assertTrue($snap['throw_active']);
        $this->assertSame($teams[1]->id, $snap['throw_current_team_id']);
        $this->assertSame(2, $snap['question_number']);
    }

    // ─── Circular order regression tests ───

    private function sortedTeamIds(Collection $teams): array
    {
        return Team::query()->whereIn('id', $teams->pluck('id'))
            ->orderBy('sort_order')->orderBy('id')->pluck('id')->all();
    }

    /**
     * Start B → B SALAH → current C (circular after B).
     */
    public function test_circular_start_b_salah_to_c(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);
        $this->setLemparan();

        $ids = $this->sortedTeamIds($teams);
        // Start from second team (B)
        $this->engine->beginThrowQuestion(Team::find($ids[1]));
        $this->engine->judgeThrowTeam(Team::find($ids[1]), 0);

        $nextId = $this->currentThrowTeamId();
        $this->assertNotNull($nextId);
        $this->assertSame($ids[2], $nextId, 'After B SALAH, next should be C');
    }

    /**
     * Start C → C SALAH → current D.
     */
    public function test_circular_start_c_salah_to_d(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);
        $this->setLemparan();

        $ids = $this->sortedTeamIds($teams);
        $this->engine->beginThrowQuestion(Team::find($ids[2]));
        $this->engine->judgeThrowTeam(Team::find($ids[2]), 0);

        $nextId = $this->currentThrowTeamId();
        $this->assertSame($ids[3], $nextId, 'After C SALAH, next should be D');
    }

    /**
     * Start D → D SALAH → current A (wrap around).
     */
    public function test_circular_start_d_salah_to_a(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);
        $this->setLemparan();

        $ids = $this->sortedTeamIds($teams);
        $this->engine->beginThrowQuestion(Team::find($ids[3]));
        $this->engine->judgeThrowTeam(Team::find($ids[3]), 0);

        $nextId = $this->currentThrowTeamId();
        $this->assertSame($ids[0], $nextId, 'After D SALAH, wrap to A');
    }

    /**
     * Start A → A SALAH → current B.
     */
    public function test_circular_start_a_salah_to_b(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);
        $this->setLemparan();

        $ids = $this->sortedTeamIds($teams);
        $this->engine->beginThrowQuestion(Team::find($ids[0]));
        $this->engine->judgeThrowTeam(Team::find($ids[0]), 0);

        $nextId = $this->currentThrowTeamId();
        $this->assertSame($ids[1], $nextId, 'After A SALAH, next should be B');
    }

    /**
     * Start B → B SALAH → C SALAH → current D.
     */
    public function test_circular_start_b_c_salah_to_d(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);
        $this->setLemparan();

        $ids = $this->sortedTeamIds($teams);
        $this->engine->beginThrowQuestion(Team::find($ids[1]));
        $this->engine->judgeThrowTeam(Team::find($ids[1]), 0);
        $this->engine->judgeThrowTeam(Team::find($ids[2]), 0);

        $nextId = $this->currentThrowTeamId();
        $this->assertSame($ids[3], $nextId, 'After B,C SALAH, next should be D');
    }

    /**
     * Start D → D SALAH → A SALAH → current B (wrap around through A).
     */
    public function test_circular_start_d_a_salah_to_b(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);
        $this->setLemparan();

        $ids = $this->sortedTeamIds($teams);
        $this->engine->beginThrowQuestion(Team::find($ids[3]));
        $this->engine->judgeThrowTeam(Team::find($ids[3]), 0);
        $this->engine->judgeThrowTeam(Team::find($ids[0]), 0);

        $nextId = $this->currentThrowTeamId();
        $this->assertSame($ids[1], $nextId, 'After D,A SALAH, wrap to B');
    }

    /**
     * Inactive team in the middle must be skipped.
     * Active ordered: A, C, D (B inactive)
     * Start C → C SALAH → D (skip B).
     */
    public function test_circular_inactive_team_skipped(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);
        // Deactivate second team (B)
        $b = $teams->sortBy('id')->values()->get(1);
        $b->update(['is_active' => false]);

        $this->setLemparan();

        // Active teams sorted by id: A(0), C(2), D(3)
        $ids = $this->sortedTeamIds($teams->where('is_active', true));
        $this->assertCount(3, $ids);

        $this->engine->beginThrowQuestion(Team::find($ids[1])); // C
        $this->engine->judgeThrowTeam(Team::find($ids[1]), 0);

        $nextId = $this->currentThrowTeamId();
        // Should go to D (next active after C), not B (inactive)
        $this->assertSame($ids[2], $nextId, 'After C SALAH, skip inactive B, go to D');
    }

    /**
     * All teams SALAH → throw finished.
     */
    public function test_circular_all_salah_throw_finished(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);
        $this->setLemparan();

        $ids = $this->sortedTeamIds($teams);
        $this->engine->beginThrowQuestion(Team::find($ids[0]));
        $this->engine->judgeThrowTeam(Team::find($ids[0]), 0);
        $this->engine->judgeThrowTeam(Team::find($ids[1]), 0);
        $this->engine->judgeThrowTeam(Team::find($ids[2]), 0);
        $this->engine->judgeThrowTeam(Team::find($ids[3]), 0);

        $snap = $this->engine->snapshot();
        $this->assertFalse($snap['throw_active']);
        $this->assertTrue($snap['throw_all_answered']);
        $this->assertNull($snap['winner']);
    }

    /**
     * Team that already answered SALAH must not get another turn.
     */
    public function test_circular_no_double_turn(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $this->setLemparan();

        $ids = $this->sortedTeamIds($teams);
        $this->engine->beginThrowQuestion(Team::find($ids[0]));
        $this->engine->judgeThrowTeam(Team::find($ids[0]), 0);
        $this->engine->judgeThrowTeam(Team::find($ids[1]), 0);
        $this->engine->judgeThrowTeam(Team::find($ids[2]), 0);

        $snap = $this->engine->snapshot();
        $this->assertFalse($snap['throw_active']);
        $this->assertSame(3, count($snap['throw_answered_team_ids']));
    }
}
