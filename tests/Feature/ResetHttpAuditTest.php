<?php

namespace Tests\Feature;

use App\Models\ScoreEvent;
use App\Models\Team;
use App\Services\GameEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Audit test: verify POST /api/game/reset returns correct response format
 * and database state in ALL scenarios, matching the exact UAT failure.
 */
class ResetHttpAuditTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Prove: reset from buzzer result returns {success: true, snapshot: ...}
     * and NOT {success: false}.
     */
    public function test_reset_buzzer_result_returns_success_true(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->applyScore($teams[0], 10, 'benar');

        $resp = $this->postJson('/api/game/reset');

        // Must be HTTP 200, not 422
        $resp->assertOk();

        $json = $resp->json();
        // Must have success: true, not success: false
        $this->assertTrue($json['success'], 'reset must return success: true, got: '.json_encode($json));
        // Must have snapshot
        $this->assertArrayHasKey('snapshot', $json, 'reset must include snapshot key');
        // Snapshot must have state: idle
        $this->assertSame('idle', $json['snapshot']['state']);
        $this->assertSame(0, $json['snapshot']['question_number']);
        $this->assertNull($json['snapshot']['winner']);
        $this->assertNull($json['snapshot']['last_score']);
    }

    /**
     * Prove: reset from idle (game not started yet) returns success: true.
     */
    public function test_reset_from_idle_returns_success_true(): void
    {
        Team::factory()->count(2)->create();

        $resp = $this->postJson('/api/game/reset');

        $resp->assertOk();
        $json = $resp->json();
        $this->assertTrue($json['success']);
        $this->assertSame('idle', $json['snapshot']['state']);
    }

    /**
     * Prove: reset during buzzing (timer running) returns success: true.
     */
    public function test_reset_during_buzzing_returns_success_true(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $engine = app(GameEngine::class);

        $engine->beginQuestion(); // state: buzzing

        $resp = $this->postJson('/api/game/reset');

        $resp->assertOk();
        $json = $resp->json();
        $this->assertTrue($json['success']);
        $this->assertSame('idle', $json['snapshot']['state']);
    }

    /**
     * Prove: reset during answering returns success: true.
     */
    public function test_reset_during_answering_returns_success_true(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer(); // state: answering

        $resp = $this->postJson('/api/game/reset');

        $resp->assertOk();
        $json = $resp->json();
        $this->assertTrue($json['success']);
        $this->assertSame('idle', $json['snapshot']['state']);
    }

    /**
     * Prove: reset from ranking_1 partial returns success: true.
     */
    public function test_reset_ranking1_partial_returns_success_true(): void
    {
        $teams = Team::factory()->count(4)->create(['score' => 0]);
        $this->postJson('/api/game/set-type', ['game_type' => 'ranking_1'])->assertOk();
        $this->postJson('/api/game/begin')->assertOk();
        $this->postJson('/api/game/judge', ['team_id' => $teams[0]->id, 'points' => 10])->assertOk();
        $this->postJson('/api/game/judge', ['team_id' => $teams[1]->id, 'points' => 5])->assertOk();

        $resp = $this->postJson('/api/game/reset');

        $resp->assertOk();
        $json = $resp->json();
        $this->assertTrue($json['success']);
        $this->assertSame('idle', $json['snapshot']['state']);
        $this->assertSame(0, $json['snapshot']['question_number']);
        $this->assertNull($json['snapshot']['winner']);
        $this->assertNull($json['snapshot']['last_score']);
        $this->assertEmpty($json['snapshot']['ranking_answered_team_ids']);
    }

    /**
     * Prove: full response format matches what frontend expects.
     */
    public function test_response_format_matches_frontend_expectations(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->applyScore($teams[0], 10, 'benar');

        $resp = $this->postJson('/api/game/reset');
        $json = $resp->json();

        // Frontend does: response.snapshot ? response.snapshot.state : 'no-snapshot'
        // Verify snapshot exists and has state
        $this->assertIsArray($json['snapshot'] ?? null, 'snapshot must be an array');
        $this->assertArrayHasKey('state', $json['snapshot']);
        $this->assertArrayHasKey('game_type', $json['snapshot']);
        $this->assertArrayHasKey('question_number', $json['snapshot']);
        $this->assertArrayHasKey('teams', $json['snapshot']);
        $this->assertArrayHasKey('winner', $json['snapshot']);
        $this->assertArrayHasKey('last_score', $json['snapshot']);
    }

    /**
     * Prove: database is actually reset after HTTP call.
     */
    public function test_database_state_after_http_reset(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->applyScore($teams[0], 20, 'benar');
        $engine->applyScore($teams[1], -5, 'salah');

        $this->assertGreaterThan(0, ScoreEvent::count());

        $this->postJson('/api/game/reset')->assertOk();

        // All scores back to 0
        foreach ($teams as $team) {
            $this->assertSame(0, $team->fresh()->score, "Score must be 0 for {$team->name}");
        }
        // All score events deleted
        $this->assertSame(0, ScoreEvent::count());
        // Game state back to idle
        $snap = $this->getJson('/api/game/state')->json('snapshot');
        $this->assertSame('idle', $snap['state']);
        $this->assertSame(0, $snap['question_number']);
        $this->assertNull($snap['winner']);
        $this->assertNull($snap['last_score']);
        $this->assertNull($snap['buzz_deadline_at']);
        $this->assertNull($snap['answer_deadline_at']);
    }

    /**
     * Prove: after reset, can START again immediately.
     */
    public function test_can_start_again_after_http_reset(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);

        $this->postJson('/api/game/begin')->assertOk();
        $this->postJson('/api/game/buzz', ['team_id' => $teams[0]->id])->assertOk();
        $this->postJson('/api/game/allow')->assertOk();
        $this->postJson('/api/game/answer', ['team_id' => $teams[0]->id, 'points' => 10])->assertOk();

        // Reset
        $this->postJson('/api/game/reset')->assertOk();

        // Start again
        $this->postJson('/api/game/begin')->assertOk()
            ->assertJsonPath('snapshot.state', 'buzzing')
            ->assertJsonPath('snapshot.question_number', 1);
    }

    /**
     * Prove: response.error is NOT present on success (frontend reads this field).
     */
    public function test_no_error_field_on_success(): void
    {
        Team::factory()->count(2)->create();

        $resp = $this->postJson('/api/game/reset');
        $json = $resp->json();

        $this->assertTrue($json['success']);
        $this->assertArrayNotHasKey('error', $json, 'success response must not have error field');
    }
}
