@extends('layouts.bare')

@section('title', 'Buzzer Peserta')

@section('body-class', 'participant-page')

@push('styles')
<style>
    body.participant-page { background: #0b1120; height: 100vh; overflow: hidden; }
    body.participant-page main, body.participant-page > main { max-width: none; padding: 0; height: 100vh; }

    /* SATU tombol besar: merah = aktif (buzzing), abu-abu = tidak aktif. */
    button.buzz {
        position: fixed; inset: 0; margin: 0; padding: 0; border: 0; width: 100%; height: 100%;
        font-size: clamp(28px, 7vw, 64px); font-weight: 900; letter-spacing: .06em;
        color: #fff; cursor: pointer; touch-action: manipulation;
        display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px;
        transition: background-color .15s ease;
    }
    button.buzz .team-label { font-size: clamp(16px, 3.5vw, 30px); font-weight: 700; opacity: .85; }
    button.buzz .mode-label { font-size: clamp(12px, 2vw, 18px); font-weight: 700; opacity: .55; letter-spacing: .1em; }
    button.buzz.on { background: #dc2626; }
    button.buzz.on:active { background: #991b1b; transform: scale(.99); }
    button.buzz.off { background: #374151; color: #9ca3af; }

    /* Pilih regu: layar penuh, tombol besar berwarna regu. */
    #team-picker { position: fixed; inset: 0; background: #0b1120; padding: 5vh 5vw;
        display: flex; flex-direction: column; gap: 6vh; overflow: auto; }
    #team-picker h1 { text-align: center; margin: 0; font-size: clamp(20px, 3vw, 28px); }
    .picker-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 3vh; }
    .picker-grid button {
        border: 0; border-radius: 18px; padding: 4vh 1vw; cursor: pointer;
        font-size: clamp(18px, 3vw, 30px); font-weight: 800; color: #0b1120;
    }
</style>
@endpush

@section('content')
    <div id="team-picker" hidden>
        <h1>PILIH REGU</h1>
        <div class="picker-grid" id="picker-grid"></div>
    </div>

    <button id="buzz-btn" class="buzz off" type="button">
        <span>BUZZER</span>
        <span class="team-label" id="buzz-team-label"></span>
        <span class="mode-label" id="buzz-mode-label"></span>
    </button>
@endsection

@push('scripts')
<script>
(function () {
    const picker = document.getElementById('team-picker');
    const grid = document.getElementById('picker-grid');
    const btnBuzz = document.getElementById('buzz-btn');
    const teamLabel = document.getElementById('buzz-team-label');
    const modeLabel = document.getElementById('buzz-mode-label');

    let my = null;       // { id, identifier, name }
    let canPress = false;
    let prevState = null;

    function teamFromQuery() {
        const params = new URLSearchParams(location.search);
        const q = (params.get('team') || params.get('identifier') || '').trim().toUpperCase();
        return q || (localStorage.getItem('cac_team') || '').trim().toUpperCase() || null;
    }

    function renderKey() {
        btnBuzz.className = 'buzz ' + (canPress ? 'on' : 'off');
    }

    function selectTeam(team) {
        my = { id: team.id, identifier: team.identifier, name: team.name };
        localStorage.setItem('cac_team', String(team.identifier));
        teamLabel.textContent = team.name;
        picker.hidden = true;
        btnBuzz.hidden = false;
        renderKey();
    }

    btnBuzz.addEventListener('click', async function () {
        if (!my || !canPress) return;
        canPress = false;                       // cegah double-tap/rebound
        renderKey();
        if (my.id) await GameApi.buzzTeam(my.id); else await GameApi.buzz(my.identifier);
    });

    // Terapkan state server (sumber kebenaran) ke tombol.
    // Suara winner hanya dipicu transisi snapshot -> buzzed (pemilik regu).
    function apply(data) {
        GameSound.configure(!!data.sound_enabled);
        if (data.custom_sounds) GameSound.loadSounds(data.custom_sounds);

        // Tampilkan mode
        const isRanking = (data.game_type || 'buzzer') === 'ranking_1';
        const isLemparan = (data.game_type || 'buzzer') === 'lemparan';
        modeLabel.textContent = isRanking ? 'RANKING 1' : isLemparan ? 'LEMPARAN' : '';

        if (my) {
            if (prevState === null) {
                prevState = data.state;
            } else if (prevState !== data.state) {
                prevState = data.state;
                if (data.state === 'buzzed' && data.winner && my.id === data.winner.id) {
                    GameSound.playBuzz(my.id);
                }
            }
        }

        // Dalam Ranking 1/Lemparan, buzzer tidak digunakan — tombol selalu off.
        canPress = (isRanking || isLemparan) ? false : !!data.buzzer_open;
        renderKey();
    }

    function buildPicker(teams, data) {
        if (grid.childElementCount) return;
        (teams || []).forEach(function (t) {
            const b = document.createElement('button');
            b.style.background = t.color;
            b.textContent = t.name;
            b.addEventListener('click', function () {
                selectTeam(t);
                apply(data);
            });
            grid.appendChild(b);
        });
    }

    function boot() {
        const q = teamFromQuery();

        if (!q) {
            btnBuzz.hidden = true;
            picker.hidden = false;
        }

        poll(function (data) {
            const teams = data.teams || [];

            if (q && !my) {
                // Refresh: pulihkan identitas regu dari ?team= / localStorage
                // menjadi objek team, sehingga `my` terisi dan guard tombol lulus.
                const restored = teams.find(function (t) {
                    return t.identifier && String(t.identifier).toUpperCase() === q;
                });

                if (restored) {
                    selectTeam(restored);
                } else if (teams.length) {
                    // Identifier tersimpan tidak dikenali server: tampilkan
                    // picker agar regu dapat dipilih ulang.
                    picker.hidden = false;
                    btnBuzz.hidden = true;
                    buildPicker(teams, data);
                }
            } else if (!q) {
                buildPicker(teams, data);
            }

            apply(data);
        }, 700);
    }

    boot();
})();
</script>
@endpush
