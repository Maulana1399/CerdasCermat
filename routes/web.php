<?php

use App\Http\Controllers\ConfigController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\ScoreExportController;
use App\Http\Controllers\SoundController;
use Illuminate\Support\Facades\Route;

Route::get('/up', fn () => response('ok'))->name('health');

Route::get('/', [PageController::class, 'display'])->name('home');
Route::get('/display', [PageController::class, 'display'])->name('display');
Route::get('/participant', [PageController::class, 'participant'])->name('participant');
Route::get('/operator', [PageController::class, 'operator'])->name('operator');
Route::get('/operator/export-nilai', ScoreExportController::class)->name('operator.export-nilai');

Route::name('game.')->prefix('api')->group(function () {
    Route::get('/game/state', [GameController::class, 'state'])->name('state');
    Route::post('/game/begin', [GameController::class, 'begin'])->name('begin');
    Route::post('/game/repeat', [GameController::class, 'repeat'])->name('repeat');
    Route::post('/game/buzz', [GameController::class, 'buzz'])->name('buzz');
    Route::post('/game/allow', [GameController::class, 'allow'])->name('allow');
    Route::post('/game/resolve', [GameController::class, 'resolve'])->name('resolve');
    Route::post('/game/score', [GameController::class, 'score'])->name('score');
    Route::post('/game/answer', [GameController::class, 'answer'])->name('answer');
    Route::post('/game/reset', [GameController::class, 'reset'])->name('reset');
    Route::post('/game/set-type', [GameController::class, 'setGameType'])->name('setGameType');
    Route::post('/game/judge', [GameController::class, 'judgeTeam'])->name('judgeTeam');
    Route::post('/game/begin-throw', [GameController::class, 'beginThrow'])->name('beginThrow');

    Route::get('/teams', [ConfigController::class, 'index'])->name('teams.index');
    Route::post('/teams', [ConfigController::class, 'store'])->name('teams.store');
    Route::patch('/teams/{team}', [ConfigController::class, 'update'])->name('teams.update');
    Route::post('/settings', [ConfigController::class, 'settings'])->name('settings.save');

    Route::get('/sounds', [SoundController::class, 'index'])->name('sounds.index');
    Route::post('/sounds/upload', [SoundController::class, 'upload'])->name('sounds.upload');
    Route::post('/sounds/upload-team', [SoundController::class, 'uploadTeam'])->name('sounds.uploadTeam');
    Route::post('/sounds/delete', [SoundController::class, 'delete'])->name('sounds.delete');
});
