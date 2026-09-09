<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BootstrapCommandTest extends TestCase
{
    protected string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = sys_get_temp_dir().'/lomba-bootstrap-'.str()->random(8);
        mkdir($this->tmpDir, 0777, true);

        config(['database.connections.sqlite.database' => $this->tmpDir.'/database.sqlite']);
        DB::purge('sqlite');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmpDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tmpDir);

        parent::tearDown();
    }

    public function test_bootstrap_migrates_and_seeds_when_empty(): void
    {
        $this->assertFalse(file_exists($this->tmpDir.'/database.sqlite'));

        $this->artisan('lomba:bootstrap', ['--force' => true])
            ->expectsOutputToContain('Bootstrap selesai')
            ->assertExitCode(0);

        $this->assertTrue(file_exists($this->tmpDir.'/database.sqlite'));
        $this->assertGreaterThan(0, Team::query()->count());
        $this->assertSame(10, Setting::get('buzzer_seconds'));
        $this->assertSame(30, Setting::get('answer_seconds'));
    }

    public function test_bootstrap_is_idempotent(): void
    {
        $this->artisan('lomba:bootstrap', ['--force' => true])->assertExitCode(0);
        $count = Team::query()->count();

        $this->artisan('lomba:bootstrap', ['--force' => true])->assertExitCode(0);

        $this->assertSame($count, Team::query()->count());
    }

    public function test_bootstrap_keeps_existing_teams(): void
    {
        $this->artisan('lomba:bootstrap', ['--force' => true])->assertExitCode(0);

        Team::query()->create(['name' => 'Regu X', 'identifier' => 'X', 'is_active' => false]);
        $count = Team::query()->count();

        $this->artisan('lomba:bootstrap', ['--force' => true])->assertExitCode(0);

        $this->assertSame($count, Team::query()->count());
        $this->assertDatabaseHas('teams', ['name' => 'Regu X']);
    }
}
