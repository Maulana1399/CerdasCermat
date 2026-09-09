<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\Team;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SettingSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Rutinitas startup container Lomba:
 * migrasi selalu dijalankan (idempotent), seeder regu default hanya
 * dijalankan bila tabel teams kosong agar hasil edit operator tidak
 * tertimpa saat container membuat ulang.
 */
#[Signature('lomba:bootstrap {--force : Jalankan migrate tanpa konfirmasi pada environment production}')]
#[Description('Migrasi + siapkan data awal untuk startup container')]
class BootstrapCommand extends Command
{
    public function handle(): int
    {
        $force = (bool) $this->option('force');

        $connection = config('database.default');
        $dbPath = strval(config("database.connections.{$connection}.database"));

        if ($dbPath === ':memory:') {
            $dbPath = database_path('database.sqlite');
        }

        File::ensureDirectoryExists(pathinfo($dbPath, PATHINFO_DIRNAME));

        if (! File::exists($dbPath)) {
            File::put($dbPath, '');
            $this->warn("{$dbPath} dibuat.");
        }

        if (config('app.key') === null || trim((string) config('app.key')) === '') {
            $this->call('key:generate', ['--force' => true]);
            $this->warn('APP_KEY dibuat otomatis (tidak tersimpan di env file container).');
        }

        $this->call('migrate', ['--force' => $force]);

        try {
            if (Team::query()->count() === 0) {
                $this->call('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);
                $this->info('Seeder awal (regu + settings) dijalankan.');
            } else {
                $this->call('db:seed', ['--class' => SettingSeeder::class, '--force' => true]);
                $this->line('Regu sudah ada; hanya settings yang disinkronkan.');
            }
        } catch (\Throwable $e) {
            Log::warning('Bootstrap seeding skipped: '.$e->getMessage());
            $this->error('Seeder gagal: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Bootstrap selesai. Regu aktif: %d, buzzer=%ss, jawab=%ss',
            Team::query()->where('is_active', true)->count(),
            Setting::get('buzzer_seconds', 10),
            Setting::get('answer_seconds', 30),
        ));

        return self::SUCCESS;
    }
}
