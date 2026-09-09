@extends('layouts.bare')

@section('title', 'Operator')

@push('styles')
<style>
    .op-header { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
    .op-header h1 { margin: 0; font-size: 24px; }
    .controls { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
    .status-banner { margin-top: 14px; padding: 12px 16px; border-radius: 12px; background: #111a2e; border: 1px solid #243047; }
    .teams-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; margin-top: 16px; }
    .score-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; justify-content: center; }
    .score-actions .btn { padding: 8px 10px; font-size: 13px; }
    .score-actions .btn.big { font-size: 14px; padding: 10px 14px; }
    .custom-row { display: flex; gap: 6px; justify-content: center; margin-top: 8px; }
    .custom-row input { width: 84px; }

    .mode-selector { display: flex; gap: 8px; align-items: center; margin-top: 14px; }
    .mode-selector label { font-size: 14px; color: #94a3b8; font-weight: 700; }
    .mode-selector .btn { font-size: 13px; padding: 8px 16px; }

    .throw-team-card { position: relative; }
    .throw-team-card.current { border-color: #facc15; box-shadow: 0 0 0 3px rgba(250,204,21,.25); }
    .throw-team-card.throw-done { opacity: .55; }
    .throw-team-card .current-badge {
        position: absolute; top: 8px; right: 8px;
        background: #facc15; color: #052e16; font-size: 11px; font-weight: 800;
        padding: 2px 8px; border-radius: 999px;
    }
    .throw-team-card .throw-done-badge {
        position: absolute; top: 8px; right: 8px;
        background: #64748b; color: #0f172a; font-size: 11px; font-weight: 800;
        padding: 2px 8px; border-radius: 999px;
    }
    .throw-team-card .judge-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; justify-content: center; }

    .throw-picker { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
    .throw-picker .btn { font-size: 14px; padding: 10px 18px; }

    .ranking-team-card { position: relative; }
    .ranking-team-card.answered { opacity: .55; }
    .ranking-team-card .answered-badge {
        position: absolute; top: 8px; right: 8px;
        background: #4ade80; color: #052e16; font-size: 11px; font-weight: 800;
        padding: 2px 8px; border-radius: 999px;
    }
    .ranking-team-card .judge-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; justify-content: center; }

    .debug-reset-panel { margin-top: 16px; border: 1px solid #f59e0b; border-radius: 12px; background: #0f172a; padding: 12px; }
    .debug-reset-panel .debug-header { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
    .debug-reset-panel .debug-header span { color: #f59e0b; font-weight: 800; font-size: 14px; }
    .debug-reset-panel .debug-log {
        font-family: 'SF Mono', 'Menlo', 'Consolas', monospace; font-size: 11px; line-height: 1.6;
        max-height: 300px; overflow-y: auto; background: #000; padding: 8px; border-radius: 8px; border: 1px solid #243047;
        -webkit-overflow-scrolling: touch;
    }
    .debug-reset-panel .debug-log .log-entry { padding: 1px 0; white-space: pre-wrap; word-break: break-all; }
    .debug-reset-panel .debug-log .log-time { color: #64748b; }
    .debug-reset-panel .debug-log .log-msg { color: #f59e0b; font-weight: 700; }
    .debug-reset-panel .debug-log .log-extra { color: #94a3b8; }
    .debug-reset-panel .debug-log .log-error .log-msg { color: #ef4444; }

    details.config { margin-top: 22px; border: 1px solid #243047; border-radius: 12px; background: #0f172a; padding: 12px; }
    details.config summary { cursor: pointer; font-weight: 800; color: #e2e8f0; }
    .config-body { margin-top: 14px; display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 18px; }
    .config-block h3 { margin: 0 0 10px; font-size: 15px; color: #94a3b8; text-transform: uppercase; letter-spacing: .05em; }
    .config-team { display: flex; gap: 8px; align-items: end; flex-wrap: wrap; border-bottom: 1px dashed #243047; padding: 8px 0; }
    .config-team input[type=text] { width: 160px; }
    .config-team .chk { display: flex; align-items: center; gap: 6px; padding-bottom: 10px; }
    .config-row { display: flex; gap: 10px; align-items: end; flex-wrap: wrap; }
    .copy-link-btn { font-size: 11px; padding: 4px 8px; background: #1e3a5f; color: #93c5fd; border: 1px solid #2563eb; border-radius: 6px; cursor: pointer; white-space: nowrap; transition: all .15s; }
    .copy-link-btn:hover { background: #2563eb; color: #fff; }
    .copy-link-btn.copied { background: #166534; color: #86efac; border-color: #22c55e; }
</style>
@endpush

@section('content')
    <div class="op-header">
        <h1>OPERATOR</h1>
        <span class="pill gold" id="phase-label">SIAP</span>
        <span class="pill" id="question-no">SOAL #0</span>
        <span class="pill" id="mode-label">BUZZER</span>
    </div>

    <div class="mode-selector" id="mode-selector">
        <label>MODE:</label>
        <button class="btn btn-primary" id="mode-buzzer" data-mode="buzzer">BUZZER</button>
        <button class="btn" id="mode-ranking" data-mode="ranking_1">RANKING 1</button>
        <button class="btn" id="mode-lemparan" data-mode="lemparan">LEMPARAN</button>
    </div>

    <div class="controls" id="buzzer-controls">
        <button class="btn btn-primary" id="begin-btn">START</button>
        <button class="btn" id="allow-btn" disabled>MULAI JAWAB</button>
        <button class="btn" id="repeat-btn" disabled>ULANG</button>
        <button class="btn btn-danger" id="reset-btn">RESET</button>
    </div>

    <div class="controls" id="ranking-controls" style="display:none">
        <button class="btn btn-primary" id="ranking-begin-btn">START</button>
        <button class="btn btn-danger" id="ranking-reset-btn">RESET</button>
    </div>

    <div class="controls" id="lemparan-controls" style="display:none">
        <button class="btn btn-primary" id="lemparan-begin-btn">START</button>
        <button class="btn btn-danger" id="lemparan-reset-btn">RESET</button>
    </div>

    <div id="throw-picker" style="display:none">
        <div style="font-size:14px;color:#94a3b8;font-weight:700;margin-bottom:6px">PILIH TIM PERTAMA:</div>
        <div class="throw-picker" id="throw-picker-btns"></div>
    </div>

    <div class="status-banner">
        <div id="status-text">Menunggu…</div>
        <div class="muted" id="countdown-line" style="margin-top:4px"></div>
    </div>

    <div class="teams-grid" id="teams"></div>

    <details class="config" id="config">
        <summary>Pengaturan</summary>
        <div class="config-body" id="config-body"></div>
    </details>

    <details class="config" id="sound-config">
        <summary>Sound</summary>
        <div class="config-body" id="sound-body"></div>
    </details>

    <div class="debug-reset-panel" id="debug-reset-panel" style="display:none">
        <div class="debug-header">
            <span>RESET DEBUG LOG</span>
            <button class="btn" id="debug-clear-btn" style="font-size:11px;padding:2px 8px">CLEAR</button>
        </div>
        <div class="debug-log" id="debug-reset-log"></div>
    </div>
@endsection

@push('scripts')
<script>
(function () {
    console.log('[RESET] script IIFE started, DOM readyState:', document.readyState);
    let snap = null;
    let fetchedAt = 0;
    let customVals = {};
    let lemparanAwaitingPicker = false;

    const states = { idle: 'SIAP', buzzing: 'REBUTAN', buzzed: 'TERAMBIL', answering: 'MENJAWAB', result: 'HASIL' };
    const statusText = {
        idle: 'Menunggu… Tekan START untuk memulai.',
        buzzing: 'Rebutan dibuka. Regu silakan tekan buzzer!'
    };

    // ─── Helpers ───
    function isBuzzer(d) { return (d.game_type || 'buzzer') === 'buzzer'; }
    function isRanking(d) { return (d.game_type || 'buzzer') === 'ranking_1'; }
    function isLemparan(d) { return (d.game_type || 'buzzer') === 'lemparan'; }

    function fallbackCopy(text, btn) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try {
            document.execCommand('copy');
            btn.textContent = 'TERCOPY!';
            btn.classList.add('copied');
            setTimeout(function () { btn.textContent = 'COPY LINK'; btn.classList.remove('copied'); }, 1500);
        } catch (e) { /* clipboard not available */ }
        document.body.removeChild(ta);
    }

    // Buzzer mode: scoring hanya aktif saat sesi menjawab (answering).
    function canScoreBuzzer(d) {
        return d.state === 'answering';
    }

    function renderModeSelector(data) {
        const active = data.game_type || 'buzzer';
        document.getElementById('mode-buzzer').className = 'btn' + (active === 'buzzer' ? ' btn-primary' : '');
        document.getElementById('mode-ranking').className = 'btn' + (active === 'ranking_1' ? ' btn-primary' : '');
        document.getElementById('mode-lemparan').className = 'btn' + (active === 'lemparan' ? ' btn-primary' : '');
        document.getElementById('mode-label').textContent = active === 'ranking_1' ? 'RANKING 1' : active === 'lemparan' ? 'LEMPARAN' : 'BUZZER';

        // Boleh switch saat idle, atau saat result (soal selesai).
        let canSwitch = data.state === 'idle';
        if (data.state === 'result') {
            if (isBuzzer(data)) {
                canSwitch = true;
            } else if (isRanking(data)) {
                canSwitch = !!data.ranking_all_answered;
            } else if (isLemparan(data)) {
                canSwitch = !data.throw_active;
            }
        }
        document.getElementById('mode-buzzer').disabled = !canSwitch;
        document.getElementById('mode-ranking').disabled = !canSwitch;
        document.getElementById('mode-lemparan').disabled = !canSwitch;

        // Toggle kontrol panel.
        document.getElementById('buzzer-controls').style.display = isBuzzer(data) ? '' : 'none';
        document.getElementById('ranking-controls').style.display = isRanking(data) ? '' : 'none';
        document.getElementById('lemparan-controls').style.display = isLemparan(data) ? '' : 'none';

        // Throw picker: tampilkan saat lemparan idle atau setelah operator tekan LANJUT.
        const picker = document.getElementById('throw-picker');
        if (isLemparan(data) && data.state === 'idle') {
            lemparanAwaitingPicker = false;
            picker.style.display = '';
            renderThrowPicker(data);
        } else if (isLemparan(data) && lemparanAwaitingPicker) {
            // Jangan konsumsi flag di sini — picker tetap visible sampai tim dipilih.
            picker.style.display = '';
            renderThrowPicker(data);
        } else {
            picker.style.display = 'none';
        }
    }

    function renderBuzzerControls(data) {
        const begin = document.getElementById('begin-btn');
        begin.textContent = data.state === 'idle' ? 'START' : data.state === 'result' ? 'LANJUT' : '…';
        begin.disabled = !(data.state === 'idle' || data.state === 'result');

        document.getElementById('allow-btn').disabled = !(data.state === 'buzzed' && data.winner);
        document.getElementById('repeat-btn').disabled = data.state !== 'result';
    }

    function renderRankingControls(data) {
        const begin = document.getElementById('ranking-begin-btn');
        begin.textContent = data.state === 'idle' ? 'START' : data.state === 'result' ? 'LANJUT' : '…';
        begin.disabled = !(data.state === 'idle' || data.state === 'result');
    }

    function renderLemparanControls(data) {
        const begin = document.getElementById('lemparan-begin-btn');
        const throwComplete = data.state === 'result' && !data.throw_active;
        begin.textContent = data.state === 'idle' ? 'START' : throwComplete ? 'LANJUT' : '…';
        begin.disabled = !(data.state === 'idle' || throwComplete);
    }

    function setStatus(data) {
        const w = data.winner;
        let text;

        if (isRanking(data)) {
            const answered = data.ranking_answered_team_ids || [];
            const total = (data.teams || []).length;
            if (data.state === 'result' && data.last_score) {
                const resultWord = data.last_score.points > 0 ? 'BENAR' : 'SALAH';
                text = data.last_score.team.name + ': ' + resultWord + (data.last_score.points > 0 ? ' +' + data.last_score.points : ' 0')
                    + '. (' + answered.length + '/' + total + ' selesai)';
            } else if (data.state === 'result') {
                text = 'Pilih regu untuk dinilai. (' + answered.length + '/' + total + ' selesai)';
            } else if (data.state === 'idle') {
                text = 'Menunggu… Tekan START untuk memulai.';
            } else {
                text = '…';
            }
        } else if (isLemparan(data)) {
            const answered = data.throw_answered_team_ids || [];
            const total = (data.teams || []).length;
            const currentTeam = data.teams.find(function (t) { return t.id === data.throw_current_team_id; });
            if (data.state === 'result' && data.throw_all_answered) {
                text = 'Semua regu sudah mendapat kesempatan. Tekan LANJUT.';
            } else if (data.state === 'result' && data.last_score && data.last_score.points > 0) {
                text = data.last_score.team.name + ': BENAR +' + data.last_score.points + '. Tekan LANJUT.';
            } else if (data.state === 'result' && currentTeam) {
                text = 'Giliran: ' + currentTeam.name + ' (' + answered.length + '/' + total + ')';
            } else if (data.state === 'idle') {
                text = 'Menunggu… Pilih tim pertama untuk memulai.';
            } else {
                text = '…';
            }
        } else {
            // Buzzer mode (original)
            if (canScoreBuzzer(data)) {
                text = w ? w.name + ' menjawab — nilai dengan BENAR / SALAH.' : '…';
            } else if (statusText[data.state]) {
                text = statusText[data.state];
            } else if (data.state === 'buzzed' && w) {
                text = w.name + ' tercepat. Tekan MULAI JAWAB.';
            } else if (data.state === 'result') {
                text = data.last_score
                    ? (data.last_score.points >= 0 ? '+' : '') + data.last_score.points + ' untuk ' + data.last_score.team.name + '. Tekan LANJUT.'
                    : 'Tidak ada yang mengambil buzzer. Tekan LANJUT.';
            } else if (data.state === 'answering' && w) {
                text = w.name + ' sedang menjawab.';
            } else {
                text = '…';
            }
        }
        document.getElementById('status-text').textContent = text;
    }

    function setCountdown(data) {
        const line = document.getElementById('countdown-line');
        if (isRanking(data)) { line.textContent = ''; return; }
        const deadline = data.state === 'buzzing' ? data.buzz_deadline_at
            : data.state === 'answering' ? data.answer_deadline_at : null;
        if (!deadline) { line.textContent = ''; return; }
        const rem = gameRemainingMs(deadline, data.server_time, Date.now(), fetchedAt);
        const secs = Math.max(0, Math.ceil((rem || 0) / 1000));
        line.textContent = (data.state === 'buzzing' ? 'Waktu rebutan: ' : 'Waktu menjawab: ') + secs + ' detik';
    }

    // ─── Buzzer mode team rendering (original) ───
    function renderBuzzerTeams(data) {
        const scoreable = canScoreBuzzer(data);
        document.getElementById('teams').innerHTML = (data.teams || []).map(function (t) {
            const w = data.winner && data.winner.id === t.id;
            const cv = (customVals[t.id] !== undefined ? customVals[t.id] : 0);
            return '<div class="team-card' + (w ? ' winner' : '') + '">'
                + '<div><span class="dot" style="background:' + esc(t.color) + '"></span><strong>' + esc(t.name) + '</strong></div>'
                + '<div class="score">' + t.score + '</div>'
                + '<div class="score-actions">'
                + '<button class="btn btn-success big" data-team="' + t.id + '" data-points="1"' + (scoreable ? '' : ' disabled') + '>BENAR +1</button>'
                + '<button class="btn btn-success" data-team="' + t.id + '" data-points="5"' + (scoreable ? '' : ' disabled') + '>+5</button>'
                + '<button class="btn btn-success" data-team="' + t.id + '" data-points="10"' + (scoreable ? '' : ' disabled') + '>+10</button>'
                + '<button class="btn btn-danger big" data-team="' + t.id + '" data-points="-1"' + (scoreable ? '' : ' disabled') + '>SALAH -1</button>'
                + '<button class="btn btn-danger" data-team="' + t.id + '" data-points="-5"' + (scoreable ? '' : ' disabled') + '>-5</button>'
                + '<button class="btn btn-danger" data-team="' + t.id + '" data-points="-10"' + (scoreable ? '' : ' disabled') + '>-10</button>'
                + '</div>'
                + '<div class="custom-row">'
                + '<input type="number" value="' + cv + '" data-custom-input="' + t.id + '">'
                + '<button class="btn" data-team="' + t.id + '" data-custom="1"' + (scoreable ? '' : ' disabled') + '>CUSTOM</button>'
                + '</div>'
                + '</div>';
        }).join('');
    }

    // ─── Ranking 1 team rendering ───
    function renderRankingTeams(data) {
        const answered = data.ranking_answered_team_ids || [];
        const isActive = data.state === 'result';
        document.getElementById('teams').innerHTML = (data.teams || []).map(function (t) {
            const isAnswered = answered.includes(t.id);
            const cv = (customVals[t.id] !== undefined ? customVals[t.id] : 10);
            const judgeDisabled = !isActive || isAnswered;
            return '<div class="team-card ranking-team-card' + (isAnswered ? ' answered' : '') + '">'
                + '<div><span class="dot" style="background:' + esc(t.color) + '"></span><strong>' + esc(t.name) + '</strong></div>'
                + '<div class="score">' + t.score + '</div>'
                + (isAnswered ? '<span class="answered-badge">✓ DINILAI</span>' : '')
                + '<div class="judge-actions">'
                + '<button class="btn btn-success big" data-judge-team="' + t.id + '" data-judge-points="10"' + (judgeDisabled ? ' disabled' : '') + '>BENAR +10</button>'
                + '<button class="btn btn-success" data-judge-team="' + t.id + '" data-judge-points="5"' + (judgeDisabled ? ' disabled' : '') + '>+5</button>'
                + '<button class="btn btn-success" data-judge-team="' + t.id + '" data-judge-points="1"' + (judgeDisabled ? ' disabled' : '') + '>+1</button>'
                + '<button class="btn btn-danger big" data-judge-team="' + t.id + '" data-judge-points="0"' + (judgeDisabled ? ' disabled' : '') + '>SALAH 0</button>'
                + '</div>'
                + '<div class="custom-row">'
                + '<input type="number" value="' + cv + '" data-ranking-custom-input="' + t.id + '" min="0" max="999">'
                + '<button class="btn" data-judge-team="' + t.id + '" data-judge-custom="1"' + (judgeDisabled ? ' disabled' : '') + '>CUSTOM</button>'
                + '</div>'
                + '</div>';
        }).join('');
    }

    // ─── Lemparan team rendering ───
    function renderThrowTeams(data) {
        const answered = data.throw_answered_team_ids || [];
        const currentId = data.throw_current_team_id;
        const isJudging = data.state === 'result' && currentId && !data.throw_all_answered;

        document.getElementById('teams').innerHTML = (data.teams || []).map(function (t) {
            const isCurrent = isJudging && t.id === currentId;
            const isDone = answered.includes(t.id);
            const cv = (customVals[t.id] !== undefined ? customVals[t.id] : 10);
            const judgeDisabled = !isCurrent;
            let badge = '';
            if (isCurrent) badge = '<span class="current-badge">GILIRAN</span>';
            else if (isDone) badge = '<span class="throw-done-badge">✓ SELESAI</span>';

            return '<div class="team-card throw-team-card' + (isCurrent ? ' current' : '') + (isDone ? ' throw-done' : '') + '">'
                + '<div><span class="dot" style="background:' + esc(t.color) + '"></span><strong>' + esc(t.name) + '</strong></div>'
                + '<div class="score">' + t.score + '</div>'
                + badge
                + '<div class="judge-actions">'
                + '<button class="btn btn-success big" data-judge-team="' + t.id + '" data-judge-points="10"' + (judgeDisabled ? ' disabled' : '') + '>BENAR +10</button>'
                + '<button class="btn btn-success" data-judge-team="' + t.id + '" data-judge-points="5"' + (judgeDisabled ? ' disabled' : '') + '>+5</button>'
                + '<button class="btn btn-success" data-judge-team="' + t.id + '" data-judge-points="1"' + (judgeDisabled ? ' disabled' : '') + '>+1</button>'
                + '<button class="btn btn-danger big" data-judge-team="' + t.id + '" data-judge-points="0"' + (judgeDisabled ? ' disabled' : '') + '>SALAH 0</button>'
                + '</div>'
                + '<div class="custom-row">'
                + '<input type="number" value="' + cv + '" data-ranking-custom-input="' + t.id + '" min="0" max="999">'
                + '<button class="btn" data-judge-team="' + t.id + '" data-judge-custom="1"' + (judgeDisabled ? ' disabled' : '') + '>CUSTOM</button>'
                + '</div>'
                + '</div>';
        }).join('');
    }

    // ─── Lemparan team picker ───
    function renderThrowPicker(data) {
        const container = document.getElementById('throw-picker-btns');
        container.innerHTML = (data.teams || []).map(function (t) {
            return '<button class="btn btn-primary throw-pick-btn" data-throw-pick="' + t.id + '">' + esc(t.name) + '</button>';
        }).join('');
    }

    function render(data) {
        var prevState_val = snap ? snap.state : null;
        var newState = data.state;
        if (prevState_val && prevState_val !== newState) {
            debugReset('state transition', { from: prevState_val, to: newState, game_type: data.game_type, question: data.question_number });
        }
        snap = data;
        fetchedAt = Date.now();
        GameSound.configure(!!data.sound_enabled);
        if (data.custom_sounds) GameSound.loadSounds(data.custom_sounds);
        document.getElementById('phase-label').textContent = states[data.state] || data.state;
        document.getElementById('question-no').textContent = 'SOAL #' + (data.question_number || 0);

        renderModeSelector(data);

        if (isBuzzer(data)) {
            renderBuzzerControls(data);
            renderBuzzerTeams(data);
        } else if (isRanking(data)) {
            renderRankingControls(data);
            renderRankingTeams(data);
        } else if (isLemparan(data)) {
            renderLemparanControls(data);
            renderThrowTeams(data);
        }

        setStatus(data);
    }

    // ─── DEBUG: trace RESET clicks ───
    var debugPanelVisible = new URLSearchParams(window.location.search).get('debug') === 'reset';
    var debugPanel = document.getElementById('debug-reset-panel');
    var debugLog = document.getElementById('debug-reset-log');
    if (debugPanelVisible && debugPanel) debugPanel.style.display = '';

    window.__debugResetLog = [];
    function debugReset(msg, extra) {
        var entry = { time: new Date().toISOString(), msg: msg };
        if (extra !== undefined) entry.extra = extra;
        window.__debugResetLog.push(entry);
        console.log('[RESET]', msg, extra || '');
    }

    document.getElementById('debug-clear-btn').addEventListener('click', function () {
        window.__debugResetLog = [];
        window.__debugResetLogRendered = 0;
        if (debugLog) debugLog.innerHTML = '';
    });

    // Poll to pick up entries added by game.js (which can't call debugReset directly)
    window.__debugResetLogRendered = 0;
    if (debugPanelVisible && debugLog) {
        setInterval(function () {
            var log = window.__debugResetLog;
            var rendered = window.__debugResetLogRendered;
            if (log.length > rendered) {
                for (var i = rendered; i < log.length; i++) {
                    var entry = log[i];
                    var line = document.createElement('div');
                    line.className = 'log-entry' + (entry.msg === 'ERROR' ? ' log-error' : '');
                    var ts = entry.time.split('T')[1].replace('Z', '');
                    var extraStr = entry.extra !== undefined ? (' ' + JSON.stringify(entry.extra)) : '';
                    line.innerHTML = '<span class="log-time">' + ts + '</span> <span class="log-msg">' + esc(entry.msg) + '</span><span class="log-extra">' + esc(extraStr) + '</span>';
                    debugLog.appendChild(line);
                }
                window.__debugResetLogRendered = log.length;
                debugLog.scrollTop = debugLog.scrollHeight;
            }
        }, 200);
    }

    // Verify both reset buttons exist in DOM
    var resetBtn = document.getElementById('reset-btn');
    var rankingResetBtn = document.getElementById('ranking-reset-btn');
    debugReset('DOM check', { 'reset-btn': !!resetBtn, 'ranking-reset-btn': !!rankingResetBtn });
    debugReset('buzzer-controls display:', document.getElementById('buzzer-controls').style.display);
    debugReset('ranking-controls display:', document.getElementById('ranking-controls').style.display);

    // ─── Buzzer mode events (original) ───
    document.getElementById('begin-btn').addEventListener('click', async function () {
        this.disabled = true;
        await GameApi.begin();
    });
    document.getElementById('allow-btn').addEventListener('click', async function () {
        this.disabled = true;
        await GameApi.allow();
    });
    document.getElementById('repeat-btn').addEventListener('click', async function () {
        this.disabled = true;
        await GameApi.repeat();
    });
    document.getElementById('reset-btn').addEventListener('click', async function () {
        debugReset('clicked', { id: 'reset-btn', mode: snap ? snap.game_type : 'unknown' });
        var result = confirm('Reset semua skor & permainan?');
        debugReset('confirm result', result);
        if (!result) return;
        try {
            debugReset('request started');
            var response = await GameApi.reset();
            var httpStatus = window.__debugResetLastHttpStatus || null;
            debugReset('response received', { success: response.success, state: response.snapshot ? response.snapshot.state : 'no-snapshot', error: response.error || null, httpStatus: httpStatus });
        } catch (err) {
            debugReset('ERROR', { message: err.message, stack: err.stack });
        }
    });

    document.getElementById('teams').addEventListener('input', function (e) {
        const el = e.target.closest('[data-custom-input]');
        if (el) customVals[el.dataset.customInput] = el.value;
        const rel = e.target.closest('[data-ranking-custom-input]');
        if (rel) customVals[rel.dataset.rankingCustomInput] = rel.value;
    });

    document.getElementById('teams').addEventListener('click', async function (e) {
        // Buzzer mode scoring
        const btn = e.target.closest('button[data-team]');
        if (btn) {
            btn.disabled = true;
            let points;
            if (btn.hasAttribute('data-custom')) {
                const input = document.querySelector('[data-custom-input="' + btn.dataset.team + '"]');
                points = parseInt(input.value, 10);
            } else {
                points = parseInt(btn.dataset.points, 10);
            }
            if (!isFinite(points)) { btn.disabled = false; return; }
            if (points >= 0) { GameSound.playCorrect(); } else { GameSound.playWrong(); }
            await GameApi.answer(parseInt(btn.dataset.team, 10), points, points >= 0 ? 'benar' : 'salah');
            return;
        }

        // Ranking 1 mode judging
        const jbtn = e.target.closest('button[data-judge-team]');
        if (jbtn) {
            jbtn.disabled = true;
            let points;
            if (jbtn.hasAttribute('data-judge-custom')) {
                const input = document.querySelector('[data-ranking-custom-input="' + jbtn.dataset.judgeTeam + '"]');
                points = parseInt(input.value, 10);
            } else {
                points = parseInt(jbtn.dataset.judgePoints, 10);
            }
            if (!isFinite(points) || points < 0) { jbtn.disabled = false; return; }
            if (points > 0) { GameSound.playCorrect(); } else { GameSound.playWrong(); }
            await GameApi.judgeTeam(parseInt(jbtn.dataset.judgeTeam, 10), points);
        }
    });

    // ─── Ranking 1 controls ───
    document.getElementById('ranking-begin-btn').addEventListener('click', async function () {
        this.disabled = true;
        await GameApi.begin();
    });
    document.getElementById('ranking-reset-btn').addEventListener('click', async function () {
        debugReset('clicked', { id: 'ranking-reset-btn', mode: snap ? snap.game_type : 'unknown' });
        var result = confirm('Reset semua skor & permainan?');
        debugReset('confirm result', result);
        if (!result) return;
        try {
            debugReset('request started');
            var response = await GameApi.reset();
            var httpStatus = window.__debugResetLastHttpStatus || null;
            debugReset('response received', { success: response.success, state: response.snapshot ? response.snapshot.state : 'no-snapshot', error: response.error || null, httpStatus: httpStatus });
        } catch (err) {
            debugReset('ERROR', { message: err.message, stack: err.stack });
        }
    });

    // ─── Mode selector ───
    document.getElementById('mode-selector').addEventListener('click', async function (e) {
        const btn = e.target.closest('button[data-mode]');
        if (!btn || btn.disabled) return;
        btn.disabled = true;
        await GameApi.setGameType(btn.dataset.mode);
    });

    // ─── Lemparan controls ───
    document.getElementById('lemparan-begin-btn').addEventListener('click', function () {
        this.disabled = true;
        lemparanAwaitingPicker = true;
    });
    document.getElementById('lemparan-reset-btn').addEventListener('click', async function () {
        debugReset('clicked', { id: 'lemparan-reset-btn', mode: snap ? snap.game_type : 'unknown' });
        var result = confirm('Reset semua skor & permainan?');
        debugReset('confirm result', result);
        if (!result) return;
        try {
            debugReset('request started');
            var response = await GameApi.reset();
            var httpStatus = window.__debugResetLastHttpStatus || null;
            debugReset('response received', { success: response.success, state: response.snapshot ? response.snapshot.state : 'no-snapshot', error: response.error || null, httpStatus: httpStatus });
        } catch (err) {
            debugReset('ERROR', { message: err.message, stack: err.stack });
        }
    });

    document.getElementById('throw-picker-btns').addEventListener('click', async function (e) {
        const btn = e.target.closest('button[data-throw-pick]');
        if (!btn) return;
        btn.disabled = true;
        lemparanAwaitingPicker = false;
        await GameApi.beginThrow(parseInt(btn.dataset.throwPick, 10));
    });

    // ─── Panel konfigurasi ───
    const configBody = document.getElementById('config-body');

    // DEBUG: track last poll state for comparison
    window.__debugLastPollState = null;

    async function renderConfig() {
        const res = await GameApi.teams();
        if (!res.success) return;

        const tmpl = function (t) {
            return '<div class="config-team" id="team-' + t.id + '">'
                + '<input type="text" value="' + esc(t.name) + '" data-name="' + t.id + '">'
                + '<input type="color" value="' + esc(t.color) + '" data-color="' + t.id + '" title="Warna regu">'
                + '<span class="pill">' + esc(t.identifier || '-') + '</span>'
                + '<label class="chk"><input type="checkbox" data-active="' + t.id + '"' + (t.is_active ? ' checked' : '') + '> Aktif</label>'
                + '<button class="btn" data-save-team="' + t.id + '">Simpan</button>'
                + '<button class="copy-link-btn" data-copy-link="' + t.identifier + '">COPY LINK</button>'
                + '</div>';
        };

        configBody.innerHTML =
            '<div class="config-block"><h3>Regu</h3>'
            + '<div class="config-row">'
            + '<div><label>Nama regu baru</label><input type="text" id="new-name" placeholder="Regu E"></div>'
            + '<div><label>Warna</label><input type="color" id="new-color" value="#22d3ee"></div>'
            + '<button class="btn btn-success" id="add-team-btn" style="margin-bottom:10px">Tambah</button>'
            + '</div>'
            + '<div style="margin-top:6px">' + (res.teams || []).map(tmpl).join('') + '</div></div>'

            + '<div class="config-block"><h3>Pengaturan game</h3>'
            + '<div class="config-row">'
            + '<div><label>Waktu rebutan (detik)</label><input type="number" id="cfg-buzzer" value="' + res.settings.buzzer_seconds + '" min="1" max="120"></div>'
            + '<div><label>Waktu menjawab (detik)</label><input type="number" id="cfg-answer" value="' + res.settings.answer_seconds + '" min="1" max="600"></div>'
            + '</div>'
            + '<div class="config-row" style="margin-top:10px">'
            + '<label class="chk"><input type="checkbox" id="cfg-sound"' + (res.settings.sound_enabled ? ' checked' : '') + '> Suara</label>'
            + '<button class="btn btn-primary" id="save-settings-btn">Simpan pengaturan</button>'
            + '</div></div>';
    }

    configBody.addEventListener('click', async function (e) {
        const saveTeam = e.target.closest('[data-save-team]');
        if (saveTeam) {
            const id = saveTeam.dataset.saveTeam;
            const name = document.querySelector('[data-name="' + id + '"]').value.trim();
            const color = document.querySelector('[data-color="' + id + '"]').value;
            const active = document.querySelector('[data-active="' + id + '"]').checked;
            if (!name) return;
            saveTeam.disabled = true;
            await GameApi.updateTeam(id, { name: name, color: color, is_active: active });
            await renderConfig(); return;
        }
        const addBtn = e.target.closest('#add-team-btn');
        if (addBtn) {
            const name = document.getElementById('new-name').value.trim();
            const color = document.getElementById('new-color').value;
            if (!name) { alert('Nama regu wajib diisi'); return; }
            addBtn.disabled = true;
            await GameApi.createTeam({ name: name, color: color, is_active: true });
            document.getElementById('new-name').value = '';
            await renderConfig(); return;
        }
        const saveSettings = e.target.closest('#save-settings-btn');
        if (saveSettings) {
            saveSettings.disabled = true;
            await GameApi.saveSettings({
                buzzer_seconds: parseInt(document.getElementById('cfg-buzzer').value, 10),
                answer_seconds: parseInt(document.getElementById('cfg-answer').value, 10),
                sound_enabled: document.getElementById('cfg-sound').checked,
            });
            await renderConfig(); return;
        }
        const copyLink = e.target.closest('[data-copy-link]');
        if (copyLink) {
            var identifier = copyLink.dataset.copyLink;
            var link = window.location.origin + '/participant?team=' + encodeURIComponent(identifier);
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(link).then(function () {
                    copyLink.textContent = 'TERCOPY!';
                    copyLink.classList.add('copied');
                    setTimeout(function () { copyLink.textContent = 'COPY LINK'; copyLink.classList.remove('copied'); }, 1500);
                }, function () {
                    fallbackCopy(link, copyLink);
                });
            } else {
                fallbackCopy(link, copyLink);
            }
            return;
        }
    });

    renderConfig();

    // ─── Sound config ───
    const soundBody = document.getElementById('sound-body');

    async function renderSoundConfig() {
        const res = await GameApi.sounds();
        if (!res.success) return;

        var html = '<div class="config-block"><h3>Global Sounds</h3>';
        var types = [
            { key: 'buzzer', label: 'Buzzer Default' },
            { key: 'benar', label: 'Benar' },
            { key: 'salah', label: 'Salah' },
            { key: 'no_buzz', label: 'Tidak Ada yang Mengambil' },
        ];
        types.forEach(function (t) {
            var s = res.sounds[t.key];
            var status = s.exists ? '<span style="color:#4ade80">✓ Active</span>' : '<span style="color:#64748b">Default</span>';
            html += '<div class="config-team">'
                + '<span style="min-width:160px;display:inline-block">' + t.label + ' ' + status + '</span>'
                + '<input type="file" accept=".mp3,.wav,.ogg" data-sound-type="' + t.key + '" style="font-size:12px">'
                + '<button class="btn btn-success" data-sound-upload="' + t.key + '">Upload</button>'
                + (s.exists ? '<button class="btn btn-danger" data-sound-delete="' + s.key + '">Hapus</button>' : '')
                + '</div>';
        });
        html += '</div>';

        // Per-team buzzer sounds
        var teamKeys = Object.keys(res.team_sounds);
        if (teamKeys.length > 0) {
            html += '<div class="config-block"><h3>Buzzer Per Tim</h3>';
            teamKeys.forEach(function (teamId) {
                var ts = res.team_sounds[teamId];
                var status = ts.exists ? '<span style="color:#4ade80">✓ Custom</span>' : '<span style="color:#64748b">Default</span>';
                html += '<div class="config-team">'
                    + '<span style="min-width:160px;display:inline-block">' + esc(ts.team_name) + ' ' + status + '</span>'
                    + '<input type="file" accept=".mp3,.wav,.ogg" data-sound-team="' + ts.team_id + '" style="font-size:12px">'
                    + '<button class="btn btn-success" data-sound-team-upload="' + ts.team_id + '">Upload</button>'
                    + (ts.exists ? '<button class="btn btn-danger" data-sound-delete="' + ts.key + '">Hapus</button>' : '')
                    + '</div>';
            });
            html += '</div>';
        }

        soundBody.innerHTML = html;
    }

    soundBody.addEventListener('click', async function (e) {
        // Upload global sound
        var uploadBtn = e.target.closest('[data-sound-upload]');
        if (uploadBtn) {
            var type = uploadBtn.dataset.soundUpload;
            var input = document.querySelector('[data-sound-type="' + type + '"]');
            if (!input || !input.files.length) { alert('Pilih file audio terlebih dahulu'); return; }
            uploadBtn.disabled = true;
            await GameApi.uploadSound(type, input.files[0]);
            await renderSoundConfig();
            return;
        }
        // Upload team sound
        var teamUploadBtn = e.target.closest('[data-sound-team-upload]');
        if (teamUploadBtn) {
            var teamId = teamUploadBtn.dataset.soundTeamUpload;
            var input = document.querySelector('[data-sound-team="' + teamId + '"]');
            if (!input || !input.files.length) { alert('Pilih file audio terlebih dahulu'); return; }
            teamUploadBtn.disabled = true;
            await GameApi.uploadTeamSound(teamId, input.files[0]);
            await renderSoundConfig();
            return;
        }
        // Delete sound
        var deleteBtn = e.target.closest('[data-sound-delete]');
        if (deleteBtn) {
            if (!confirm('Hapus custom sound ini?')) return;
            deleteBtn.disabled = true;
            await GameApi.deleteSound(deleteBtn.dataset.soundDelete);
            await renderSoundConfig();
            return;
        }
    });

    renderSoundConfig();

    // DEBUG: wrap poll to log state transitions
    var _origRender = render;
    render = function(data) {
        var oldState = window.__debugLastPollState;
        window.__debugLastPollState = data.state;
        if (oldState && oldState !== data.state) {
            debugReset('poll state change', { from: oldState, to: data.state, question: data.question_number, game_type: data.game_type });
        }
        _origRender(data);
    };

    poll(render, 1000);
    requestAnimationFrame(function tick() {
        if (snap) setCountdown(snap);
        requestAnimationFrame(tick);
    });
})();
</script>
@endpush
