<?php

namespace Tests\Feature;

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParticipantLinkTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeam(array $overrides = []): Team
    {
        return Team::factory()->create(array_merge([
            'identifier' => fake()->unique()->randomLetter(),
        ], $overrides));
    }

    // ─── API: teams endpoint returns identifier ───

    public function test_teams_api_returns_identifier(): void
    {
        $team = $this->makeTeam(['identifier' => 'X']);

        $res = $this->getJson('/api/teams')->assertOk()->json();

        $this->assertTrue($res['success']);
        $found = collect($res['teams'])->firstWhere('id', $team->id);
        $this->assertNotNull($found);
        $this->assertSame('X', $found['identifier']);
    }

    public function test_all_active_teams_have_identifier(): void
    {
        $this->makeTeam();
        $this->makeTeam();
        $this->makeTeam();

        $res = $this->getJson('/api/teams')->assertOk()->json();

        foreach ($res['teams'] as $t) {
            $this->assertNotEmpty($t['identifier'], "Team {$t['name']} missing identifier");
        }
    }

    // ─── Identifier independent of name ───

    public function test_identifier_persists_after_name_change(): void
    {
        $team = $this->makeTeam(['identifier' => 'B', 'name' => 'Original']);

        $this->patchJson('/api/teams/'.$team->id, ['name' => 'Renamed'])->assertOk();

        $res = $this->getJson('/api/teams')->assertOk()->json();
        $found = collect($res['teams'])->firstWhere('id', $team->id);
        $this->assertSame('B', $found['identifier']);
        $this->assertSame('Renamed', $found['name']);
    }

    // ─── Dynamic teams ───

    public function test_new_team_appears_in_api_with_identifier(): void
    {
        $this->makeTeam();
        $this->makeTeam();

        $res1 = $this->getJson('/api/teams')->assertOk()->json();
        $this->assertCount(2, $res1['teams']);

        $this->postJson('/api/teams', ['name' => 'Regu E', 'color' => '#ff0000'])
            ->assertOk();

        $res2 = $this->getJson('/api/teams')->assertOk()->json();
        $this->assertCount(3, $res2['teams']);

        $newTeam = collect($res2['teams'])->firstWhere('name', 'Regu E');
        $this->assertNotNull($newTeam);
        $this->assertNotEmpty($newTeam['identifier']);
    }

    // ─── Inactive teams ───

    public function test_inactive_team_still_has_identifier(): void
    {
        $team = $this->makeTeam(['identifier' => 'C', 'is_active' => false]);

        $res = $this->getJson('/api/teams')->assertOk()->json();
        $found = collect($res['teams'])->firstWhere('id', $team->id);
        $this->assertNotNull($found);
        $this->assertSame('C', $found['identifier']);
        $this->assertFalse($found['is_active']);
    }

    // ─── Operator page renders COPY LINK for teams ───

    public function test_operator_page_contains_copy_link_buttons(): void
    {
        $this->makeTeam();
        $this->makeTeam();
        $this->makeTeam();

        $res = $this->get('/operator')->assertOk();
        $html = $res->getContent();

        $this->assertStringContainsString('data-copy-link', $html);
        $this->assertStringContainsString('COPY LINK', $html);
    }

    public function test_participant_page_uses_team_query_param(): void
    {
        $res = $this->get('/participant?team=A')->assertOk();
        $html = $res->getContent();

        $this->assertStringContainsString('teamFromQuery', $html);
        $this->assertStringContainsString("params.get('team')", $html);
    }

    // ─── Multiple teams have unique identifiers ───

    public function test_identifiers_are_unique(): void
    {
        $teams = collect([
            $this->makeTeam(['identifier' => 'A']),
            $this->makeTeam(['identifier' => 'B']),
            $this->makeTeam(['identifier' => 'C']),
            $this->makeTeam(['identifier' => 'D']),
        ]);
        $identifiers = $teams->pluck('identifier')->toArray();

        $this->assertCount(4, array_unique($identifiers));
    }

    // ─── Snapshot includes team identifiers ───

    public function test_snapshot_includes_team_identifiers(): void
    {
        $this->makeTeam(['identifier' => 'Z']);

        $res = $this->getJson('/api/game/state')->assertOk()->json();

        $found = collect($res['snapshot']['teams'])->firstWhere('identifier', 'Z');
        $this->assertNotNull($found);
    }
}
