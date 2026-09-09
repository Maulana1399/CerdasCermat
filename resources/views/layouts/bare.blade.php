<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Cerdas Cermat')</title>
    <style>
        :root { color-scheme: dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: system-ui, -apple-system, sans-serif;
            background: #0b1120;
            color: #e2e8f0;
        }
        main { max-width: 1200px; margin: 0 auto; padding: 16px; }
        .row { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; }
        .muted { color: #7d8ca3; }
        .pill {
            display: inline-block; padding: 4px 12px; border-radius: 999px;
            background: #1e293b; font-size: 13px; font-weight: 700;
        }
        .pill.gold { background: #713f12; color: #fde047; }
        .btn {
            border: 0; border-radius: 14px; padding: 14px 22px;
            font-size: 18px; font-weight: 700; cursor: pointer;
            background: #26334a; color: #e2e8f0; touch-action: manipulation;
        }
        .btn:hover:not(:disabled) { filter: brightness(1.15); }
        .btn:disabled { opacity: .4; cursor: not-allowed; }
        .btn-primary { background: #2563eb; }
        .btn-success { background: #16a34a; }
        .btn-danger { background: #b91c1c; }
        .dot {
            display: inline-block; width: 18px; height: 18px; border-radius: 50%;
            vertical-align: middle; margin-right: 6px;
        }
        label { display: block; font-size: 13px; color: #94a3b8; margin-bottom: 4px; }
        input, select {
            padding: 12px; border-radius: 10px; border: 1px solid #1e293b;
            background: #0f172a; color: #e2e8f0; font-size: 16px;
        }
        input[type=number] { width: 110px; }
        input[type=color] { width: 56px; height: 44px; padding: 4px; cursor: pointer; }
        /* Panel kartu regu (display & operator) */
        .team-card {
            border-radius: 16px; padding: 14px; background: #0f172a;
            border: 2px solid #243047; text-align: center;
        }
        .team-card.winner { border-color: #facc15; box-shadow: 0 0 0 3px rgba(250,204,21,.2); }
        .team-card.scored { border-color: #4ade80; }
        .team-card .score { font-size: 40px; font-weight: 800; margin-top: 4px; line-height: 1; }
        pre { white-space: pre-wrap; }
    </style>
    @stack('styles')
</head>
<body class="@yield('body-class', '')">
    @yield('content')
        <script src="{{ asset('js/sound.js') }}?v={{ filemtime(public_path('js/sound.js')) }}"></script>
        <script src="{{ asset('js/game.js') }}?v={{ filemtime(public_path('js/game.js')) }}"></script>
    @stack('scripts')
</body>
</html>