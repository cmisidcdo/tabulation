<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="{{ asset('img/cdo-seal.png') }}" rel="icon">
    <title>People's Choice QR - {{ $category->description }}</title>
    <style>
        @page { size: A4 portrait; margin: 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; color: #1d2733; background: #fff; }
        .poster { max-width: 720px; margin: 0 auto; padding: 32px 24px; text-align: center; }
        .logo { width: 90px; height: 90px; object-fit: contain; }
        .kicker { margin-top: 12px; font-size: 18px; letter-spacing: .2em; text-transform: uppercase; color: #b8860b; font-weight: 700; }
        h1 { margin: 6px 0 4px; font-size: 40px; text-transform: uppercase; color: #1f3b5a; }
        .event { font-size: 24px; font-weight: 700; text-transform: uppercase; color: #266da7; }
        .qr { margin: 28px auto 16px; width: 440px; max-width: 100%; border: 6px solid #1f3b5a; border-radius: 18px; padding: 10px; }
        .qr img { width: 100%; display: block; image-rendering: pixelated; }
        .cta { font-size: 28px; font-weight: 800; }
        .steps { font-size: 17px; color: #555; margin-top: 8px; line-height: 1.6; }
        .url { margin-top: 18px; font-size: 13px; color: #888; word-break: break-all; }
        .print-btn { margin-top: 24px; padding: 10px 24px; font-size: 16px; cursor: pointer; }
        @media print { .print-btn { display: none; } }
    </style>
</head>

<body>
    <div class="poster">
        <img src="{{ asset('img/cdo-seal.png') }}" alt="" class="logo">
        <div class="kicker">Vote now</div>
        <h1>People's Choice Award</h1>
        <div class="event">{{ $category->description }}</div>

        <div class="qr"><img src="{{ $qr }}" alt="QR code for {{ $voteUrl }}"></div>

        <div class="cta">Scan with your phone camera to vote!</div>
        <div class="steps">1. Scan the QR code &nbsp; 2. Choose your favorite group &nbsp; 3. Tap Submit Vote<br>One vote per device.</div>
        <div class="url">{{ $voteUrl }}</div>

        <button class="print-btn" onclick="window.print()">Print</button>
    </div>
</body>

</html>
