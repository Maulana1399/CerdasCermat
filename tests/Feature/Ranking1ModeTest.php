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
 * Mode Ranking 1: semua regu mendapat pertanyaan sama,
 * operator menilai satu per satu, BENAR dapat poin, SALAH 0.
 */
class Ranking1ModeTest extends TestCase
{
    use RefreshDatabase;

    private function setRanking1(): void
    {
        $engine = app(GameEngine::class);
        $engine->setGameType(GameType::Ranking1);
    }

    // ─── 1. Mode Ranking 1 dapat dimulai ───

    public function test_mode_ranking_1_dapat_dimulai(): void
    {
        $teams = Team::factory()->count(3)->create();
        $this->setRanking1();

        $snapshot = app(GameEngine::class)->beginQuestion()->refresh();
        $engine = app(GameEngine::class);
        $snap = $engine->snapshot();

        $this->assertSame('ranking_1', $snap['game_type']);
        $this->assertSame('result', $snap['state']);
        $this->assertSame(1, $snap['question_number']);
        $this->assertNull($snap['winner']);
        $this->assertEmpty($snap['ranking_answered_team_ids']);
        $this->assertFalse($snap['ranking_all_answered']);
    }

    // ─── 2. Semua tim dapat mendapat kesempatan menjawab ───

    public function test_semua_regu_mendapat_kesempatan_menjawab(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);
        $this->setRanking1();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();

        // Urutan: tim kedua, pertama, ketiga, keempat
        $engine->judgeTeam($teams[1], 10);
        $engine->judgeTeam($teams[0], 10);
        $engine->judgeTeam($teams[2], 10);
        $engine->judgeTeam($teams[3], 10);

        $snap = $engine->snapshot();
        $this->assertCount(4, $snap['ranking_answered_team_ids']);
        $this->assertTrue($snap['ranking_all_answered']);
    }

    // ─── 3. Tim yang BENAR mendapat poin sesuai nilai ───

    public function test_tim_benar_mendapat_poin_sesuai_nilai(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $this->setRanking1();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->judgeTeam($teams[0], 10);

        $snap = $engine->snapshot();
        $scored = collect($snap['teams'])->firstWhere('id', $teams[0]->id);
        $this->assertSame(10, $scored['score']);
        $this->assertSame(10, $snap['last_score']['points']);
        $this->assertSame($teams[0]->id, $snap['last_score']['team']['id']);
    }

    // ─── 4. Tim yang SALAH mendapat 0 dan tidak terkena minus ───

    public function test_tim_salah_mendapat_0_tanpa_pengurangan(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 5]);
        $this->setRanking1();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->judgeTeam($teams[0], 0);

        $snap = $engine->snapshot();
        $scored = collect($snap['teams'])->firstWhere('id', $teams[0]->id);
        $this->assertSame(5, $scored['score'], 'Skor tidak berubah untuk SALAH');
        $this->assertSame(0, $snap['last_score']['points']);
    }

    public function test_salah_score_event_tidak_dibuat(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $this->setRanking1();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->judgeTeam($teams[0], 0);

        $this->assertSame(0, ScoreEvent::count(), 'ScoreEvent tidak dibuat untuk SALAH');
    }

    // ─── 5. Tim tidak dapat dinilai dua kali pada pertanyaan yang sama ───

    public function test_tim_tidak_dapat_dinilai_dua_kali(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $this->setRanking1();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->judgeTeam($teams[0], 10);

        $this->expectException(RuntimeException::class);
        $engine->judgeTeam($teams[0], 10);
    }

    public function test_dinilai_dua_kali_tidak_menambah_poin(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $this->setRanking1();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->judgeTeam($teams[0], 10);

        try {
            $engine->judgeTeam($teams[0], 10);
        } catch (RuntimeException $e) {
            // Expected
        }

        $snap = $engine->snapshot();
        $scored = collect($snap['teams'])->firstWhere('id', $teams[0]->id);
        $this->assertSame(10, $scored['score'], 'Poin hanya ditambahkan sekali');
    }

    // ─── 6. Tim yang sudah selesai tidak dapat menjawab lagi ───

    public function test_tim_yang_sudah_selesai_tidak_dapat_menjawab_lagi(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $this->setRanking1();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->judgeTeam($teams[0], 10);
        $engine->judgeTeam($teams[1], 5);

        $snap = $engine->snapshot();
        $this->assertContains($teams[0]->id, $snap['ranking_answered_team_ids']);
        $this->assertContains($teams[1]->id, $snap['ranking_answered_team_ids']);
        $this->assertNotContains($teams[2]->id, $snap['ranking_answered_team_ids']);
    }

    // ─── 7. Semua tim selesai → LANJUT menuju pertanyaan berikutnya ───

    public function test_semua_selesai_lanjut_ke_pertanyaan_berikutnya(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $this->setRanking1();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->judgeTeam($teams[0], 10);
        $engine->judgeTeam($teams[1], 5);

        $snap = $engine->snapshot();
        $this->assertTrue($snap['ranking_all_answered']);

        // LANJUT
        $engine->beginQuestion();
        $snap = $engine->snapshot();
        $this->assertSame('result', $snap['state']);
        $this->assertSame(2, $snap['question_number']);
        $this->assertEmpty($snap['ranking_answered_team_ids']);
    }

    // ─── 8. Skor tetap benar setelah LANJUT ───

    public function test_skor_tetap_benar_setelah_lanjut(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $this->setRanking1();
        $engine = app(GameEngine::class);

        // Pertanyaan 1
        $engine->beginQuestion();
        $engine->judgeTeam($teams[0], 10);
        $engine->judgeTeam($teams[1], 5);
        $engine->judgeTeam($teams[2], 0);

        // Pertanyaan 2
        $engine->beginQuestion();
        $engine->judgeTeam($teams[0], 10);
        $engine->judgeTeam($teams[1], 0);
        $engine->judgeTeam($teams[2], 10);

        $snap = $engine->snapshot();
        $s0 = collect($snap['teams'])->firstWhere('id', $teams[0]->id);
        $s1 = collect($snap['teams'])->firstWhere('id', $teams[1]->id);
        $s2 = collect($snap['teams'])->firstWhere('id', $teams[2]->id);

        $this->assertSame(20, $s0['score']);
        $this->assertSame(5, $s1['score']);
        $this->assertSame(10, $s2['score']);
    }

    // ─── 9. Mode Buzzer/Rebutan existing tetap PASS ───

    public function test_mode_buzzer_existing_tetap_bekerja(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $engine = app(GameEngine::class);

        // Default adalah Buzzer
        $snap = $engine->snapshot();
        $this->assertSame('buzzer', $snap['game_type']);

        $engine->beginQuestion();
        $snap = $engine->snapshot();
        $this->assertSame('buzzing', $snap['state']);
        $this->assertTrue($snap['buzzer_open']);

        $engine->buzz($teams[0]);
        $snap = $engine->snapshot();
        $this->assertSame('buzzed', $snap['state']);
        $this->assertSame($teams[0]->id, $snap['winner']['id']);

        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->applyScore($teams[0], 10, 'benar');

        $snap = $engine->snapshot();
        $this->assertSame(10, collect($snap['teams'])->firstWhere('id', $teams[0]->id)['score']);
    }

    // ─── 10. State tidak valid ditolak ───

    public function test_judge_team_ditolak_di_mode_buzzer(): void
    {
        $teams = Team::factory()->count(2)->create();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->buzz($teams[0]);

        $this->expectException(RuntimeException::class);
        $engine->judgeTeam($teams[0], 10);
    }

    public function test_judge_team_ditolak_sebelum_mulai_pertanyaan(): void
    {
        $teams = Team::factory()->count(2)->create();
        $this->setRanking1();
        $engine = app(GameEngine::class);

        $this->expectException(RuntimeException::class);
        $engine->judgeTeam($teams[0], 10);
    }

    public function test_buzz_ditolak_di_mode_ranking_1(): void
    {
        $teams = Team::factory()->count(2)->create();
        $this->setRanking1();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();

        $result = $engine->buzz($teams[0]);
        $this->assertNull($result, 'Buzzer tidak boleh berhasil di mode Ranking 1');
    }

    public function test_repeat_ditolak_di_mode_ranking_1(): void
    {
        $teams = Team::factory()->count(2)->create();
        $this->setRanking1();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->judgeTeam($teams[0], 10);

        $this->expectException(RuntimeException::class);
        $engine->repeatQuestion();
    }

    public function test_set_game_type_ditolak_saat_game_berjalan(): void
    {
        $teams = Team::factory()->count(2)->create();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();

        $this->expectException(RuntimeException::class);
        $engine->setGameType(GameType::Ranking1);
    }

    // ─── API integration tests ───

    public function test_api_set_game_type(): void
    {
        Team::factory()->count(2)->create();

        $this->postJson('/api/game/set-type', ['game_type' => 'ranking_1'])
            ->assertOk()
            ->assertJsonPath('snapshot.game_type', 'ranking_1');
    }

    public function test_api_set_game_type_invalid_ditolak(): void
    {
        Team::factory()->count(2)->create();

        $this->postJson('/api/game/set-type', ['game_type' => 'invalid'])
            ->assertStatus(422);
    }

    public function test_api_judge_team(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);

        $this->postJson('/api/game/set-type', ['game_type' => 'ranking_1'])->assertOk();
        $this->postJson('/api/game/begin')->assertOk();

        $this->postJson('/api/game/judge', ['team_id' => $teams[0]->id, 'points' => 10])
            ->assertOk()
            ->assertJsonPath('snapshot.last_score.points', 10);
    }

    public function test_api_judge_team_ditolak_saat_buzzer_mode(): void
    {
        $teams = Team::factory()->count(2)->create();

        $this->postJson('/api/game/begin')->assertOk();

        $this->postJson('/api/game/judge', ['team_id' => $teams[0]->id, 'points' => 10])
            ->assertStatus(422);
    }

    public function test_api_full_ranking_flow(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);

        // Set mode
        $this->postJson('/api/game/set-type', ['game_type' => 'ranking_1'])->assertOk();

        // Start question
        $this->postJson('/api/game/begin')->assertOk()
            ->assertJsonPath('snapshot.state', 'result')
            ->assertJsonPath('snapshot.question_number', 1);

        // Judge teams
        $this->postJson('/api/game/judge', ['team_id' => $teams[0]->id, 'points' => 10])
            ->assertOk()
            ->assertJsonPath('snapshot.ranking_answered_team_ids', [$teams[0]->id]);

        $this->postJson('/api/game/judge', ['team_id' => $teams[1]->id, 'points' => 0])
            ->assertOk();

        $this->postJson('/api/game/judge', ['team_id' => $teams[2]->id, 'points' => 5])
            ->assertOk()
            ->assertJsonPath('snapshot.ranking_all_answered', true);

        // All answered, scores correct
        $state = $this->getJson('/api/game/state')->json('snapshot');
        $s0 = collect($state['teams'])->firstWhere('id', $teams[0]->id);
        $s1 = collect($state['teams'])->firstWhere('id', $teams[1]->id);
        $s2 = collect($state['teams'])->firstWhere('id', $teams[2]->id);
        $this->assertSame(10, $s0['score']);
        $this->assertSame(0, $s1['score']);
        $this->assertSame(5, $s2['score']);

        // LANJUT
        $this->postJson('/api/game/begin')->assertOk()
            ->assertJsonPath('snapshot.state', 'result')
            ->assertJsonPath('snapshot.question_number', 2)
            ->assertJsonPath('snapshot.ranking_answered_team_ids', []);
    }

    public function test_api_judge_duplicate_team_ditolak(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);

        $this->postJson('/api/game/set-type', ['game_type' => 'ranking_1'])->assertOk();
        $this->postJson('/api/game/begin')->assertOk();
        $this->postJson('/api/game/judge', ['team_id' => $teams[0]->id, 'points' => 10])->assertOk();
        $this->postJson('/api/game/judge', ['team_id' => $teams[0]->id, 'points' => 10])->assertStatus(422);
    }

    // ─── Reset preserves game_type ───

    public function test_reset_tidak_mengubah_game_type(): void
    {
        $teams = Team::factory()->count(2)->create();
        $this->setRanking1();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->reset();

        $snap = $engine->snapshot();
        $this->assertSame('ranking_1', $snap['game_type']);
        $this->assertSame('idle', $snap['state']);
        $this->assertSame(0, $snap['question_number']);
    }

    // ─── Snapshot contains game_type ───

    public function test_snapshot_mengandung_game_type(): void
    {
        $engine = app(GameEngine::class);

        $snap = $engine->snapshot();
        $this->assertArrayHasKey('game_type', $snap);
        $this->assertSame('buzzer', $snap['game_type']);
    }

    // ─── Buzzer open false in ranking mode ───

    public function test_buzzer_open_false_di_mode_ranking(): void
    {
        $teams = Team::factory()->count(2)->create();
        $this->setRanking1();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $snap = $engine->snapshot();
        $this->assertFalse($snap['buzzer_open']);
    }
}
