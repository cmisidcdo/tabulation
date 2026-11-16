<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <link href="{{ asset('img/cdo-seal.png') }}" rel="icon">
    <link href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <title>People's Choice Award</title>
    <style>
        html, body { margin: 0; height: 100%; background: #0d1b2a; overflow: hidden; }
        body { font-family: "Poppins", Arial, Helvetica, sans-serif; color: #fff; }
        .pcl-screen {
            height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center;
            text-align: center; padding: 4vh 4vw; box-sizing: border-box;
            background: radial-gradient(ellipse at center, #1f3b5a 0%, #0d1b2a 70%);
        }
        .pcl-kicker { font-size: 4.5vh; letter-spacing: .3em; text-transform: uppercase; color: #ffd76a; font-weight: 800; }
        .pcl-event { font-size: 3.5vh; text-transform: uppercase; opacity: .85; margin-top: 1vh; }
        .pcl-trophy { font-size: 16vh; color: #ffd76a; margin: 3vh 0 1vh; text-shadow: 0 0 40px rgba(255, 215, 106, .5); animation: pcl-pop .8s ease-out; }
        .pcl-winner { font-size: 11vh; font-weight: 900; text-transform: uppercase; line-height: 1.05; animation: pcl-pop .8s ease-out; }
        .pcl-members { font-size: 3.5vh; opacity: .85; margin-top: 1.5vh; }
        .pcl-note { font-size: 3vh; color: #ffd76a; margin-top: 2vh; }
        .pcl-wait { font-size: 7vh; font-weight: 700; margin-top: 6vh; opacity: .9; }
        .pcl-vote { display: flex; align-items: center; justify-content: center; gap: 5vw; margin-top: 5vh; }
        .pcl-qr { width: 55vh; height: 55vh; background: #fff; padding: 2vh; border-radius: 2vh; box-sizing: border-box; }
        .pcl-qr svg { width: 100%; height: 100%; display: block; }
        .pcl-qr svg .dark { fill: #000; }
        .pcl-vote-text { text-align: left; }
        .pcl-cta { font-size: 9vh; font-weight: 900; color: #ffd76a; }
        .pcl-steps { font-size: 3.5vh; margin-top: 2vh; opacity: .9; }
        .pcl-small { font-size: 3vh; opacity: .7; }
        @keyframes pcl-pop { 0% { transform: scale(.6); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
    </style>
</head>

<body>
    @livewire('peoples-choice-led', ['slug' => $category->category])
</body>

</html>
