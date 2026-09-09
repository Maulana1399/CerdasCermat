<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Services\GameEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kontrak participant: tombol aktif (merah) hanya saat buzzer terbuka.
 * Server adalah sumber kebenaran lewat field `buzzer_open`.
 */
class ParticipantStateTest extends TestCase
{
    use RefreshDatabase;

    public function test_buzzer_tertutup_saat_idle(): void
    {
        Team::factory()->count(2)->create();

        $this->assertFalse($this->snapshot()['buzzer_open']);
    }

    public function test_buzzer_terbuka_setelah_start(): void
    {
        Team::factory()->count(2)->create();

        $this->postJson('/api/game/begin')->assertOk();

        $this->assertTrue($this->snapshot()['buzzer_open']);
    }

    public function test_buzzer_terkunci_setelah_ada_winner(): void
    {
        $teams = Team::factory()->count(2)->create();

        $this->postJson('/api/game/begin')->assertOk();
        $this->postJson('/api/game/buzz', ['team_id' => $teams[0]->id])->assertOk();

        $this->assertFalse($this->snapshot()['buzzer_open']);
    }

    public function test_buzzer_terbuka_lagi_setelah_lanjut(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);

        $this->postJson('/api/game/begin')->assertOk();
        $this->postJson('/api/game/buzz', ['team_id' => $teams[0]->id])->assertOk();
        $this->postJson('/api/game/allow')->assertOk();
        $this->postJson('/api/game/answer', ['team_id' => $teams[0]->id, 'points' => 5])->assertOk();

        // LANJUT = begin dari result
        $this->postJson('/api/game/begin')->assertOk();

        $snapshot = $this->snapshot();
        $this->assertTrue($snapshot['buzzer_open']);
        $this->assertNull($snapshot['winner']);
        $this->assertNull($snapshot['answer_deadline_at']);
    }

    public function test_buzzer_terkunci_untuk_semua_participant_saat_hasil(): void
    {
        $teams = Team::factory()->count(3)->create();

        app(GameEngine::class)->beginQuestion();
        app(GameEngine::class)->buzz($teams->last());

        $this->assertFalse($this->snapshot()['buzzer_open']);

        app(GameEngine::class)->allowAnswer();
        app(GameEngine::class)->resolveAnswer();

        $this->assertFalse($this->snapshot()['buzzer_open']);
    }

    public function test_participant_setelah_refresh_restore_identitas_dan_buzz_berhasil(): void
    {
        $teamA = Team::factory()->create(['identifier' => 'A', 'is_active' => true]);
        Team::factory()->create(['identifier' => 'B', 'is_active' => true]);

        // Halaman participant dapat dirender (bukan hardcode regu).
        $this->get('/participant')->assertOk();

        // "Refresh": client memulihkan identitas dari ?team=A / localStorage
        // dengan mencocokkan identifier ke snapshot (data.teams dinamis).
        $this->postJson('/api/game/begin')->assertOk();
        $snapshot = $this->getJson('/api/game/state')->json('snapshot');
        $restored = collect($snapshot['teams'])->firstWhere('identifier', 'A');

        $this->assertNotNull($restored, 'snapshot memuat regu yang cocok dengan identitas tersimpan');
        $this->assertSame($teamA->id, $restored['id']);

        // Client hasil restorasi memakai my.id -> buzz via team_id.
        $response = $this->postJson('/api/game/buzz', ['team_id' => $restored['id']])->assertOk();

        $winner = $response->json('snapshot.winner');
        $this->assertSame($teamA->id, $winner['id']);
        $this->assertSame('A', $winner['identifier']);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return app(GameEngine::class)->snapshot();
    }
}
