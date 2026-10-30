<!doctype html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32.png?v=5">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#171716">
    <title>Admin sign in · Naise v3</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f7f6f2;color:#211f1c;font-family:Arial,sans-serif;min-height:100vh;display:grid;place-items:center;padding:24px}.card{width:min(430px,100%);background:#fff;padding:38px;border:1px solid #ebe7df}.brand{width:56px;height:56px;border-radius:10px;overflow:hidden;background:#000;margin-bottom:24px}.brand img{display:block;width:100%;height:100%;object-fit:contain}.lockout{background:#fff3dc;border:1px solid #edce91;border-radius:8px;padding:12px;font-size:13px;line-height:1.6;color:#765314}.submit:disabled{opacity:.5;cursor:wait}.eyebrow{font:10px monospace;letter-spacing:.16em;color:#928b80}.card h1{font:500 38px Georgia;margin:8px 0}.subtitle{color:#827c72;font-size:13px;line-height:1.6;margin-bottom:28px}label{display:block;font:10px monospace;letter-spacing:.1em;margin:17px 0 7px}input{width:100%;padding:13px;border:1px solid #ddd8ce;font-size:14px;outline-color:#e8ab34}.error{font-size:12px;color:#ae3d2e;margin-top:10px}.submit{margin-top:22px;width:100%;padding:15px;background:#171716;color:#fff;border:0;font:11px monospace;letter-spacing:.12em;cursor:pointer}.foot{font-size:10px;color:#999187;margin-top:22px}.foot a{color:#514b42}
    </style>
<script src="/admin-login.js?v=1" defer></script>
</head>
<body data-retry-after="{{ $retryAfter ?? 0 }}"><main class="card"><div class="brand"><img src="/icons/naise-192.png?v=5" alt="Naise Coffee logo"></div><p class="eyebrow">NAISE COFFEE · V3</p><h1>Admin access</h1><p class="subtitle">Use your Naise administrator email and password. Customer sign-in codes and your Gmail password do not grant admin access.</p>@if(isset($retryAfter))<p class="lockout" role="alert">Too many unsuccessful sign-in attempts. Please wait <span id="retry-seconds">{{ $retryAfter }}</span> seconds before trying again. Your account has not been deleted.</p>@endif<form method="POST" action="{{ route('admin.login.submit') }}">@csrf<label for="email">EMAIL</label><input id="email" name="email" type="email" autocomplete="username" required value="{{ old('email', $loginEmail ?? '') }}">@error('email')<div class="error">{{ $message }}</div>@enderror<label for="password">PASSWORD</label><input id="password" name="password" type="password" autocomplete="current-password" required>@error('password')<div class="error">{{ $message }}</div>@enderror<button class="submit" type="submit" @disabled(isset($retryAfter))>SIGN IN <span style="float:right">↗</span></button></form><p class="foot"><a href="{{ url('/') }}">← Back to menu</a></p></main></body>
</html>
