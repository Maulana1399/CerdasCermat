<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Team;
use App\Services\GameEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TimerTest extends TestCase
{
    use RefreshDatabase;

    public function test_waktu_rebutan_habis_otomatis_ke_result_tanpa_pemenang(): void
    {
        Setting::set('buzzer_seconds', 1);
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $this->assertSame('buzzing', $engine->snapshot()['state']);

        $this->travelTo(now()->addSeconds(2));
        $engine->advanceTimedOut();

        $snapshot = $engine->snapshot();
        $this->assertSame('result', $snapshot['state']);
        $this->assertNull($snapshot['winner']);
    }

    public function test_waktu_menjawab_habis_otomatis_ke_result_dengan_pemenang_bertahan(): void
    {
        Setting::set('answer_seconds', 1);
        $teams = Team::factory()->count(2)->create();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $this->assertSame('answering', $engine->snapshot()['state']);

        $this->travelTo(now()->addSeconds(2));
        $engine->advanceTimedOut();

        $snapshot = $engine->snapshot();
        $this->assertSame('result', $snapshot['state']);
        $this->assertSame($teams[0]->id, $snapshot['winner']['id']);
    }

    public function test_buzzer_yang_ditekan_setelah_batas_waktu_ditolak(): void
    {
        Setting::set('buzzer_seconds', 1);
        $teams = Team::factory()->count(2)->create();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();

        $this->travelTo(now()->addSeconds(5));
        $engine->advanceTimedOut();
        $this->assertNull($engine->buzz($teams[0]));
    }
}
