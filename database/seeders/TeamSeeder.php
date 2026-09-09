<?php

namespace Database\Seeders;

use App\Models\Team;
use Illuminate\Database\Seeder;

class TeamSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['name' => 'Regu A', 'identifier' => 'A', 'color' => '#ef4444'],
            ['name' => 'Regu B', 'identifier' => 'B', 'color' => '#3b82f6'],
            ['name' => 'Regu C', 'identifier' => 'C', 'color' => '#22c55e'],
            ['name' => 'Regu D', 'identifier' => 'D', 'color' => '#eab308'],
        ];

        foreach ($defaults as $index => $team) {
            Team::query()->updateOrCreate(
                ['identifier' => $team['identifier']],
                [
                    'name' => $team['name'],
                    'color' => $team['color'],
                    'score' => 0,
                    'is_active' => true,
                    'sort_order' => $index,
                ],
            );
        }
    }
}
