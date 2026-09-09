<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ScriptOrderTest extends TestCase
{
    /**
     * Inline page script harus dirender SETELAH sound.js dan game.js,
     * serta tidak boleh "terangkat" ke atas <!DOCTYPE html> (gejala @push tanpa @endpush).
     */
    #[DataProvider('halamanDanMarkerInline')]
    public function test_inline_script_dieksekusi_setelah_dependency_script(string $view, string $marker): void
    {
        $html = view($view)->render();

        $posDoctype = strpos($html, '<!DOCTYPE html>');
        $posSound = strpos($html, 'js/sound.js');
        $posGame = strpos($html, 'js/game.js');
        $posInline = strpos($html, $marker);

        $this->assertNotFalse($posDoctype, 'DOCTYPE tidak ditemukan');
        $this->assertNotFalse($posSound, 'src sound.js tidak ditemukan');
        $this->assertNotFalse($posGame, 'src game.js tidak ditemukan');
        $this->assertNotFalse($posInline, 'inline script tidak ditemukan');

        $this->assertTrue(
            $posDoctype < $posInline,
            'Inline script ter-render sebelum <!DOCTYPE html> (push stack tidak ditutup)',
        );
        $this->assertTrue(
            $posSound < $posGame && $posGame < $posInline,
            'Urutan script harus: sound.js -> game.js -> inline page script',
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function halamanDanMarkerInline(): array
    {
        return [
            'display' => ['display', 'poll(render, 800)'],
            'operator' => ['operator', 'poll(render, 1000)'],
            'participant' => ['participant', 'poll(function (data)'],
        ];
    }
}
