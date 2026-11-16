<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <link href="{{ asset('img/cdo-seal.png') }}" rel="icon">
    <title>People's Choice Award - {{ $category->description }}</title>
    <link href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <style>
        [x-cloak] { display: none !important; }

        body {
            background: #1f3b5a linear-gradient(160deg, #1f3b5a 0%, #266da7 100%) fixed;
            min-height: 100vh;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            color: #1d2733;
        }

        .pc-wrap { max-width: 560px; margin: 0 auto; padding: 16px 16px 110px; }
        .pc-header { color: #fff; padding: 12px 0 18px; }
        .pc-logo { width: 64px; height: 64px; object-fit: contain; margin-bottom: 8px; }
        .pc-kicker { font-size: .8rem; letter-spacing: .18em; text-transform: uppercase; color: #ffd76a; font-weight: 700; }
        .pc-title { font-size: 1.5rem; font-weight: 800; text-transform: uppercase; margin: 4px 0 0; }
        .pc-instructions { color: #fff; font-size: .95rem; }

        .pc-card { background: #fff; border-radius: 16px; box-shadow: 0 8px 24px rgba(0, 0, 0, .12); padding: 24px; }
        .pc-state-icon { font-size: 3.5rem; line-height: 1; margin-bottom: 12px; }

        .pc-list { display: flex; flex-direction: column; gap: 12px; }
        .pc-option {
            display: flex; align-items: center; gap: 14px; width: 100%; text-align: left;
            background: #fff; border: 2px solid transparent; border-radius: 14px; padding: 16px;
            box-shadow: 0 4px 14px rgba(0, 0, 0, .08); transition: border-color .15s, transform .15s;
        }
        .pc-option:active { transform: scale(.98); }
        .pc-option.is-selected { border-color: #266da7; background: #eef5fc; }
        .pc-radio {
            flex: 0 0 28px; height: 28px; border-radius: 50%; border: 2px solid #b8c4d0;
            display: flex; align-items: center; justify-content: center; color: transparent;
        }
        .pc-option.is-selected .pc-radio { background: #266da7; border-color: #266da7; color: #fff; }
        .pc-option-body { display: flex; flex-direction: column; min-width: 0; }
        .pc-option-name { font-weight: 700; font-size: 1.1rem; text-transform: uppercase; }
        .pc-option-members { font-size: .85rem; color: #6b7785; }

        .pc-submit-bar {
            position: fixed; left: 0; right: 0; bottom: 0; padding: 12px 16px calc(12px + env(safe-area-inset-bottom));
            background: rgba(255, 255, 255, .96); box-shadow: 0 -4px 16px rgba(0, 0, 0, .1);
        }
        .pc-submit-bar .pc-submit { max-width: 528px; margin: 0 auto; display: block; background: #266da7; color: #fff; font-weight: 700; border-radius: 12px; }
        .pc-submit:disabled { background: #9fb4c8; color: #fff; }
    </style>
</head>

<body>
    @livewire('public-vote', ['slug' => $category->category])
</body>

</html>
