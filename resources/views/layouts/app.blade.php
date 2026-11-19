<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- CSRF Token -->
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>Tabulation System</title>

    @auth
        {{-- Scopes the offline score queue to this user (public/js/score-outbox.js) --}}
        <meta name="user-id" content="{{ auth()->id() }}">
    @endauth

    <!-- Favicons -->
    <link href="{{ asset('img/cdo-seal.png') }}" rel="icon">
    <link href="{{ asset('img/pwa/apple-touch-icon.png') }}" rel="apple-touch-icon">

    <!-- PWA -->
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#1d4ed8">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Tabulation">

    <script src="{{ asset('js/jquery-3.7.1.js') }}"></script>

    <!-- Vendor CSS Files -->
    <link href="{{ asset('vendor/bootstrap/css/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    {{-- <link href="{{ asset('vendor/boxicons/css/boxicons.min.css') }}" rel="stylesheet"> --}} {{-- unused --}}
    <link href="{{ asset('vendor/quill/quill.snow.css') }}" rel="stylesheet">
    <link href="{{ asset('vendor/quill/quill.bubble.css') }}" rel="stylesheet">
    {{-- <link href="{{ asset('vendor/remixicon/remixicon.css') }}" rel="stylesheet"> --}} {{-- unused --}}
    <link href="{{ asset('vendor/simple-datatables/style.css') }}" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('vendor/jquery-filepond-master/filepond.css') }}" />
    <link rel="stylesheet" href="{{ asset('vendor/jquery-filepond-master/filepond-plugin-image-preview.css') }}">

    <link href="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/css/lightbox.min.css" rel="stylesheet">

    <!-- Template Main CSS File -->
    <link href="{{ asset('css/style.css') }}" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/custom.css') }}">
    <link rel="stylesheet" href="{{ asset('css/theme.css') }}">
    <link rel="stylesheet" href="{{ asset('css/score-outbox.css') }}">
    <!-- <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.13.1/font/bootstrap-icons.min.css"> -->

    <!-- Scripts -->
    <!-- @vite(['resources/sass/app.scss', 'resources/js/app.js']) -->
</head>

<body class="{{ auth()->guest() ? 'auth-bg' : '' }}">
    @auth
        @include('layouts.header')
        @include('layouts.sidebar')
    @endauth

    <div id="app">
        @yield('content')
    </div>

    <!-- Vendor JS Files -->
    {{-- <script src="{{ asset('vendor/apexcharts/apexcharts.min.js') }}"></script> --}} {{-- unused --}}
    <script src="{{ asset('vendor/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
    {{-- <script src="{{ asset('vendor/chart.js/chart.umd.js') }}"></script> --}} {{-- unused --}}
    <script src="{{ asset('vendor/echarts/echarts.min.js') }}"></script>
    {{-- <script src="{{ asset('vendor/quill/quill.js') }}"></script> --}}
    {{-- <script src="{{ asset('vendor/simple-datatables/simple-datatables.js') }}"></script> --}}
    <script src="{{ asset('vendor/tinymce/tinymce.min.js') }}"></script>
    {{-- <script src="{{ asset('vendor/php-email-form/validate.js') }}"></script> --}} {{-- unused --}}
    <script src="{{ asset('vendor/jquery-filepond-master/filepond.min.js') }}"></script>
    <script src="{{ asset('vendor/jquery-filepond-master/filepond-plugin-file-validate-type.js') }}"></script>
    <script src="{{ asset('vendor/jquery-filepond-master/filepond-plugin-file-validate-size.js') }}"></script>
    <script src="{{ asset('vendor/jquery-filepond-master/filepond-plugin-image-preview.js') }}"></script>
    <script src="{{ asset('vendor/jquery-filepond-master/filepond.jquery.js') }}"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/lightbox2/2.11.4/js/lightbox.min.js"></script>

    <!-- Template Main JS File -->
    <script src="{{ asset('js/main.js') }}"></script>
    <script src="{{ asset('js/score-outbox.js') }}"></script>

    <script>
        // Service workers only run on HTTPS or localhost; on plain-HTTP LAN addresses this is skipped
        if ('serviceWorker' in navigator && window.isSecureContext) {
            window.addEventListener('load', function () {
                navigator.serviceWorker.register(@js(asset('sw.js')), { scope: @js(rtrim(asset(''), '/') . '/') })
                    .catch(function (err) { console.warn('Service worker not registered:', err); });
            });
        }
    </script>

</body>

</html>
