<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class SoundController extends Controller
{
    private const ALLOWED_MIMES = ['audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/mp3'];

    private const ALLOWED_EXTENSIONS = ['mp3', 'wav', 'ogg'];

    private const MAX_FILE_SIZE = 5120; // 5 MB

    private const SOUND_KEYS = [
        'buzzer' => 'sound_buzzer',
        'benar' => 'sound_benar',
        'salah' => 'sound_salah',
        'no_buzz' => 'sound_no_buzz',
    ];

    /**
     * Get all sound configurations.
     */
    public function index(): JsonResponse
    {
        $sounds = [];
        foreach (self::SOUND_KEYS as $name => $key) {
            $path = Setting::get($key, '');
            $sounds[$name] = [
                'key' => $key,
                'path' => $path,
                'url' => $path ? Storage::disk('public')->url($path) : null,
                'exists' => $path ? Storage::disk('public')->exists($path) : false,
            ];
        }

        // Per-team buzzer sounds
        $teams = Team::query()->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get();
        $teamSounds = [];
        foreach ($teams as $team) {
            $key = 'sound_buzzer_team_'.$team->id;
            $path = Setting::get($key, '');
            $teamSounds[$team->id] = [
                'team_id' => $team->id,
                'team_name' => $team->name,
                'key' => $key,
                'path' => $path,
                'url' => $path ? Storage::disk('public')->url($path) : null,
                'exists' => $path ? Storage::disk('public')->exists($path) : false,
            ];
        }

        return response()->json([
            'success' => true,
            'sounds' => $sounds,
            'team_sounds' => $teamSounds,
        ]);
    }

    /**
     * Upload or update a custom sound.
     */
    public function upload(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', Rule::in(array_keys(self::SOUND_KEYS))],
            'file' => ['required', 'file', 'max:'.(self::MAX_FILE_SIZE), 'mimes:mp3,wav,ogg'],
        ]);

        return $this->storeFile(
            self::SOUND_KEYS[$validated['type']],
            $request->file('file'),
            $validated['type']
        );
    }

    /**
     * Upload or update a per-team buzzer sound.
     */
    public function uploadTeam(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'file' => ['required', 'file', 'max:'.(self::MAX_FILE_SIZE), 'mimes:mp3,wav,ogg'],
        ]);

        $team = Team::query()->findOrFail((int) $validated['team_id']);
        $key = 'sound_buzzer_team_'.$team->id;

        return $this->storeFile($key, $request->file('file'), 'buzzer-'.$team->id);
    }

    /**
     * Delete/reset a custom sound to default.
     */
    public function delete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'key' => ['required', 'string'],
        ]);

        $key = $validated['key'];

        // Only allow deleting known sound keys
        $allKeys = array_merge(
            array_values(self::SOUND_KEYS),
            // Also allow per-team keys pattern
        );
        if (! in_array($key, $allKeys, true) && ! preg_match('/^sound_buzzer_team_\d+$/', $key)) {
            return response()->json(['success' => false, 'error' => 'Sound key tidak valid.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $oldPath = Setting::get($key, '');
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        Setting::set($key, '');

        return response()->json(['success' => true]);
    }

    private function storeFile(string $key, $file, string $prefix): JsonResponse
    {
        // Validate MIME type
        $mimeType = $file->getMimeType();
        if (! in_array($mimeType, self::ALLOWED_MIMES, true)) {
            return response()->json([
                'success' => false,
                'error' => 'Tipe file tidak valid. Hanya menerima: '.implode(', ', self::ALLOWED_EXTENSIONS),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        // Delete old file if exists
        $oldPath = Setting::get($key, '');
        if ($oldPath && Storage::disk('public')->exists($oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        // Store new file with safe name
        $extension = $file->getClientOriginalExtension();
        $safeName = $prefix.'-'.time().'.'.$extension;
        $path = $file->storeAs('sounds', $safeName, 'public');

        Setting::set($key, $path);

        return response()->json([
            'success' => true,
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ]);
    }
}
