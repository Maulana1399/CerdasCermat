// Suara via Web Audio API (tanpa dependency eksternal).
// Mendukung custom audio files (MP3/WAV/OGG) dengan fallback ke built-in tones.
//
// Autoplay policy browser: AudioContext hanya boleh hidup/di-resume dalam
// event interaksi pengguna (gesture). AudioContext TIDAK pernah dibuat dari
// polling — ia dibuat/resume hanya pada interaksi pertama (SATU kali), lalu
// event game dari polling otomatis bisa berbunyi.
// Dihormati setting server `sound_enabled` (server sumber kebenaran).
// Seluruh path diamankan try/catch: audio gagal tidak boleh menghentikan
// polling/game atau memunculkan error JS.
(function () {
    'use strict';

    let ctx = null;
    let enabled = false;
    let unlocked = false;

    // Custom sound URLs keyed by setting key (e.g. 'sound_buzzer', 'sound_buzzer_team_5')
    let customUrls = {};

    // HTMLAudioElement cache keyed by URL
    const audioCache = {};

    function createCtx() {
        const AC = window.AudioContext || window.webkitAudioContext;
        if (!AC) return null;
        try {
            return new AC();
        } catch (e) {
            return null;
        }
    }

    function unlock() {
        if (unlocked) return;
        const c = createCtx();
        if (!c) return;

        try {
            const len = Math.min(Math.max(1, Math.floor(c.sampleRate * 0.05)), 4800);
            const buf = c.createBuffer(1, len, c.sampleRate);
            const data = buf.getChannelData(0);
            for (let i = 0; i < len; i++) data[i] = 0;
            const src = c.createBufferSource();
            const mute = c.createGain();
            mute.gain.value = 0;
            src.buffer = buf;
            src.connect(mute);
            mute.connect(c.destination);
            src.start(0);
        } catch (e) { /* audionya tetap dipakai apa adanya */ }

        if (c.state === 'suspended') {
            c.resume().catch(function () {});
        }

        ctx = c;
        unlocked = true;
        try {
            window.dispatchEvent(new Event('game-sound-unlocked'));
        } catch (e) { /* event hanya informatif */ }
    }

    if (typeof document !== 'undefined') {
        ['pointerdown', 'keydown', 'touchstart', 'click'].forEach(function (ev) {
            document.addEventListener(ev, unlock);
        });
    }

    const TONES = {
        buzz:  { freqs: [987, 1319], type: 'square',   dur: 0.09, gap: 0.06, vol: 0.22 },
        benar: { freqs: [523, 784],  type: 'sine',     dur: 0.16, gap: 0.08, vol: 0.25 },
        salah: { freqs: [220, 165],  type: 'sawtooth', dur: 0.20, gap: 0.10, vol: 0.18 },
    };

    // Play a built-in synthesized tone
    function playTone(name) {
        if (!ctx) return;
        const tone = TONES[name];
        if (!tone) return;

        try {
            const now = ctx.currentTime;
            tone.freqs.forEach(function (freq, i) {
                const start = now + i * (tone.dur + tone.gap);
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();

                osc.type = tone.type;
                osc.frequency.value = freq;

                gain.gain.setValueAtTime(0.0001, start);
                gain.gain.exponentialRampToValueAtTime(tone.vol, start + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, start + tone.dur);

                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start(start);
                osc.stop(start + tone.dur + 0.02);
            });
        } catch (e) { /* suara opsional — tanpa error JS */ }
    }

    // Play a custom audio file by URL. Returns true if played.
    function playCustomAudio(url) {
        if (!url || !ctx) return false;
        try {
            let audio = audioCache[url];
            if (!audio) {
                audio = new Audio(url);
                audio.preload = 'auto';
                audioCache[url] = audio;
            }
            audio.currentTime = 0;
            audio.play().catch(function () {});
            return true;
        } catch (e) {
            return false;
        }
    }

    window.GameSound = {
        configure(flag) {
            enabled = !!flag;
        },
        isEnabled() {
            return enabled;
        },
        isUnlocked() {
            return unlocked;
        },

        // Load custom sound configuration from server
        loadSounds(sounds) {
            customUrls = {};
            if (!sounds) return;
            // Global sounds
            if (sounds.buzzer && sounds.buzzer.url) customUrls.sound_buzzer = sounds.buzzer.url;
            if (sounds.benar && sounds.benar.url) customUrls.sound_benar = sounds.benar.url;
            if (sounds.salah && sounds.salah.url) customUrls.sound_salah = sounds.salah.url;
            if (sounds.no_buzz && sounds.no_buzz.url) customUrls.sound_no_buzz = sounds.no_buzz.url;
            // Per-team sounds
            if (sounds.team_sounds) {
                Object.keys(sounds.team_sounds).forEach(function (teamId) {
                    var ts = sounds.team_sounds[teamId];
                    if (ts && ts.url) customUrls['sound_buzzer_team_' + teamId] = ts.url;
                });
            }
        },

        // Legacy play by name (buzz/benar/salah)
        play(name) {
            if (!enabled) return;
            if (!ctx) return;
            try {
                playTone(name);
            } catch (e) { /* suara opsional */ }
        },

        // Play buzzer with team-specific → global buzzer → built-in fallback
        playBuzz(teamId) {
            if (!enabled || !ctx) return;
            try {
                // 1. Team-specific buzzer sound
                if (teamId) {
                    var teamKey = 'sound_buzzer_team_' + teamId;
                    if (customUrls[teamKey]) {
                        if (playCustomAudio(customUrls[teamKey])) return;
                    }
                }
                // 2. Global buzzer sound
                if (customUrls.sound_buzzer) {
                    if (playCustomAudio(customUrls.sound_buzzer)) return;
                }
                // 3. Built-in tone
                playTone('buzz');
            } catch (e) { /* suara opsional */ }
        },

        // Play correct answer sound
        playCorrect() {
            if (!enabled || !ctx) return;
            try {
                if (customUrls.sound_benar) {
                    if (playCustomAudio(customUrls.sound_benar)) return;
                }
                playTone('benar');
            } catch (e) { /* suara opsional */ }
        },

        // Play wrong answer sound
        playWrong() {
            if (!enabled || !ctx) return;
            try {
                if (customUrls.sound_salah) {
                    if (playCustomAudio(customUrls.sound_salah)) return;
                }
                playTone('salah');
            } catch (e) { /* suara opsional */ }
        },

        // Play no-buzzer-taken sound
        playNoBuzz() {
            if (!enabled || !ctx) return;
            try {
                if (customUrls.sound_no_buzz) {
                    if (playCustomAudio(customUrls.sound_no_buzz)) return;
                }
                // Default: no specific built-in tone for no-buzz, use silence
            } catch (e) { /* suara opsional */ }
        },
    };
})();
