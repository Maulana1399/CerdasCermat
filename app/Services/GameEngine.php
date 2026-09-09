<?php

namespace App\Services;

use App\Enums\GamePhase;
use App\Enums\GameType;
use App\Models\GameState;
use App\Models\ScoreEvent;
use App\Models\Setting;
use App\Models\Team;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Sumber kebenaran (source of truth) seluruh permainan.
 *
 * Timer dihitung oleh server (deadline tersimpan di game_states),
 * winner buzzer ditentukan oleh server lewat klaim atomik (CAS),
 * dan daftar regu selalu diambil dinamis dari tabel teams.
 */
class GameEngine
{
    public function beginQuestion(): GameState
    {
        $state = GameState::current();

        if (! in_array($state->state, [GamePhase::Idle, GamePhase::Result], true)) {
            throw new RuntimeException('Pertanyaan tidak dapat dimulai pada fase '.($state->state?->value ?? 'tidak diketahui').'.');
        }

        if ($state->game_type === GameType::Ranking1) {
            return $this->beginRankingQuestion($state);
        }

        if ($state->game_type === GameType::Lemparan) {
            throw new RuntimeException('Mode Lemparan harus memilih tim terlebih dahulu via beginThrowQuestion().');
        }

        $deadline = $this->now()->addSeconds((int) Setting::get('buzzer_seconds', 10));

        DB::transaction(function () use ($state, $deadline): void {
            $this->table()->where('id', GameState::SINGLETON_ID)->update([
                'state' => GamePhase::Buzzing->value,
                'question_number' => (int) $state->question_number + 1,
                'winner_team_id' => null,
                'buzz_deadline_at' => $deadline,
                'answer_deadline_at' => null,
                'phase_started_at' => $this->now(),
                'last_score_team_id' => null,
                'last_score_points' => null,
            ]);
        });

        return GameState::current()->refresh();
    }

    /**
     * Klaim buzzer. Hanya satu regu yang boleh menang per rebutan.
     *
     * Update kondisi-letakkan-aksi atomik: hanya berhasil jika state masih
     * "buzzing" dan batas waktu rebutan belum lewat pada saat eksekusi,
     * sehingga dua regu yang menekan hampir bersamaan tetap menghasilkan
     * satu pemenang (waktu server, bukan timestamp client).
     */
    public function buzz(Team $team): ?Team
    {
        $now = $this->now();

        $claimed = DB::transaction(function () use ($team, $now): bool {
            return (bool) $this->table()->where('id', GameState::SINGLETON_ID)
                ->where('state', GamePhase::Buzzing->value)
                ->where('buzz_deadline_at', '>=', $now->toDateTimeString())
                ->update([
                    'state' => GamePhase::Buzzed->value,
                    'winner_team_id' => $team->id,
                    'phase_started_at' => $now,
                    'answer_deadline_at' => null,
                ]);
        });

        return $claimed ? $team : null;
    }

    /**
     * ULANG: mulai kembali rebutan untuk pertanyaan yang SAMA.
     *
     * Boleh dipanggil hanya dari fase Result (hasil pertanyaan). Skor seluruh
     * regu TIDAK diubah, nomor pertanyaan TIDAK dinaikkan, dan tidak ada
     * score event baru. Field transient/result (winner, last_score, answer
     * deadline) dibersihkan. Deadline buzzer dibuat ulang berdasarkan waktu
     * server menggunakan setting buzzer_seconds.
     */
    public function repeatQuestion(): GameState
    {
        $state = GameState::current();

        if ($state->game_type !== GameType::Buzzer) {
            throw new RuntimeException('ULANG hanya tersedia untuk mode Buzzer.');
        }

        if ($state->state !== GamePhase::Result) {
            throw new RuntimeException('Pertanyaan hanya dapat diulang setelah hasil (result).');
        }

        $deadline = $this->now()->addSeconds((int) Setting::get('buzzer_seconds', 10));

        $this->table()->where('id', GameState::SINGLETON_ID)->update([
            'state' => GamePhase::Buzzing->value,
            'question_number' => (int) $state->question_number,
            'winner_team_id' => null,
            'buzz_deadline_at' => $deadline,
            'answer_deadline_at' => null,
            'phase_started_at' => $this->now(),
            'last_score_team_id' => null,
            'last_score_points' => null,
        ]);

        return GameState::current()->refresh();
    }

    public function allowAnswer(): GameState
    {
        $state = GameState::current();

        if ($state->state !== GamePhase::Buzzed || $state->winner_team_id === null) {
            throw new RuntimeException('Belum ada regu pemenang yang dapat diizinkan menjawab.');
        }

        $deadline = $this->now()->addSeconds((int) Setting::get('answer_seconds', 30));

        $this->table()->where('id', GameState::SINGLETON_ID)->update([
            'state' => GamePhase::Answering->value,
            'answer_deadline_at' => $deadline,
            'phase_started_at' => $this->now(),
        ]);

        return GameState::current()->refresh();
    }

    public function resolveAnswer(): GameState
    {
        $state = GameState::current();

        if ($state->state !== GamePhase::Answering) {
            throw new RuntimeException('Tidak ada sesi menjawab yang sedang berlangsung.');
        }

        $this->table()->where('id', GameState::SINGLETON_ID)->update([
            'state' => GamePhase::Result->value,
            'answer_deadline_at' => null,
        ]);

        return GameState::current()->refresh();
    }

    public function applyScore(Team $team, int $points, string $kind = 'custom'): Team
    {
        if ($points === 0) {
            return $team;
        }

        DB::transaction(function () use ($team, $points, $kind): void {
            GameState::current();
            $team->increment('score', $points);
            ScoreEvent::query()->create([
                'team_id' => $team->id,
                'points' => $points,
                'kind' => $kind,
            ]);
            $this->table()->where('id', GameState::SINGLETON_ID)->update([
                'last_score_team_id' => $team->id,
                'last_score_points' => $points,
            ]);
        });

        return $team->refresh();
    }

    /**
     * Majukan state apabila batas waktu fase telah lewat (dipanggil setiap
     * polling/permintaan, sehingga tidak butuh scheduler/cron tersendiri).
     */
    public function advanceTimedOut(): GameState
    {
        $state = GameState::current();
        $now = $this->now();

        if (
            $state->state === GamePhase::Buzzing
            && $state->buzz_deadline_at !== null
            && $state->buzz_deadline_at->lt($now)
        ) {
            $this->table()->where('id', GameState::SINGLETON_ID)->update([
                'state' => GamePhase::Result->value,
                'winner_team_id' => null,
                'buzz_deadline_at' => null,
                'answer_deadline_at' => null,
            ]);
        } elseif (
            $state->state === GamePhase::Answering
            && $state->answer_deadline_at !== null
            && $state->answer_deadline_at->lt($now)
        ) {
            $this->table()->where('id', GameState::SINGLETON_ID)->update([
                'state' => GamePhase::Result->value,
                'answer_deadline_at' => null,
            ]);
        }

        return GameState::current()->refresh();
    }

    public function reset(): GameState
    {
        DB::transaction(function (): void {
            Team::query()->update(['score' => 0]);
            ScoreEvent::query()->delete();
            $this->table()->where('id', GameState::SINGLETON_ID)->update([
                'state' => GamePhase::Idle->value,
                'question_number' => 0,
                'winner_team_id' => null,
                'last_score_team_id' => null,
                'last_score_points' => null,
                'buzz_deadline_at' => null,
                'answer_deadline_at' => null,
                'phase_started_at' => null,
                'ranking_answered_team_ids' => null,
                'throw_active' => false,
            ]);
        });

        return GameState::current()->refresh();
    }

    // ─── Ranking 1 ───────────────────────────────────────────────────

    /**
     * Atur tipe permainan.
     *
     * Boleh dilakukan saat:
     * - Idle (sebelum soal dimulai), atau
     * - Result (soal selesai) dengan syarat:
     *   • Mode Buzzer: selalu boleh saat Result.
     *   • Mode Ranking 1: hanya boleh saat semua regu aktif sudah dinilai.
     */
    public function setGameType(GameType $type): GameState
    {
        $state = GameState::current();

        if ($state->state === GamePhase::Idle) {
            // Selalu boleh saat idle — tidak ada perubahan.
        } elseif ($state->state === GamePhase::Result) {
            if ($state->game_type === GameType::Lemparan && $state->throw_active) {
                throw new RuntimeException('Beralih mode hanya boleh setelah pertanyaan Lemparan selesai.');
            }

            $isComplete = match ($state->game_type) {
                GameType::Buzzer => true,
                GameType::Ranking1 => count($state->ranking_answered_team_ids ?? []) >= Team::query()->where('is_active', true)->count(),
                GameType::Lemparan => ! $state->throw_active,
                default => false,
            };

            if (! $isComplete) {
                throw new RuntimeException('Beralih mode hanya boleh setelah pertanyaan selesai pada mode ini.');
            }
        } else {
            throw new RuntimeException('Tipe permainan hanya dapat diubah saat SIAP (idle) atau hasil (result).');
        }

        $this->table()->where('id', GameState::SINGLETON_ID)->update([
            'game_type' => $type->value,
        ]);

        return GameState::current()->refresh();
    }

    /**
     * Mulai pertanyaan untuk mode Ranking 1.
     *
     * Semua regu aktif mendapat pertanyaan yang sama.
     * Tidak ada buzzer/rebutan — operator memilih tim yang akan dinilai.
     */
    private function beginRankingQuestion(GameState $state): GameState
    {
        DB::transaction(function () use ($state): void {
            $this->table()->where('id', GameState::SINGLETON_ID)->update([
                'state' => GamePhase::Result->value,
                'question_number' => (int) $state->question_number + 1,
                'winner_team_id' => null,
                'buzz_deadline_at' => null,
                'answer_deadline_at' => null,
                'phase_started_at' => $this->now(),
                'last_score_team_id' => null,
                'last_score_points' => null,
                'ranking_answered_team_ids' => null,
            ]);
        });

        return GameState::current()->refresh();
    }

    /**
     * Nilai regu pada mode Ranking 1.
     *
     * Operator memilih regu, lalu menekan BENAR/SALAH.
     * BENAR mendapat poin sesuai nilai pertanyaan.
     * SALAH mendapat 0 poin, tidak ada pengurangan.
     * Regu yang sudah dinilai tidak boleh dinilai lagi pada pertanyaan yang sama.
     * Setelah semua regu selesai, state berpindah ke Result (siap LANJUT).
     */
    public function judgeTeam(Team $team, int $points): GameState
    {
        $state = GameState::current();

        if ($state->game_type !== GameType::Ranking1) {
            throw new RuntimeException('Penilaian regu hanya tersedia untuk mode Ranking 1.');
        }

        if (! in_array($state->state, [GamePhase::Result], true)) {
            throw new RuntimeException('Penilaian hanya dapat dilakukan pada fase Ranking 1.');
        }

        $answered = $state->ranking_answered_team_ids ?? [];
        if (in_array($team->id, $answered, true)) {
            throw new RuntimeException('Regu "'.$team->name.'" sudah menjawab pada pertanyaan ini.');
        }

        DB::transaction(function () use ($team, $points, $answered): void {
            // Terapkan poin (BENAR > 0, SALAH = 0 via guard applyScore).
            if ($points > 0) {
                $team->increment('score', $points);
                ScoreEvent::query()->create([
                    'team_id' => $team->id,
                    'points' => $points,
                    'kind' => 'benar',
                ]);
            }

            $answered[] = $team->id;

            // Ambil semua regu aktif.
            $activeTeamIds = Team::query()
                ->where('is_active', true)
                ->pluck('id')
                ->all();

            $allAnswered = count($answered) >= count($activeTeamIds);

            $this->table()->where('id', GameState::SINGLETON_ID)->update([
                'ranking_answered_team_ids' => $answered,
                'last_score_team_id' => $team->id,
                'last_score_points' => $points,
                // Jika semua sudah selesai, state tetap Result (operator tekan LANJUT).
                // Jika belum, state tetap Result (operator pilih regu berikutnya).
                'state' => GamePhase::Result->value,
                'phase_started_at' => $this->now(),
            ]);
        });

        return GameState::current()->refresh();
    }

    // ─── Lemparan ────────────────────────────────────────────────────

    /**
     * Mulai pertanyaan Lemparan: pilih tim pertama yang mendapat giliran.
     *
     * Operator memilih tim pertama. State berpindah ke Result dengan
     * tim tersebut sebagai current throw team. ranking_answered_team_ids
     * digunakan untuk mencatat tim yang sudah mendapat kesempatan.
     */
    public function beginThrowQuestion(Team $team): GameState
    {
        $state = GameState::current();

        if ($state->game_type !== GameType::Lemparan) {
            throw new RuntimeException('beginThrowQuestion hanya tersedia untuk mode Lemparan.');
        }

        if (! in_array($state->state, [GamePhase::Idle, GamePhase::Result], true)) {
            throw new RuntimeException('Pertanyaan Lemparan tidak dapat dimulai pada fase '.($state->state?->value ?? 'tidak diketahui').'.');
        }

        if ($state->throw_active) {
            throw new RuntimeException('Pertanyaan Lemparan sedang berjalan. Selesaikan terlebih dahulu.');
        }

        $activeTeamIds = Team::query()->where('is_active', true)->pluck('id')->all();
        if (! in_array($team->id, $activeTeamIds, true)) {
            throw new RuntimeException('Regu "'.$team->name.'" tidak aktif.');
        }

        DB::transaction(function () use ($state, $team): void {
            $this->table()->where('id', GameState::SINGLETON_ID)->update([
                'state' => GamePhase::Result->value,
                'question_number' => (int) $state->question_number + 1,
                'winner_team_id' => null,
                'buzz_deadline_at' => null,
                'answer_deadline_at' => null,
                'phase_started_at' => $this->now(),
                'last_score_team_id' => $team->id,
                'last_score_points' => null,
                'ranking_answered_team_ids' => [],
                'throw_active' => true,
            ]);
        });

        return GameState::current()->refresh();
    }

    /**
     * Nilai tim pada mode Lemparan.
     *
     * BENAR (>0): tim mendapat poin, pertanyaan selesai.
     * SALAH (=0): tim tidak dapat poin, giliran pindah ke tim berikutnya.
     * Jika semua tim sudah salah, pertanyaan selesai tanpa poin.
     */
    public function judgeThrowTeam(Team $team, int $points): GameState
    {
        $state = GameState::current();

        if ($state->game_type !== GameType::Lemparan) {
            throw new RuntimeException('Penilaian Lemparan hanya tersedia untuk mode Lemparan.');
        }

        if ($state->state !== GamePhase::Result || ! $state->throw_active) {
            throw new RuntimeException('Penilaian Lemparan hanya dapat dilakukan saat ada pertanyaan aktif.');
        }

        // Pastikan tim yang dinilai adalah tim yang sedang mendapat giliran.
        if ($state->last_score_team_id !== $team->id) {
            throw new RuntimeException('Regu "'.$team->name.'" bukan regu yang sedang mendapat giliran.');
        }

        $answered = $state->ranking_answered_team_ids ?? [];
        if (in_array($team->id, $answered, true)) {
            throw new RuntimeException('Regu "'.$team->name.'" sudah mendapat kesempatan pada pertanyaan ini.');
        }

        $activeTeamIds = Team::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->pluck('id')->all();

        DB::transaction(function () use ($team, $points, $answered, $activeTeamIds): void {
            $answered[] = $team->id;

            if ($points > 0) {
                // BENAR: beri poin, pertanyaan selesai.
                $team->increment('score', $points);
                ScoreEvent::query()->create([
                    'team_id' => $team->id,
                    'points' => $points,
                    'kind' => 'benar',
                ]);

                $this->table()->where('id', GameState::SINGLETON_ID)->update([
                    'ranking_answered_team_ids' => $answered,
                    'winner_team_id' => $team->id,
                    'last_score_team_id' => $team->id,
                    'last_score_points' => $points,
                    'state' => GamePhase::Result->value,
                    'phase_started_at' => $this->now(),
                    'throw_active' => false,
                ]);
            } else {
                // SALAH: cari tim berikutnya secara circular SETELAH current team.
                $count = count($activeTeamIds);
                $currentIdx = array_search($team->id, $activeTeamIds, true);
                $nextTeamId = null;
                for ($i = 1; $i <= $count; $i++) {
                    $candidate = $activeTeamIds[($currentIdx + $i) % $count];
                    if (! in_array($candidate, $answered, true)) {
                        $nextTeamId = $candidate;
                        break;
                    }
                }

                $allDone = $nextTeamId === null;

                $this->table()->where('id', GameState::SINGLETON_ID)->update([
                    'ranking_answered_team_ids' => $answered,
                    'winner_team_id' => null,
                    'last_score_team_id' => $nextTeamId,
                    'last_score_points' => $allDone ? 0 : null,
                    'state' => GamePhase::Result->value,
                    'phase_started_at' => $this->now(),
                    'throw_active' => ! $allDone,
                ]);
            }
        });

        return GameState::current()->refresh();
    }

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        $state = GameState::current();

        $teams = Team::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(
                fn (Team $team): array => $team->only(['id', 'name', 'color', 'score', 'identifier', 'is_active', 'sort_order']),
            )
            ->all();

        $snapshot = [
            'server_time' => $this->now()->toISOString(true),
            'state' => $state->state?->value ?? GamePhase::Idle->value,
            'game_type' => $state->game_type?->value ?? GameType::Buzzer->value,
            'question_number' => (int) $state->question_number,
            'buzzer_open' => $state->state === GamePhase::Buzzing,
            'buzzer_seconds' => (int) Setting::get('buzzer_seconds', 10),
            'answer_seconds' => (int) Setting::get('answer_seconds', 30),
            'sound_enabled' => (bool) Setting::get('sound_enabled', false),
            'buzz_deadline_at' => $state->buzz_deadline_at?->toISOString(true),
            'answer_deadline_at' => $state->answer_deadline_at?->toISOString(true),
            'phase_started_at' => $state->phase_started_at?->toISOString(true),
            'winner' => $state->winnerTeam?->only(['id', 'name', 'color', 'identifier']),
            'last_score' => $state->last_score_team_id !== null && $state->last_score_points !== null
                ? [
                    'team' => $state->lastScoreTeam?->only(['id', 'name', 'color', 'identifier']),
                    'points' => (int) $state->last_score_points,
                ]
                : null,
            'teams' => $teams,
        ];

        // Custom sounds (only include URLs for non-empty paths)
        $soundKeys = ['sound_buzzer', 'sound_benar', 'sound_salah', 'sound_no_buzz'];
        $sounds = [];
        foreach ($soundKeys as $key) {
            $path = Setting::get($key, '');
            if ($path !== '') {
                $sounds[$key] = Storage::disk('public')->url($path);
            }
        }
        foreach ($teams as $t) {
            $path = Setting::get('sound_buzzer_team_'.$t['id'], '');
            if ($path !== '') {
                $sounds['sound_buzzer_team_'.$t['id']] = Storage::disk('public')->url($path);
            }
        }
        $snapshot['custom_sounds'] = $sounds;

        // Ranking 1 fields
        if ($state->game_type === GameType::Ranking1) {
            $answered = $state->ranking_answered_team_ids ?? [];
            $snapshot['ranking_answered_team_ids'] = $answered;
            $snapshot['ranking_all_answered'] = count($answered) >= count($teams);
        }

        // Lemparan fields
        if ($state->game_type === GameType::Lemparan) {
            $answered = $state->ranking_answered_team_ids ?? [];
            $snapshot['throw_answered_team_ids'] = $answered;
            $snapshot['throw_current_team_id'] = $state->last_score_team_id;
            $snapshot['throw_all_answered'] = count($answered) >= count($teams);
            $snapshot['throw_active'] = (bool) $state->throw_active;
        }

        return $snapshot;
    }

    private function table(): Builder
    {
        return DB::table('game_states');
    }

    private function now(): Carbon
    {
        return now();
    }
}
