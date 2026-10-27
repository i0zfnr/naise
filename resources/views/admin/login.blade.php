<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#171716">
    <title>Admin sign in · Naise v3</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f7f6f2;color:#211f1c;font-family:Arial,sans-serif;min-height:100vh;display:grid;place-items:center;padding:24px}.card{width:min(430px,100%);background:#fff;padding:38px;border:1px solid #ebe7df}.brand{font:italic 27px Georgia;border:1px solid #211f1c;width:46px;height:46px;border-radius:50%;display:grid;place-items:center;margin-bottom:32px}.eyebrow{font:10px monospace;letter-spacing:.16em;color:#928b80}.card h1{font:500 38px Georgia;margin:8px 0}.subtitle{color:#827c72;font-size:13px;line-height:1.6;margin-bottom:28px}label{display:block;font:10px monospace;letter-spacing:.1em;margin:17px 0 7px}input{width:100%;padding:13px;border:1px solid #ddd8ce;font-size:14px;outline-color:#e8ab34}.error{font-size:12px;color:#ae3d2e;margin-top:10px}.submit{margin-top:22px;width:100%;padding:15px;background:#171716;color:#fff;border:0;font:11px monospace;letter-spacing:.12em;cursor:pointer}.foot{font-size:10px;color:#999187;margin-top:22px}.foot a{color:#514b42}
    </style>
</head>
<body><main class="card"><div class="brand">N</div><p class="eyebrow">NAISE COFFEE · V3</p><h1>Admin access</h1><p class="subtitle">Sign in to the store dashboard to view overview data and manage store mode.</p><form method="POST" action="{{ route('admin.login.submit') }}">@csrf<label for="email">EMAIL</label><input id="email" name="email" type="email" autocomplete="username" required value="{{ old('email') }}">@error('email')<div class="error">{{ $message }}</div>@enderror<label for="password">PASSWORD</label><input id="password" name="password" type="password" autocomplete="current-password" required>@error('password')<div class="error">{{ $message }}</div>@enderror<button class="submit" type="submit">SIGN IN <span style="float:right">↗</span></button></form><p class="foot"><a href="{{ url('/') }}">← Back to menu</a></p></main></body>
</html>
