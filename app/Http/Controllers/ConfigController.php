<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Konfigurasi ringan (tanpa admin/complex dashboard):
 * kelola regu aktif (nama, warna, aktif/nonaktif) dan pengaturan game
 * (buzzer_seconds, answer_seconds, sound_enabled).
 */
class ConfigController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'teams' => Team::query()
                ->orderBy('is_active', 'desc')
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get()
                ->map(fn (Team $team): array => $team->only(['id', 'name', 'color', 'score', 'identifier', 'is_active', 'sort_order']))
                ->all(),
            'settings' => [
                'buzzer_seconds' => (int) Setting::get('buzzer_seconds', 10),
                'answer_seconds' => (int) Setting::get('answer_seconds', 30),
                'sound_enabled' => (bool) Setting::get('sound_enabled', false),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            'name' => ['required', 'string', 'max:100'],
            'color' => ['sometimes', 'string', 'max:9'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $team = Team::query()->create([
            'name' => $data['name'],
            'color' => $data['color'] ?? '#64748b',
            'is_active' => $data['is_active'] ?? true,
            'identifier' => $this->nextIdentifier(),
            'score' => 0,
            'sort_order' => ((int) Team::query()->max('sort_order')) + 1,
        ]);

        return response()->json(['success' => true, 'team' => $team->fresh()->only(['id', 'name', 'color', 'score', 'identifier', 'is_active', 'sort_order'])]);
    }

    public function update(Request $request, Team $team): JsonResponse
    {
        $data = $this->validated($request, [
            'name' => ['sometimes', 'string', 'max:100'],
            'color' => ['sometimes', 'string', 'max:9'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ]);

        $team->fill($data)->save();

        return response()->json(['success' => true, 'team' => $team->fresh()->only(['id', 'name', 'color', 'score', 'identifier', 'is_active', 'sort_order'])]);
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $this->validated($request, [
            'buzzer_seconds' => ['sometimes', 'integer', 'min:1', 'max:120'],
            'answer_seconds' => ['sometimes', 'integer', 'min:1', 'max:600'],
            'sound_enabled' => ['sometimes', 'boolean'],
        ]);

        foreach ($data as $key => $value) {
            Setting::set($key, $value);
        }

        return response()->json(['success' => true, 'settings' => [
            'buzzer_seconds' => (int) Setting::get('buzzer_seconds', 10),
            'answer_seconds' => (int) Setting::get('answer_seconds', 30),
            'sound_enabled' => (bool) Setting::get('sound_enabled', false),
        ]]);
    }

    /**
     * @param  array<string, array<int, mixed>>  $rules
     * @return array<string, mixed>
     */
    private function validated(Request $request, array $rules): array
    {
        return Validator::make($request->all(), $rules)->validate();
    }

    private function nextIdentifier(): string
    {
        $taken = Team::query()->pluck('identifier')->map(fn (?string $v): string => strtoupper((string) $v))->all();

        for ($letter = 'A'; $letter <= 'Z'; $letter++) {
            if (! in_array($letter, $taken, true)) {
                return $letter;
            }
        }

        return 'T'.(Team::query()->count() + 1);
    }
}
