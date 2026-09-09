<?php

namespace App\Models;

use App\Enums\GamePhase;
use App\Enums\GameType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['id', 'state'])]
class GameState extends Model
{
    public const SINGLETON_ID = 1;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => GamePhase::class,
            'game_type' => GameType::class,
            'ranking_answered_team_ids' => 'array',
            'throw_active' => 'boolean',
            'buzz_deadline_at' => 'datetime',
            'answer_deadline_at' => 'datetime',
            'phase_started_at' => 'datetime',
        ];
    }

    public static function current(): static
    {
        return static::query()->firstOrCreate(
            ['id' => self::SINGLETON_ID],
            ['state' => GamePhase::Idle],
        );
    }

    public function winnerTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'winner_team_id');
    }

    public function lastScoreTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'last_score_team_id');
    }
}
