@extends('layouts.bare')

@section('title', 'Display Lomba')

@section('body-class', 'display-page')

@push('styles')
<style>
    body.display-page { background: #020617; overflow: hidden; }
    body.display-page main { max-width: none; width: 100vw; height: 100vh; padding: 2vh 2vw; display: flex; flex-direction: column; gap: 1vh; }

    .disp-header { display: flex; align-items: center; gap: 14px; font-size: clamp(18px, 2.6vw, 30px); }
    .disp-header h1 { margin: 0; font-size: clamp(24px, 3.4vw, 40px); letter-spacing: .08em; }
    .disp-header .pill { font-size: clamp(13px, 1.6vw, 20px); }

    .center-area { flex: 1; display: flex; align-items: center; justify-content: center; gap: 3vw; min-height: 0; }
    .big-text-wrap { text-align: center; }
    .big-text { font-size: clamp(36px, 6vw, 80px); font-weight: 900; line-height: 1.05; color: #fff; }
    .big-text.plus { color: #4ade80; }
    .big-text.minus { color: #f87171; }
    .big-sub { font-size: clamp(20px, 3vw, 42px); font-weight: 800; letter-spacing: .25em; color: #facc15; margin-top: .4vh; }
    .big-sub.muted-sub { color: #7d8ca3; letter-spacing: .1em; }

    .countdown { font-size: clamp(56px, 8vw, 110px); font-weight: 900; font-variant-numeric: tabular-nums; color: #facc15; line-height: 1; }
    .countdown.tight { color: #f87171; }
    .countdown-label { text-align: center; font-size: clamp(14px, 1.8vw, 24px); letter-spacing: .2em; color: #7d8ca3; margin-top: .5vh; }

    .disp-teams { display: grid; gap: 1.2vh; border-top: 1px solid #1e293b; padding-top: 1.5vh; }
    .disp-team { border-radius: 18px; padding: 1.6vh .4vw; background: #0f172a; border: 3px solid #243047; text-align: center; }
    .disp-team.winner { border-color: #facc15; box-shadow: 0 0 0 4px rgba(250,204,21,.25); }
    .disp-team.scored { border-color: #4ade80; }
    .disp-team .team-name { font-size: clamp(16px, 2.2vw, 32px); font-weight: 800; }
    .disp-team .score { font-size: clamp(28px, 4.5vw, 64px); font-weight: 900; line-height: 1; margin-top: .4vh; font-variant-numeric: tabular-nums; }
    .disp-team .rank-status { font-size: clamp(11px, 1.2vw, 16px); font-weight: 700; margin-top: .3vh; }
    .disp-team .rank-status.done { color: #4ade80; }

    /* Indikator kecil status suara — tidak mengganggu tampilan lomba.
       pointer-events:none agar tidak memblok klik yang diperlukan untuk unlock. */
    .sound-hint {
        position: fixed; right: 16px; bottom: 14px; z-index: 5;
        padding: 6px 14px; border-radius: 999px;
        background: rgba(15, 23, 42, .85); border: 1px solid #334155;
        color: #fde047; font-size: 13px; font-weight: 700; letter-spacing: .08em;
        pointer-events: none; opacity: 1; transition: opacity .45s ease;
    }
    .sound-hint.hidden { opacity: 0; }
</style>
@endpush

@section('content')
    <div class="disp-header">
        <h1>CERDAS CERMAT</h1>
        <span class="pill" id="question-no">SOAL #0</span>
        <span class="pill gold" id="phase-label">SIAP</span>
        <span class="pill" id="mode-label" style="display:none">RANKING 1</span>
    </div>

    <div class="center-area">
        <div class="big-text-wrap">
            <div class="big-text" id="big-text">MENUNGGU SOAL</div>
            <div class="big-sub" id="big-sub"></div>
        </div>
        <div id="countdown-block" style="text-align:center; display:none">
            <div class="countdown" id="countdown">--</div>
            <div class="countdown-label" id="countdown-label"></div>
        </div>
    </div>

    <div class="disp-teams" id="teams"></div>

    <div class="sound-hint hidden" id="sound-hint">TAP UNTUK SUARA</div>
@endsection

@push('scripts')
<script>
(function () {
    let snap = null;
    let fetchedAt = 0;
    let prevState = null;

    const LABELS = { idle: 'SIAP', buzzing: 'REBUTAN', buzzed: 'TERAMBIL', answering: 'MENJAWAB', result: 'HASIL' };

    function isRanking(d) { return (d.game_type || 'buzzer') === 'ranking_1'; }
    function isLemparan(d) { return (d.game_type || 'buzzer') === 'lemparan'; }

    function setPhase(data) {
        document.getElementById('phase-label').textContent = LABELS[data.state] || data.state;
        document.getElementById('question-no').textContent = 'SOAL #' + (data.question_number || 0);
        const modeLabel = document.getElementById('mode-label');
        if ((isRanking(data) || isLemparan(data)) && data.state !== 'idle') {
            modeLabel.textContent = isLemparan(data) ? 'LEMPARAN' : 'RANKING 1';
            modeLabel.style.display = '';
        } else {
            modeLabel.style.display = 'none';
        }
    }

    function renderMain(data) {
        const big = document.getElementById('big-text');
        const sub = document.getElementById('big-sub');

        big.className = 'big-text';
        sub.className = 'big-sub';

        if (isRanking(data)) {
            // Ranking 1 display
            if (data.state === 'idle') {
                big.textContent = 'MENUNGGU SOAL';
                sub.textContent = '';
            } else if (data.state === 'result' && data.last_score) {
                const p = data.last_score.points;
                big.textContent = (p > 0 ? '+' : '') + (p > 0 ? p : '0');
                big.className = 'big-text ' + (p > 0 ? 'plus' : 'minus');
                sub.textContent = data.last_score.team.name + (p > 0 ? ' — BENAR' : ' — SALAH');
            } else if (data.state === 'result') {
                const answered = (data.ranking_answered_team_ids || []).length;
                const total = (data.teams || []).length;
                big.textContent = 'SOAL #' + (data.question_number || 0);
                sub.textContent = 'MENUNGGU PENILAIAN (' + answered + '/' + total + ')';
                sub.className = 'big-sub muted-sub';
            } else {
                big.textContent = 'MENUNGGU SOAL';
                sub.textContent = '';
            }
        } else if (isLemparan(data)) {
            // Lemparan display
            const answered = (data.throw_answered_team_ids || []);
            const total = (data.teams || []).length;
            const currentTeam = (data.teams || []).find(function (t) { return t.id === data.throw_current_team_id; });
            if (data.state === 'idle') {
                big.textContent = 'MENUNGGU SOAL';
                sub.textContent = '';
            } else if (data.state === 'result' && data.throw_all_answered) {
                big.textContent = 'SEMUA REGU SUDAH MENJAWAB';
                big.className = 'big-text';
                sub.textContent = 'Tidak ada jawaban benar';
                sub.className = 'big-sub muted-sub';
            } else if (data.state === 'result' && data.last_score && data.last_score.points > 0) {
                big.textContent = '+' + data.last_score.points;
                big.className = 'big-text plus';
                sub.textContent = data.last_score.team.name + ' — BENAR';
            } else if (data.state === 'result' && currentTeam) {
                big.textContent = currentTeam.name;
                sub.textContent = 'GILIRAN (' + answered.length + '/' + total + ')';
                sub.className = 'big-sub muted-sub';
            } else {
                big.textContent = 'MENUNGGU SOAL';
                sub.textContent = '';
            }
        } else {
            // Buzzer mode display (original)
            if (data.state === 'idle') {
                big.textContent = 'MENUNGGU SOAL';
                sub.textContent = '';
            } else if (data.state === 'buzzing') {
                big.textContent = 'TEKAN BUZZER!';
                sub.textContent = 'REBUTAN DIBUKA';
            } else if (data.state === 'buzzed' && data.winner) {
                big.textContent = data.winner.name;
                sub.textContent = 'TERCEPAT';
            } else if (data.state === 'answering' && data.winner) {
                big.textContent = data.winner.name;
                sub.textContent = 'MENJAWAB';
            } else if (data.state === 'result' && data.last_score) {
                const p = data.last_score.points;
                big.textContent = (p >= 0 ? '+' : '') + p;
                big.className = 'big-text ' + (p >= 0 ? 'plus' : 'minus');
                sub.textContent = data.last_score.team.name;
            } else if (data.state === 'result' && data.winner) {
                big.textContent = data.winner.name;
                sub.textContent = 'WAKTU HABIS';
                sub.className = 'big-sub muted-sub';
            } else if (data.state === 'result') {
                big.textContent = 'TIDAK ADA YANG MENGAMBIL';
                sub.textContent = '';
                big.style.fontSize = '';
            }
        }
    }

    function renderTeams(data) {
        const teams = data.teams || [];
        const count = Math.max(1, teams.length);
        const el = document.getElementById('teams');
        el.style.gridTemplateColumns = 'repeat(' + count + ', 1fr)';

        if (isRanking(data)) {
            const answered = data.ranking_answered_team_ids || [];
            el.innerHTML = teams.map(function (t) {
                const isScored = data.state === 'result' && data.last_score && data.last_score.team.id === t.id;
                const isDone = answered.includes(t.id);
                return '<div class="disp-team' + (isScored ? ' scored' : '') + '">'
                    + '<div class="team-name"><span class="dot" style="background:' + esc(t.color) + '"></span>' + esc(t.name) + '</div>'
                    + '<div class="score">' + t.score + '</div>'
                    + (isDone ? '<div class="rank-status done">✓ DINILAI</div>' : '')
                    + '</div>';
            }).join('');
        } else if (isLemparan(data)) {
            const answered = data.throw_answered_team_ids || [];
            const currentId = data.throw_current_team_id;
            el.innerHTML = teams.map(function (t) {
                const isScored = data.state === 'result' && data.last_score && data.last_score.team.id === t.id && data.last_score.points > 0;
                const isCurrent = data.state === 'result' && t.id === currentId && !data.throw_all_answered;
                const isDone = answered.includes(t.id);
                return '<div class="disp-team' + (isScored ? ' scored' : '') + '">'
                    + '<div class="team-name"><span class="dot" style="background:' + esc(t.color) + '"></span>' + esc(t.name) + '</div>'
                    + '<div class="score">' + t.score + '</div>'
                    + (isCurrent ? '<div class="rank-status">GILIRAN</div>' : isDone ? '<div class="rank-status done">✓ SELESAI</div>' : '')
                    + '</div>';
            }).join('');
        } else {
            // Original buzzer display
            el.innerHTML = teams.map(function (t) {
                const isWinner = data.winner && data.winner.id === t.id;
                const isScored = data.state === 'result' && data.last_score && data.last_score.team.id === t.id;
                return '<div class="disp-team' + (isWinner ? ' winner' : '') + (isScored ? ' scored' : '') + '">'
                    + '<div class="team-name"><span class="dot" style="background:' + esc(t.color) + '"></span>' + esc(t.name) + '</div>'
                    + '<div class="score">' + t.score + '</div>'
                    + '</div>';
            }).join('');
        }
    }

    // Countdown realtime berbasis waktu SERVER (sumber kebenaran):
    //   sisa = deadline - (server_time + selisih jam client pada saat fetch).
    // Dipanggil setiap frame (requestAnimationFrame), bukan hanya saat poll,
    // dan tidak pernah berhenti walau snapshot pertama belum tiba.
    function updateCountdown() {
        const block = document.getElementById('countdown-block');
        const text = document.getElementById('countdown');
        const label = document.getElementById('countdown-label');

        if (!snap || isRanking(snap)) {
            block.style.display = 'none';
            return;
        }

        const deadline = snap.state === 'buzzing' ? snap.buzz_deadline_at
            : snap.state === 'answering' ? snap.answer_deadline_at : null;
        const lab = snap.state === 'buzzing' ? 'WAKTU REBUTAN'
            : snap.state === 'answering' ? 'WAKTU MENJAWAB' : '';

        // Fase tanpa timer yang relevan -> tampilkan "--".
        if (!deadline) {
            text.textContent = '--';
            text.className = 'countdown';
            label.textContent = lab;
            block.style.display = 'block';
            return;
        }

        const remaining = gameRemainingMs(deadline, snap.server_time, Date.now(), fetchedAt);

        // Deadline/server_time tak bisa diparse -> jangan tampilkan angka keliru.
        if (remaining === null) {
            text.textContent = '--';
            text.className = 'countdown';
            label.textContent = lab;
            block.style.display = 'block';
            return;
        }

        const secs = Math.max(0, Math.ceil(remaining / 1000));
        text.textContent = String(secs);
        text.className = 'countdown' + (snap.state === 'answering' && secs <= 3 ? ' tight' : '');
        label.textContent = lab;
        block.style.display = 'block';
    }

    function tick() {
        updateCountdown();
        requestAnimationFrame(tick);
    }

    function updateSoundHint() {
        const hint = document.getElementById('sound-hint');
        if (hint) hint.classList.toggle('hidden', !(GameSound.isEnabled() && !GameSound.isUnlocked()));
    }

    function render(data) {
        snap = data;
        fetchedAt = Date.now();
        GameSound.configure(!!data.sound_enabled);
        if (data.custom_sounds) GameSound.loadSounds(data.custom_sounds);

        setPhase(data);
        renderMain(data);
        renderTeams(data);
        updateSoundHint();

        // Suara hanya pada transisi state (bukan per polling).
        if (prevState !== data.state) {
            prevState = data.state;
            if (data.state === 'buzzed' && data.winner) GameSound.playBuzz(data.winner.id);
            if (data.state === 'result' && data.last_score) {
                if (data.last_score.points >= 0) {
                    GameSound.playCorrect();
                } else {
                    GameSound.playWrong();
                }
            }
        }
    }

    // Sembunyikan indikator begitu audio di-unlock (tanpa menunggu poll berikut).
    window.addEventListener('game-sound-unlocked', function () {
        document.getElementById('sound-hint').classList.add('hidden');
    });

    poll(render, 800);
    requestAnimationFrame(tick);
})();
</script>
@endpush
