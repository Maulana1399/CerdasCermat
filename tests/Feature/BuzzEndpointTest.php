<?php

namespace Tests\Feature;

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BuzzEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_endpoint_mengembalikan_snapshot(): void
    {
        Team::factory()->count(3)->create();

        $response = $this->getJson('/api/game/state');

        $response->assertOk()
            ->assertJsonStructure(['success', 'snapshot' => ['state', 'teams', 'server_time']])
            ->assertJsonPath('snapshot.state', 'idle');
        $response->assertJsonCount(3, 'snapshot.teams');
    }

    public function test_buzz_menerima_identifier_dan_mengunci_rebutan(): void
    {
        Team::factory()->create(['identifier' => 'AA']);
        Team::factory()->create(['identifier' => 'BB']);

        $this->postJson('/api/game/begin')->assertOk();

        $response = $this->postJson('/api/game/buzz', ['identifier' => 'AA']);

        $response->assertOk()
            ->assertJsonPath('snapshot.state', 'buzzed')
            ->assertJsonPath('snapshot.winner.identifier', 'AA');
    }

    public function test_identifier_bersifat_case_insensitive(): void
    {
        Team::factory()->create(['identifier' => 'XY']);

        $this->postJson('/api/game/begin')->assertOk();

        $this->postJson('/api/game/buzz', ['identifier' => 'xy'])
            ->assertOk()
            ->assertJsonPath('snapshot.winner.identifier', 'XY');
    }

    public function test_buzz_identifier_tidak_dikenal_ditolak(): void
    {
        $this->postJson('/api/game/begin')->assertOk();

        $this->postJson('/api/game/buzz', ['identifier' => 'ZZZ'])->assertStatus(422);
    }

    public function test_dua_buzz_berturut_hanya_satu_pemenang_via_api(): void
    {
        Team::factory()->create(['identifier' => 'X']);
        Team::factory()->create(['identifier' => 'Y']);

        $this->postJson('/api/game/begin')->assertOk();

        $this->postJson('/api/game/buzz', ['identifier' => 'X'])
            ->assertJsonPath('snapshot.winner.identifier', 'X');
        $this->postJson('/api/game/buzz', ['identifier' => 'Y'])
            ->assertJsonPath('snapshot.winner.identifier', 'X');
    }

    public function test_skor_via_api_menerima_nilai_negatif_dan_positif(): void
    {
        $team = Team::factory()->create(['score' => 0]);

        // Sesi menjawab harus aktif dulu (guard: scoring sebelum answering ditolak).
        $this->postJson('/api/game/begin')->assertOk();
        $this->postJson('/api/game/buzz', ['team_id' => $team->id])->assertOk();
        $this->postJson('/api/game/allow')->assertOk();

        $this->postJson('/api/game/score', ['team_id' => $team->id, 'points' => 10, 'kind' => 'benar'])
            ->assertOk()
            ->assertJsonPath('snapshot.teams.0.score', 10);

        $this->postJson('/api/game/score', ['team_id' => $team->id, 'points' => -5, 'kind' => 'salah'])
            ->assertOk()
            ->assertJsonPath('snapshot.teams.0.score', 5);
    }

    public function test_halaman_display_participant_dan_operator_merender(): void
    {
        $this->seed();
        Team::factory()->count(6)->create();

        $this->get('/')->assertOk();
        $this->get('/display')->assertOk();
        $this->get('/participant')->assertOk();
        $this->get('/operator')->assertOk();
    }
}
