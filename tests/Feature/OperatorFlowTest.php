<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Team;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Operator UI: alur START → MULAI JAWAB → BENAR/SALAH → LANJUT
 * serta konfigurasi regu dinamis dan pengaturan game.
 */
class OperatorFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_alur_operator_lengkap_via_api(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $winner = $teams[0];

        // START
        $this->postJson('/api/game/begin')->assertOk()->assertJsonPath('snapshot.state', 'buzzing');

        // buzz regu pemenang
        $this->postJson('/api/game/buzz', ['team_id' => $winner->id])
            ->assertOk()->assertJsonPath('snapshot.state', 'buzzed');

        // MULAI JAWAB
        $this->postJson('/api/game/allow')->assertOk()->assertJsonPath('snapshot.state', 'answering');

        // BENAR +5
        $this->postJson('/api/game/answer', ['team_id' => $winner->id, 'points' => 5, 'kind' => 'benar'])
            ->assertOk()
            ->assertJsonPath('snapshot.state', 'result');

        $teamsAfterAnswer = $this->getJson('/api/game/state')->json('snapshot.teams');
        $winnerRow = collect($teamsAfterAnswer)->firstWhere('id', $winner->id);
        $this->assertSame(5, $winnerRow['score']);

        // LANJUT = pertanyaan berikutnya
        $this->postJson('/api/game/begin')->assertOk()
            ->assertJsonPath('snapshot.state', 'buzzing')
            ->assertJsonPath('snapshot.question_number', 2);
    }

    public function test_answer_menolak_regu_bukan_pemenang_saat_result(): void
    {
        $teams = Team::factory()->count(2)->create();
        $winner = $teams[0];

        $this->postJson('/api/game/begin')->assertOk();
        $this->postJson('/api/game/buzz', ['team_id' => $winner->id])->assertOk();
        $this->postJson('/api/game/allow')->assertOk();
        $this->postJson('/api/game/answer', ['team_id' => $winner->id, 'points' => 5])->assertOk();

        // Result: hanya pemenang buzzer yang boleh dinilai.
        $losingTeam = $teams[1];
        $this->postJson('/api/game/answer', ['team_id' => $losingTeam->id, 'points' => 5])->assertStatus(422);
    }

    public function test_answer_ditolak_sebelum_mula_i_jawab(): void
    {
        $teams = Team::factory()->count(2)->create();

        $this->postJson('/api/game/begin')->assertOk();
        $this->postJson('/api/game/buzz', ['team_id' => $teams[0]->id])->assertOk();

        // Masih buzzed, bukan answering → ditolak.
        $this->postJson('/api/game/answer', ['team_id' => $teams[0]->id, 'points' => 5])->assertStatus(422);
    }

    public function test_scoring_ditolak_sebelum_sesi_menjawab_dan_setelah_result(): void
    {
        $teams = Team::factory()->count(2)->create();
        $winner = $teams[0];

        // idle: belum ada ronde
        $this->postJson('/api/game/answer', ['team_id' => $winner->id, 'points' => 5])->assertStatus(422);

        $this->postJson('/api/game/begin')->assertOk();
        $this->postJson('/api/game/buzz', ['team_id' => $winner->id])->assertOk();

        // buzzed (buzzer sudah terambil): kontrol skor belum boleh
        $this->postJson('/api/game/answer', ['team_id' => $winner->id, 'points' => 5])->assertStatus(422);

        $this->postJson('/api/game/allow')->assertOk();

        // answering: hanya regu pemenang buzzer yang boleh dinilai
        $this->postJson('/api/game/answer', ['team_id' => $teams[1]->id, 'points' => 5])->assertStatus(422);
        $this->postJson('/api/game/answer', ['team_id' => $winner->id, 'points' => 5])->assertOk();

        // result (setelah dinilai): tidak boleh dinilai lagi hingga LANJUT
        $this->postJson('/api/game/answer', ['team_id' => $winner->id, 'points' => 5])->assertStatus(422);
    }

    public function test_score_endpoint_legacy_guard_sama_dengan_answer(): void
    {
        $teams = Team::factory()->count(2)->create();
        $winner = $teams[0];

        $this->postJson('/api/game/score', ['team_id' => $winner->id, 'points' => 5])->assertStatus(422);

        $this->postJson('/api/game/begin')->assertOk();
        $this->postJson('/api/game/buzz', ['team_id' => $winner->id])->assertOk();
        $this->postJson('/api/game/score', ['team_id' => $winner->id, 'points' => 5])->assertStatus(422);

        $this->postJson('/api/game/allow')->assertOk();
        $this->postJson('/api/game/score', ['team_id' => $winner->id, 'points' => 5])->assertOk();
    }

    public function test_repeat_via_api_kembali_ke_buzzing_soal_sama_skor_sama(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $winner = $teams[0];

        $this->postJson('/api/game/begin')->assertOk();
        $this->postJson('/api/game/buzz', ['team_id' => $winner->id])->assertOk();
        $this->postJson('/api/game/allow')->assertOk();
        $this->postJson('/api/game/answer', ['team_id' => $winner->id, 'points' => 5])->assertOk()
            ->assertJsonPath('snapshot.state', 'result');

        $scoresBefore = collect($this->getJson('/api/game/state')->json('snapshot.teams'))
            ->pluck('score', 'id')->all();

        $repeat = $this->postJson('/api/game/repeat')->assertOk()
            ->assertJsonPath('snapshot.state', 'buzzing')
            ->assertJsonPath('snapshot.question_number', 1)
            ->assertJsonPath('snapshot.buzzer_open', true)
            ->assertJsonPath('snapshot.winner', null);

        $scoresAfter = collect($repeat->json('snapshot.teams'))->pluck('score', 'id')->all();
        $this->assertSame($scoresBefore, $scoresAfter);

        // Lanjut normal setelah ULANG: buzzer baru menentukan winner.
        $this->postJson('/api/game/buzz', ['team_id' => $teams[1]->id])
            ->assertOk()->assertJsonPath('snapshot.winner.id', $teams[1]->id);
    }

    public function test_repeat_ditolak_dari_state_tidak_valid_via_api(): void
    {
        Team::factory()->count(2)->create();

        // Idle: bukan hasil pertanyaan.
        $this->postJson('/api/game/repeat')->assertStatus(422);

        // Buzz (buzzed) juga tidak valid.
        $this->postJson('/api/game/begin')->assertOk();
        $this->postJson('/api/game/buzz', ['team_id' => 1])->assertOk();
        $this->postJson('/api/game/repeat')->assertStatus(422);
    }

    public function test_reset_tetap_bekerja_setelah_ulang(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 10]);
        $winner = $teams[0];

        $this->postJson('/api/game/begin')->assertOk();
        $this->postJson('/api/game/buzz', ['team_id' => $winner->id])->assertOk();
        $this->postJson('/api/game/allow')->assertOk();
        $this->postJson('/api/game/answer', ['team_id' => $winner->id, 'points' => 5])->assertOk();

        // ULANG lalu RESET
        $this->postJson('/api/game/repeat')->assertOk();
        $reset = $this->postJson('/api/game/reset')->assertOk();

        $snap = $reset->json('snapshot');
        $this->assertSame('idle', $snap['state']);
        $this->assertSame(0, $snap['question_number']);
        $this->assertSame(array_fill(0, 2, 0), array_column($snap['teams'], 'score'));
    }

    public function test_konfigurasi_menambah_regu(): void
    {
        foreach (['A', 'B', 'C', 'D'] as $letter) {
            Team::factory()->create(['identifier' => $letter, 'name' => 'Regu '.$letter]);
        }
        $this->artisan('db:seed', ['--class' => SettingSeeder::class, '--force' => true]);

        $this->postJson('/api/teams', ['name' => 'Regu E', 'color' => '#22d3ee'])
            ->assertOk()
            ->assertJsonPath('team.name', 'Regu E')
            ->assertJsonPath('team.is_active', true)
            ->assertJsonPath('team.identifier', 'E')
            ->assertJsonPath('team.score', 0);
    }

    public function test_konfigurasi_mengubah_nama_warna_dan_aktif(): void
    {
        $team = Team::factory()->create(['name' => 'Regu Lama', 'color' => '#111111', 'is_active' => true]);

        $this->patchJson("/api/teams/{$team->id}", [
            'name' => 'Regu Baru',
            'color' => '#ff00ff',
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('team.name', 'Regu Baru')
            ->assertJsonPath('team.color', '#ff00ff')
            ->assertJsonPath('team.is_active', false);

        // Regu nonaktif tidak tampil di snapshot permainan.
        $snapshot = $this->getJson('/api/game/state')->json('snapshot');
        $this->assertEmpty($snapshot['teams']);
    }

    public function test_konfigurasi_setting_game_tersimpan(): void
    {
        $this->postJson('/api/settings', [
            'buzzer_seconds' => 7,
            'answer_seconds' => 45,
            'sound_enabled' => true,
        ])->assertOk()
            ->assertJsonPath('settings.buzzer_seconds', 7)
            ->assertJsonPath('settings.answer_seconds', 45)
            ->assertJsonPath('settings.sound_enabled', true);

        $this->assertSame(7, Setting::get('buzzer_seconds'));
        $this->assertSame(45, Setting::get('answer_seconds'));
        $this->assertTrue(Setting::get('sound_enabled'));

        // Ikut terpantul ke snapshot display/participant.
        $snapshot = $this->getJson('/api/game/state')->json('snapshot');
        $this->assertSame(7, $snapshot['buzzer_seconds']);
        $this->assertSame(45, $snapshot['answer_seconds']);
        $this->assertTrue($snapshot['sound_enabled']);
    }

    public function test_index_teams_mencakup_regu_nonaktif_untuk_konfigurasi(): void
    {
        Team::factory()->create(['is_active' => true]);
        Team::factory()->create(['is_active' => false]);

        $response = $this->getJson('/api/teams')->assertOk();

        $this->assertCount(2, $response->json('teams'));
        $this->assertArrayHasKey('settings', $response->json());
    }

    public function test_presets_dan_custom_nilai_tervalidasi(): void
    {
        $team = Team::factory()->create(['score' => 0]);

        // CUSTOM positif
        $this->postJson('/api/game/begin')->assertOk();
        $this->postJson('/api/game/buzz', ['team_id' => $team->id])->assertOk();
        $this->postJson('/api/game/allow')->assertOk();
        $this->postJson('/api/game/answer', ['team_id' => $team->id, 'points' => 3, 'kind' => 'custom'])
            ->assertOk()->assertJsonPath('snapshot.teams.0.score', 3);

        // Nilai di luar batas ditolak
        $this->postJson('/api/game/score', ['team_id' => $team->id, 'points' => 2000])->assertStatus(422);
        $this->postJson('/api/game/score', ['team_id' => $team->id, 'points' => 'abc'])->assertStatus(422);
    }
}
