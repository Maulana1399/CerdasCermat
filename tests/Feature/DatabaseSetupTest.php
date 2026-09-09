<?php

namespace Tests\Feature;

use App\Models\GameState;
use App\Models\Setting;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_membuat_empat_regu_default_dan_pengaturan_awal(): void
    {
        $this->seed();

        $this->assertSame(4, Team::count());
        $this->assertSame(['A', 'B', 'C', 'D'], Team::query()->orderBy('sort_order')->pluck('identifier')->all());
        $this->assertSame(['Regu A', 'Regu B', 'Regu C', 'Regu D'], Team::query()->orderBy('sort_order')->pluck('name')->all());
        $this->assertSame(0, (int) Team::query()->where('score', '!=', 0)->count());

        $this->assertSame(10, Setting::get('buzzer_seconds'));
        $this->assertSame(30, Setting::get('answer_seconds'));
        $this->assertFalse(Setting::get('sound_enabled'));
    }

    public function test_game_state_singleton_row_dibuat_saat_diperlukan(): void
    {
        $this->seed();

        $state = GameState::current();

        $this->assertSame(1, $state->id);
        $this->assertSame('idle', $state->state->value);
        $this->assertSame(1, GameState::current()->id);
    }
}
