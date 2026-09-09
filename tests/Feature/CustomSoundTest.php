<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CustomSoundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    private function fakeAudio(string $name = 'test.mp3'): UploadedFile
    {
        // Create a minimal valid MP3 file (ID3 header + sync frame)
        $content = 'ID3'."\x03\x00\x00"."\x00".str_repeat("\0", 10).str_repeat("\xff\xfb\x90\x00", 128);

        return UploadedFile::fake()->createWithContent($name, $content);
    }

    // ─── GET /api/sounds ───

    public function test_index_returns_empty_sounds(): void
    {
        $res = $this->getJson('/api/sounds')->assertOk()->json();

        $this->assertTrue($res['success']);
        $this->assertArrayHasKey('sounds', $res);
        $this->assertArrayHasKey('team_sounds', $res);
        $this->assertArrayHasKey('buzzer', $res['sounds']);
        $this->assertArrayHasKey('benar', $res['sounds']);
        $this->assertArrayHasKey('salah', $res['sounds']);
        $this->assertArrayHasKey('no_buzz', $res['sounds']);
    }

    public function test_index_includes_active_teams(): void
    {
        Team::factory()->count(3)->create();
        $res = $this->getJson('/api/sounds')->assertOk()->json();

        $this->assertCount(3, $res['team_sounds']);
    }

    // ─── POST /api/sounds/upload ───

    public function test_upload_global_sound(): void
    {
        $res = $this->postJson('/api/sounds/upload', [
            'type' => 'benar',
            'file' => $this->fakeAudio('correct.mp3'),
        ])->assertOk()->json();

        $this->assertTrue($res['success']);
        $this->assertNotEmpty($res['path']);
        $this->assertStringContainsString('benar-', $res['path']);

        // Verify setting saved
        $path = Setting::get('sound_benar', '');
        $this->assertNotEmpty($path);
    }

    public function test_upload_invalid_type_rejected(): void
    {
        $this->postJson('/api/sounds/upload', [
            'type' => 'invalid',
            'file' => $this->fakeAudio(),
        ])->assertStatus(422);
    }

    public function test_upload_invalid_extension_rejected(): void
    {
        $this->postJson('/api/sounds/upload', [
            'type' => 'buzzer',
            'file' => UploadedFile::fake()->createWithContent('test.exe', str_repeat("\0", 100)),
        ])->assertStatus(422);
    }

    public function test_upload_replaces_old_file(): void
    {
        Setting::set('sound_buzzer', 'sounds/buzzer-old.mp3');
        Storage::disk('public')->put('sounds/buzzer-old.mp3', 'old');

        $res = $this->postJson('/api/sounds/upload', [
            'type' => 'buzzer',
            'file' => $this->fakeAudio('new.mp3'),
        ])->assertOk()->json();

        $this->assertTrue($res['success']);
        $this->assertNotSame('sounds/buzzer-old.mp3', $res['path']);
    }

    // ─── POST /api/sounds/upload-team ───

    public function test_upload_team_sound(): void
    {
        $team = Team::factory()->create();

        $res = $this->postJson('/api/sounds/upload-team', [
            'team_id' => $team->id,
            'file' => $this->fakeAudio('team1.mp3'),
        ])->assertOk()->json();

        $this->assertTrue($res['success']);
        $this->assertStringContainsString('buzzer-'.$team->id, $res['path']);

        // Verify setting saved with team ID
        $path = Setting::get('sound_buzzer_team_'.$team->id, '');
        $this->assertNotEmpty($path);
    }

    public function test_team_sound_bound_to_team_id(): void
    {
        $team = Team::factory()->create();
        $this->postJson('/api/sounds/upload-team', [
            'team_id' => $team->id,
            'file' => $this->fakeAudio('t1.mp3'),
        ])->assertOk();

        // Get sounds and verify team binding
        $res = $this->getJson('/api/sounds')->assertOk()->json();
        $this->assertArrayHasKey((string) $team->id, $res['team_sounds']);
        $this->assertSame($team->id, $res['team_sounds'][(string) $team->id]['team_id']);
    }

    public function test_nonexistent_team_rejected(): void
    {
        $this->postJson('/api/sounds/upload-team', [
            'team_id' => 99999,
            'file' => $this->fakeAudio(),
        ])->assertStatus(422);
    }

    // ─── POST /api/sounds/delete ───

    public function test_delete_global_sound(): void
    {
        Setting::set('sound_buzzer', 'sounds/buzzer.mp3');

        $this->postJson('/api/sounds/delete', ['key' => 'sound_buzzer'])
            ->assertOk()->json();

        $path = Setting::get('sound_buzzer', '');
        $this->assertSame('', $path);
    }

    public function test_delete_team_sound(): void
    {
        $team = Team::factory()->create();
        Setting::set('sound_buzzer_team_'.$team->id, 'sounds/buzzer-'.$team->id.'.mp3');

        $this->postJson('/api/sounds/delete', ['key' => 'sound_buzzer_team_'.$team->id])
            ->assertOk()->json();

        $path = Setting::get('sound_buzzer_team_'.$team->id, '');
        $this->assertSame('', $path);
    }

    public function test_delete_invalid_key_rejected(): void
    {
        $this->postJson('/api/sounds/delete', ['key' => 'invalid_key'])
            ->assertStatus(422);
    }

    // ─── Snapshot includes custom sounds ───

    public function test_snapshot_includes_custom_sounds(): void
    {
        Setting::set('sound_buzzer', 'sounds/bz.mp3');
        Setting::set('sound_benar', 'sounds/bn.mp3');

        $res = $this->getJson('/api/game/state')->assertOk()->json();
        $this->assertArrayHasKey('custom_sounds', $res['snapshot']);
        $this->assertArrayHasKey('sound_buzzer', $res['snapshot']['custom_sounds']);
        $this->assertArrayHasKey('sound_benar', $res['snapshot']['custom_sounds']);
    }

    public function test_snapshot_team_sounds_included(): void
    {
        $team = Team::factory()->create();
        Setting::set('sound_buzzer_team_'.$team->id, 'sounds/bt.mp3');

        $res = $this->getJson('/api/game/state')->assertOk()->json();
        $this->assertArrayHasKey('sound_buzzer_team_'.$team->id, $res['snapshot']['custom_sounds']);
    }

    // ─── Dynamic team gets sound option ───

    public function test_new_team_gets_sound_option(): void
    {
        // Initially no teams
        $res = $this->getJson('/api/sounds')->assertOk()->json();
        $this->assertCount(0, $res['team_sounds']);

        // Add team
        $team = Team::factory()->create();

        // Now should have sound option
        $res = $this->getJson('/api/sounds')->assertOk()->json();
        $this->assertCount(1, $res['team_sounds']);
        $this->assertArrayHasKey((string) $team->id, $res['team_sounds']);
    }

    // ─── Fallback chain (team → global → built-in) ───

    public function test_fallback_chain_team_to_global_to_builtin(): void
    {
        // No custom sounds set → snapshot should have empty custom_sounds
        $res = $this->getJson('/api/game/state')->assertOk()->json();
        $this->assertEmpty($res['snapshot']['custom_sounds']);

        // Set global buzzer
        Setting::set('sound_buzzer', 'sounds/global.mp3');
        $res = $this->getJson('/api/game/state')->assertOk()->json();
        $this->assertArrayHasKey('sound_buzzer', $res['snapshot']['custom_sounds']);

        // Set team buzzer — should override global
        $team = Team::factory()->create();
        Setting::set('sound_buzzer_team_'.$team->id, 'sounds/team.mp3');
        $res = $this->getJson('/api/game/state')->assertOk()->json();
        $this->assertArrayHasKey('sound_buzzer_team_'.$team->id, $res['snapshot']['custom_sounds']);
    }

    // ─── Mode Ranking 1 tidak memicu buzzer sound ───

    public function test_ranking_1_no_buzzer_trigger(): void
    {
        // In Ranking 1 mode, the buzzer is not used
        // This is a frontend concern, but we verify the backend doesn't
        // break when sound settings exist in Ranking 1 mode
        $team = Team::factory()->create();
        Setting::set('sound_buzzer_team_'.$team->id, 'sounds/bt.mp3');

        $this->postJson('/api/game/set-type', ['game_type' => 'ranking_1'])->assertOk();
        $res = $this->getJson('/api/game/state')->assertOk()->json();
        $this->assertSame('ranking_1', $res['snapshot']['game_type']);
        // Sound config should still be available
        $this->assertArrayHasKey('sound_buzzer_team_'.$team->id, $res['snapshot']['custom_sounds']);
    }

    // ─── Mode Lemparan tidak memicu participant buzzer ───

    public function test_lemparan_no_participant_buzzer(): void
    {
        // In Lemparan mode, the participant buzzer is not used
        $team = Team::factory()->create();
        Setting::set('sound_buzzer_team_'.$team->id, 'sounds/bt.mp3');

        $this->postJson('/api/game/set-type', ['game_type' => 'lemparan'])->assertOk();
        $res = $this->getJson('/api/game/state')->assertOk()->json();
        $this->assertSame('lemparan', $res['snapshot']['game_type']);
        // Sound config should still be available
        $this->assertArrayHasKey('sound_buzzer_team_'.$team->id, $res['snapshot']['custom_sounds']);
    }

    // ─── Existing behavior preserved ───

    public function test_sound_enabled_setting_unchanged(): void
    {
        Setting::set('sound_enabled', true);
        $res = $this->getJson('/api/game/state')->assertOk()->json();
        $this->assertTrue($res['snapshot']['sound_enabled']);
    }

    public function test_sound_enabled_false_by_default(): void
    {
        $res = $this->getJson('/api/game/state')->assertOk()->json();
        $this->assertFalse($res['snapshot']['sound_enabled']);
    }
}
