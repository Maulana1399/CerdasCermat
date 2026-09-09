<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\GameEngine;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Hook hardware: sumber input (Arduino via USB serial, HID, dll) cukup
 * memanggil perintah ini agar event masuk ke engine yang sama.
 *
 *   php artisan game:buzz A
 */
#[Signature('game:buzz {identifier : identifier regu, contoh: A}')]
#[Description('Kirim event buzzer untuk sebuah regu ke dalam permainan')]
class GameBuzz extends Command
{
    public function handle(GameEngine $engine): int
    {
        $team = Team::query()
            ->whereRaw('LOWER(identifier) = ?', [strtolower((string) $this->argument('identifier'))])
            ->where('is_active', true)
            ->first();

        if ($team === null) {
            $this->error('Regu tidak ditemukan: '.$this->argument('identifier'));

            return self::FAILURE;
        }

        $engine->advanceTimedOut();
        $winner = $engine->buzz($team);

        $this->line($winner !== null
            ? 'WINNER: '.$team->name.' ('.$team->identifier.')'
            : 'BUZZ DITOLAK (bukan fase rebutan / sudah ada pemenang).');

        return self::SUCCESS;
    }
}
