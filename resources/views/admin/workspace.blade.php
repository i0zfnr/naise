<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="theme-color" content="#faf8f3">
    <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32.png?v=5">
    <title>Naise Admin</title>
    @viteReactRefresh
    @vite('resources/js/admin.tsx')
</head>
<body><div id="root"></div></body>
</html>
