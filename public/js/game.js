(function () {
    'use strict';

    window.GameApi = {
        async state() {
            const res = await fetch('/api/game/state');
            return res.json();
        },

        async get(path) {
            const res = await fetch(path, { headers: { 'Accept': 'application/json' } });
            return res.json();
        },

        async post(path, body) {
            if (window.__debugResetLog && path === '/api/game/reset') {
                window.__debugResetLog.push({ time: new Date().toISOString(), msg: 'fetch sending', extra: { path: path, body: body } });
                console.log('[RESET]', 'fetch sending', path, body);
            }
            const res = await fetch(path, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(body || {}),
            });
            if (window.__debugResetLog && path === '/api/game/reset') {
                window.__debugResetLog.push({ time: new Date().toISOString(), msg: 'fetch status', extra: { status: res.status, ok: res.ok } });
                window.__debugResetLastHttpStatus = res.status;
                console.log('[RESET]', 'fetch status', res.status, res.ok);
            }
            return res.json();
        },

        async patch(path, body) {
            const res = await fetch(path, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                body: JSON.stringify(body || {}),
            });
            return res.json();
        },

        begin() { return this.post('/api/game/begin'); },
        repeat() { return this.post('/api/game/repeat'); },
        allow() { return this.post('/api/game/allow'); },
        resolve() { return this.post('/api/game/resolve'); },
        reset() { return this.post('/api/game/reset'); },
        buzz(identifier) { return this.post('/api/game/buzz', { identifier }); },
        buzzTeam(teamId) { return this.post('/api/game/buzz', { team_id: teamId }); },
        score(teamId, points, kind) { return this.post('/api/game/score', { team_id: teamId, points, kind }); },
        answer(teamId, points, kind) { return this.post('/api/game/answer', { team_id: teamId, points, kind }); },
        setGameType(gameType) { return this.post('/api/game/set-type', { game_type: gameType }); },
        beginThrow(teamId) { return this.post('/api/game/begin-throw', { team_id: teamId }); },
        judgeTeam(teamId, points) { return this.post('/api/game/judge', { team_id: teamId, points }); },

        teams() { return this.get('/api/teams'); },
        createTeam(payload) { return this.post('/api/teams', payload); },
        updateTeam(id, payload) { return this.patch('/api/teams/' + id, payload); },
        saveSettings(payload) { return this.post('/api/settings', payload); },

        sounds() { return this.get('/api/sounds'); },
        uploadSound(type, file) {
            const fd = new FormData();
            fd.append('type', type);
            fd.append('file', file);
            return fetch('/api/sounds/upload', { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd }).then(function (r) { return r.json(); });
        },
        uploadTeamSound(teamId, file) {
            const fd = new FormData();
            fd.append('team_id', teamId);
            fd.append('file', file);
            return fetch('/api/sounds/upload-team', { method: 'POST', headers: { 'Accept': 'application/json' }, body: fd }).then(function (r) { return r.json(); });
        },
        deleteSound(key) { return this.post('/api/sounds/delete', { key: key }); },
    };

    // Sisa waktu fase (ms) berdasarkan deadline & server_time dari server,
    // dikoreksi dengan selisih jam client-server pada saat fetch:
    //   sisa = deadline - (server_time + (sekarang - saat_fetch))
    // Laravel/MySQL mengirim fraksi detik 6 digit (.000000); potong ke presisi
    // ms agar `new Date(ISO)` valid di semua browser, bukan NaN.
    window.gameRemainingMs = function (deadlineIso, serverTimeIso, nowMs, fetchedAtMs) {
        function parseIso(iso) {
            if (!iso) return null;
            const norm = String(iso).replace(/\.(\d{3})\d+/, '.$1');
            const t = new Date(norm).getTime();
            return isNaN(t) ? null : t;
        }

        const now = nowMs || Date.now();
        const deadline = parseIso(deadlineIso);
        const serverAtFetch = parseIso(serverTimeIso);
        if (deadline === null || serverAtFetch === null) return null;
        return deadline - (serverAtFetch + (now - (fetchedAtMs || now)));
    };

    window.esc = function (s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };

    window.poll = function (render, intervalMs) {
        async function tick() {
            try {
                const data = await GameApi.state();
                window.__gameLastStateAt = Date.now();
                render(data.snapshot || data);
            } catch (e) { /* server belum siap, coba lagi */ }
        }
        tick();
        setInterval(tick, intervalMs || 1500);
    };
})();