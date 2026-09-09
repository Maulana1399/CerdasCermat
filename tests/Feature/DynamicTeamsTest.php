<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Services\GameEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DynamicTeamsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array<int>>
     */
    public static function teamCountProvider(): array
    {
        return [
            'dua regu' => [2],
            'empat regu' => [4],
            'enam regu' => [6],
        ];
    }

    #[DataProvider('teamCountProvider')]
    public function test_engine_melayani_jumlah_regu_dinamis(int $count): void
    {
        $teams = Team::factory()->count($count)->create(['score' => 0]);

        $engine = app(GameEngine::class);

        $initial = $engine->snapshot();
        $this->assertCount($count, $initial['teams']);

        $engine->beginQuestion();
        $winner = $engine->buzz($teams->last());

        $this->assertNotNull($winner);
        $this->assertSame($teams->last()->id, $winner->id);

        $after = $engine->snapshot();
        $this->assertCount($count, $after['teams']);
        $this->assertSame('buzzed', $after['state']);
        $this->assertSame($teams->last()->id, $after['winner']['id']);
    }

    public function test_semua_regu_tampil_di_snapshot_dengan_warna_dan_skor(): void
    {
        $names = ['Regu Alpha', 'Regu Bravo', 'Regu Charlie', 'Regu Delta', 'Regu Echo', 'Regu Foxtrot'];

        foreach ($names as $index => $name) {
            Team::factory()->create([
                'name' => $name,
                'color' => '#'.str_pad(dechex($index * 40), 6, '0', STR_PAD_LEFT),
                'sort_order' => $index,
            ]);
        }

        $snapshot = app(GameEngine::class)->snapshot();

        $this->assertCount(6, $snapshot['teams']);
        $this->assertEqualsCanonicalizing($names, array_column($snapshot['teams'], 'name'));

        foreach ($snapshot['teams'] as $team) {
            $this->assertArrayHasKey('id', $team);
            $this->assertArrayHasKey('name', $team);
            $this->assertArrayHasKey('color', $team);
            $this->assertArrayHasKey('score', $team);
            $this->assertArrayHasKey('identifier', $team);
            $this->assertSame(0, $team['score']);
        }
    }
}
