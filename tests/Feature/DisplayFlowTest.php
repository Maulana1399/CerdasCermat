<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\Team;
use App\Services\GameEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Perilaku Audience Display: regu dinamis, winner tanpa reaction time,
 * countdown menjawab hanya setelah MULAI JAWAB, hasil +/- dan lanjut.
 */
class DisplayFlowTest extends TestCase
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
    public function test_display_regu_dinamis_dengan_skor(int $count): void
    {
        $teams = Team::factory()->count($count)->create(['score' => 7]);
        $engine = app(GameEngine::class);

        $snapshot = $engine->snapshot();
        $this->assertCount($count, $snapshot['teams']);
        $this->assertSame(array_fill(0, $count, 7), array_column($snapshot['teams'], 'score'));
    }

    public function test_winner_ditampilkan_tanpa_reaction_time_atau_timestamp(): void
    {
        $teams = Team::factory()->count(2)->create();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->buzz($teams[0]);

        $winner = $engine->snapshot()['winner'];

        $this->assertSame($teams[0]->id, $winner['id']);
        $this->assertSame($teams[0]->name, $winner['name']);
        foreach (['reaction', 'reaction_ms', 'timestamp', 'time'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $winner, 'winner tidak boleh menampilkan '.$forbidden);
        }
    }

    public function test_countdown_menjawab_tidak_mulai_saat_buzz(): void
    {
        $teams = Team::factory()->count(2)->create();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $this->assertNotNull($engine->snapshot()['buzz_deadline_at']);

        $engine->buzz($teams[0]);

        $snapshot = $engine->snapshot();
        $this->assertSame('buzzed', $snapshot['state']);
        $this->assertNull($snapshot['answer_deadline_at'], 'answer timer tidak boleh jalan saat buzzed');
    }

    public function test_countdown_menjawab_mulai_setelah_mula_i_jawab(): void
    {
        Setting::set('answer_seconds', 30);
        $teams = Team::factory()->count(2)->create();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->buzz($teams[1]);
        $engine->allowAnswer();

        $snapshot = $engine->snapshot();
        $this->assertSame('answering', $snapshot['state']);
        $this->assertNotNull($snapshot['answer_deadline_at']);
    }

    public function test_hasil_positif_dan_negatif_memperbarui_skor_dan_last_score(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->applyScore($teams[0], 5, 'benar');

        $snapshot = $engine->snapshot();
        $scored = collect($snapshot['teams'])->firstWhere('id', $teams[0]->id);
        $this->assertSame(5, $scored['score']);
        $this->assertSame(5, $snapshot['last_score']['points']);
        $this->assertSame($teams[0]->id, $snapshot['last_score']['team']['id']);

        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->applyScore($teams[0], -5, 'salah');

        $snapshot = $engine->snapshot();
        $scored = collect($snapshot['teams'])->firstWhere('id', $teams[0]->id);
        $this->assertSame(0, $scored['score']);
        $this->assertSame(-5, $snapshot['last_score']['points']);
    }

    public function test_snapshot_menyediakan_data_countdown_realtime(): void
    {
        Setting::set('buzzer_seconds', 10);
        Setting::set('answer_seconds', 30);
        $teams = Team::factory()->count(2)->create();
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $snap = $engine->snapshot();

        // Browser menghitung sisa secara realtime dari server_time + deadline.
        $this->assertNotNull($snap['server_time']);
        $this->assertSame('buzzing', $snap['state']);
        $this->assertNotNull($snap['buzz_deadline_at']);
        $server = Carbon::parse($snap['server_time']);
        $buzzTtl = $server->diffInSeconds(Carbon::parse($snap['buzz_deadline_at']), true);
        $this->assertGreaterThanOrEqual(9, $buzzTtl, 'deadline rebutan ~ buzzer_seconds di masa depan');
        $this->assertLessThanOrEqual(10, $buzzTtl, 'deadline rebutan ~ buzzer_seconds di masa depan');

        $engine->buzz($teams[0]);
        $engine->allowAnswer();

        $snap = $engine->snapshot();
        $this->assertSame('answering', $snap['state']);
        $this->assertNotNull($snap['answer_deadline_at']);
        $answerTtl = Carbon::parse($snap['server_time'])->diffInSeconds(Carbon::parse($snap['answer_deadline_at']), true);
        $this->assertGreaterThanOrEqual(29, $answerTtl, 'deadline menjawab ~ answer_seconds di masa depan');
        $this->assertLessThanOrEqual(30, $answerTtl, 'deadline menjawab ~ answer_seconds di masa depan');

        // Deadline direset pada fase tanpa timer agar UI menyembunyikan countdown.
        $engine->resolveAnswer();
        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $snap = $engine->snapshot();
        $this->assertSame('buzzed', $snap['state']);
        $this->assertNull($snap['answer_deadline_at']);
    }

    public function test_lanju_t_memulai_pertanyaan_berikutnya_mempertahankan_skor(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->applyScore($teams[0], 10, 'benar');

        $before = $engine->snapshot();
        $this->assertSame('result', $before['state']);

        $engine->beginQuestion();

        $after = $engine->snapshot();
        $this->assertSame('buzzing', $after['state']);
        $this->assertSame(2, $after['question_number']);
        $this->assertNull($after['winner']);
        $this->assertNull($after['last_score']);
        $scored = collect($after['teams'])->firstWhere('id', $teams[0]->id);
        $this->assertNotNull($scored);
        $this->assertSame(10, $scored['score']);
        $this->assertTrue($after['buzzer_open']);
    }

    public function test_ulang_kembali_ke_buzzing_tanpa_ganti_soal_atau_skor(): void
    {
        Setting::set('buzzer_seconds', 10);
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $engine = app(GameEngine::class);

        // Ronde normal menuju Result dengan skor.
        $engine->beginQuestion();
        $engine->buzz($teams[1]);
        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->applyScore($teams[1], -3, 'salah');

        $from = $engine->snapshot();
        $this->assertSame('result', $from['state']);
        $this->assertSame(1, $from['question_number']);

        $scoresBefore = collect($from['teams'])->pluck('score', 'id')->all();

        // ULANG
        $engine->repeatQuestion();
        $after = $engine->snapshot();

        $this->assertSame('buzzing', $after['state']);
        $this->assertSame(1, $after['question_number'], 'nomor pertanyaan tidak berubah');
        $this->assertNull($after['winner'], 'winner/result lama dibersihkan');
        $this->assertNull($after['last_score'], 'last_score lama dibersihkan');
        $this->assertTrue($after['buzzer_open'], 'participant kembali boleh buzzer');

        $scoresAfter = collect($after['teams'])->pluck('score', 'id')->all();
        $this->assertSame($scoresBefore, $scoresAfter, 'skor semua tim tidak berubah');

        // Timer buzzer dibuat ulang (deadline baru di masa depan).
        $this->assertNotNull($after['buzz_deadline_at'], 'deadline buzzer dibuat ulang');
        $ttl = Carbon::parse($after['server_time'])->diffInSeconds(Carbon::parse($after['buzz_deadline_at']), true);
        $this->assertGreaterThanOrEqual(9, $ttl);
        $this->assertLessThanOrEqual(10, $ttl);
        $this->assertNull($after['answer_deadline_at']);
    }

    public function test_ulang_winner_lama_tidak_mengunci_participant_dan_buzzer_baru_berhasil(): void
    {
        $teams = Team::factory()->count(3)->create(['score' => 0]);
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $engine->resolveAnswer();

        $engine->repeatQuestion();

        // Buzzer baru: regu berbeda bisa menang, state mengikuti flow normal.
        $this->assertNotNull($engine->buzz($teams[1]));

        $snap = $engine->snapshot();
        $this->assertSame('buzzed', $snap['state']);
        $this->assertSame($teams[1]->id, $snap['winner']['id'], 'winner baru dari buzzer baru');
    }

    public function test_ulang_dari_state_tidak_valid_ditolak(): void
    {
        $teams = Team::factory()->count(2)->create();
        $engine = app(GameEngine::class);

        // Idle bukan hasil pertanyaan.
        $this->expectException(RuntimeException::class);
        $engine->repeatQuestion();
    }

    public function test_lanju_t_setelah_ulang_menuju_pertanyaan_berikutnya(): void
    {
        $teams = Team::factory()->count(2)->create(['score' => 0]);
        $engine = app(GameEngine::class);

        $engine->beginQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->applyScore($teams[0], 10, 'benar');

        $engine->repeatQuestion();
        $engine->buzz($teams[0]);
        $engine->allowAnswer();
        $engine->resolveAnswer();
        $engine->applyScore($teams[0], 5, 'benar');

        // LANJUT = pertanyaan berikutnya, skor dipertahankan.
        $engine->beginQuestion();
        $after = $engine->snapshot();
        $this->assertSame('buzzing', $after['state']);
        $this->assertSame(2, $after['question_number']);
        $scored = collect($after['teams'])->firstWhere('id', $teams[0]->id);
        $this->assertSame(15, $scored['score']);
    }
}
