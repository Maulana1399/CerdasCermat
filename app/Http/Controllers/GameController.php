<?php

namespace App\Http\Controllers;

use App\Enums\GamePhase;
use App\Enums\GameType;
use App\Models\GameState;
use App\Models\Team;
use App\Services\GameEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class GameController extends Controller
{
    public function __construct(private readonly GameEngine $engine) {}

    public function state(): JsonResponse
    {
        return $this->snapshotResponse();
    }

    public function begin(): JsonResponse
    {
        return $this->run(fn () => $this->engine->beginQuestion());
    }

    public function repeat(): JsonResponse
    {
        return $this->run(fn () => $this->engine->repeatQuestion());
    }

    public function buzz(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): void {
            $team = $this->resolveTeam($request);

            $this->engine->buzz($team);
        });
    }

    public function allow(): JsonResponse
    {
        return $this->run(fn () => $this->engine->allowAnswer());
    }

    public function resolve(): JsonResponse
    {
        return $this->run(fn () => $this->engine->resolveAnswer());
    }

    /**
     * Endpoint legacy (Step 1) — guard menjadi setara endpoint `answer`:
     * penilaian hanya boleh saat sesi menjawab, dan hanya bagi pemenang buzzer.
     */
    public function score(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): void {
            $validator = Validator::make($request->all(), [
                'team_id' => ['required', 'integer', 'exists:teams,id'],
                'points' => ['required', 'integer', 'min:-999', 'max:999'],
                'kind' => ['sometimes', 'string', 'max:20'],
            ]);
            $validator->validate();

            $points = (int) $validator->validated()['points'];
            $kind = (string) ($validator->validated()['kind'] ?? 'custom');
            $team = Team::query()->findOrFail((int) $validator->validated()['team_id']);

            $state = GameState::current();

            if ($state->state !== GamePhase::Answering) {
                throw new RuntimeException('Penilaian hanya dapat dilakukan saat sesi menjawab.');
            }

            if ($state->winner_team_id !== $team->id) {
                throw new RuntimeException('Hanya regu pemenang buzzer yang dapat dinilai.');
            }

            $this->engine->applyScore($team, $points, $kind);
        });
    }

    /**
     * Penilaian per ronde: operator menekan BENAR/SALAH/CUSTOM.
     *
     * Guard di sisi server: hanya boleh saat state "answering" (setelah
     * MULAI JAWAB). Di dalamnya sesi menjawab ditutup (resolve) lalu nilai
     * diterapkan. Idle/buzzing/buzzed/result ditolak — UI juga menonaktifkan
     * kontrol skor agar konsisten.
     */
    public function answer(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): void {
            $validator = Validator::make($request->all(), [
                'team_id' => ['required', 'integer', 'exists:teams,id'],
                'points' => ['required', 'integer', 'min:-999', 'max:999'],
                'kind' => ['sometimes', 'string', 'max:20'],
            ]);
            $validator->validate();

            $points = (int) $validator->validated()['points'];
            $kind = (string) ($validator->validated()['kind'] ?? 'custom');
            $team = Team::query()->findOrFail((int) $validator->validated()['team_id']);

            $state = GameState::current();

            if ($state->state !== GamePhase::Answering) {
                throw new RuntimeException('Penilaian hanya dapat dilakukan saat sesi menjawab.');
            }

            if ($state->winner_team_id !== $team->id) {
                throw new RuntimeException('Hanya regu pemenang buzzer yang dapat dinilai.');
            }

            $this->engine->resolveAnswer();
            $this->engine->applyScore($team, $points, $kind);
        });
    }

    /**
     * Ranking 1: atur tipe permainan. Hanya boleh saat idle.
     */
    public function setGameType(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): void {
            $validator = Validator::make($request->all(), [
                'game_type' => ['required', 'string', 'in:buzzer,ranking_1,lemparan'],
            ]);
            $validator->validate();

            $type = GameType::from($validator->validated()['game_type']);
            $this->engine->setGameType($type);
        });
    }

    /**
     * Lemparan: pilih tim pertama untuk memulai pertanyaan.
     */
    public function beginThrow(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): void {
            $validator = Validator::make($request->all(), [
                'team_id' => ['required', 'integer', 'exists:teams,id'],
            ]);
            $validator->validate();

            $team = Team::query()->findOrFail((int) $validator->validated()['team_id']);
            $this->engine->beginThrowQuestion($team);
        });
    }

    /**
     * Ranking 1 / Lemparan: pilih regu yang sedang dinilai, lalu beri nilai BENAR/SALAH.
     *
     * Guard: hanya boleh saat mode ranking_1/lemparan dan state Result.
     * Regu tidak boleh dinilai dua kali pada pertanyaan yang sama.
     */
    public function judgeTeam(Request $request): JsonResponse
    {
        return $this->run(function () use ($request): void {
            $validator = Validator::make($request->all(), [
                'team_id' => ['required', 'integer', 'exists:teams,id'],
                'points' => ['required', 'integer', 'min:0', 'max:999'],
            ]);
            $validator->validate();

            $team = Team::query()->findOrFail((int) $validator->validated()['team_id']);
            $points = (int) $validator->validated()['points'];

            $state = GameState::current();
            if ($state->game_type === GameType::Lemparan) {
                $this->engine->judgeThrowTeam($team, $points);
            } else {
                $this->engine->judgeTeam($team, $points);
            }
        });
    }

    public function reset(): JsonResponse
    {
        return $this->run(fn () => $this->engine->reset());
    }

    private function resolveTeam(Request $request): Team
    {
        $validator = Validator::make($request->all(), [
            'team_id' => ['sometimes', 'integer', 'exists:teams,id'],
            'identifier' => ['sometimes', 'string', 'max:120'],
        ]);
        $validator->validate();

        $validated = $validator->validated();
        $team = null;

        if (isset($validated['team_id'])) {
            $team = Team::query()->find((int) $validated['team_id']);
        } elseif (isset($validated['identifier'])) {
            $team = Team::query()
                ->whereRaw('LOWER(identifier) = ?', [strtolower((string) $validated['identifier'])])
                ->where('is_active', true)
                ->first();
        }

        return $team ?? throw new RuntimeException('Regu tidak ditemukan.');
    }

    private function run(callable $action): JsonResponse
    {
        try {
            $action();
        } catch (ValidationException $e) {
            return response()->json(['success' => false, 'error' => $e->errors()], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return $this->snapshotResponse();
    }

    private function snapshotResponse(): JsonResponse
    {
        $this->engine->advanceTimedOut();

        return response()->json(['success' => true, 'snapshot' => $this->engine->snapshot()]);
    }
}
