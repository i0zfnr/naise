<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#171716">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="google-login-enabled" content="{{ config('services.google.client_id') ? 'true' : 'false' }}">
    <meta name="demo-login-enabled" content="{{ app()->environment('local') ? 'true' : 'false' }}">
    <meta name="profile-tab-enabled" content="{{ session('profile') ? 'true' : 'false' }}">
    <meta name="demo-login-enabled" content="{{ app()->environment('local') ? 'true' : 'false' }}">
    <meta name="store-mode-enabled" content="{{ session('store_mode') ? 'true' : 'false' }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Naise v3">
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="icon" type="image/png" sizes="512x512" href="/icons/naise-512.png?v=3">
    <link rel="shortcut icon" href="/icons/naise-512.png?v=3">
    <link rel="apple-touch-icon" href="/icons/naise-512.png">
    <title>Naise Coffee v3 · Menu</title>
    @viteReactRefresh
    @vite('resources/js/main.tsx')
</head>
<body>
    <div id="root"></div>
</body>
</html>
