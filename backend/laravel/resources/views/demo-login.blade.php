<!doctype html>
<html lang="en">
<head>
    <link rel="icon" type="image/png" sizes="32x32" href="/icons/favicon-32.png?v=5">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#171716">
    <title>Demo customer sign in · Naise v3</title>
    <style>
        *{box-sizing:border-box}body{margin:0;background:#f7f6f2;color:#211f1c;font-family:Arial,sans-serif;min-height:100vh;display:grid;place-items:center;padding:24px}.card{width:min(430px,100%);background:#fff;padding:38px;border:1px solid #ebe7df}.brand{font:italic 27px Georgia;border:1px solid #211f1c;width:46px;height:46px;border-radius:50%;display:grid;place-items:center;margin-bottom:32px}.eyebrow{font:10px monospace;letter-spacing:.16em;color:#928b80}.card h1{font:500 38px Georgia;margin:8px 0}.subtitle{color:#827c72;font-size:13px;line-height:1.6;margin-bottom:28px}label{display:block;font:10px monospace;letter-spacing:.1em;margin:17px 0 7px}input{width:100%;padding:13px;border:1px solid #ddd8ce;font-size:14px;outline-color:#e8ab34}.error{font-size:12px;color:#ae3d2e;margin-top:10px}.submit{margin-top:22px;width:100%;padding:15px;background:#171716;color:#fff;border:0;font:11px monospace;letter-spacing:.12em;cursor:pointer}.foot{font-size:10px;color:#999187;margin-top:22px}.foot a{color:#514b42}.demo-note{margin-top:18px;padding:13px;background:#f8f6f0;font:11px/1.7 monospace;color:#655e53}
    </style>
</head>
<body>
    <main class="card">
        <div class="brand">N</div>
        <p class="eyebrow">NAISE COFFEE · LOCAL DEMO</p>
        <h1>Customer sign in</h1>
        <p class="subtitle">Use the sample customer account to preview points, rewards, vouchers, and recent orders.</p>
        <form method="POST" action="{{ route('demo.login.submit') }}">
            @csrf
            <label for="email">EMAIL</label>
            <input id="email" name="email" type="email" autocomplete="username" required value="{{ old('email', 'client@naise.test') }}">
            @error('email')<div class="error">{{ $message }}</div>@enderror
            <label for="password">PASSWORD</label>
            <input id="password" name="password" type="password" autocomplete="current-password" value="NaiseTestClient-v3!" required>
            <button class="submit" type="submit">SIGN IN <span style="float:right">↗</span></button>
        </form>
        <div class="demo-note">Local demo only · Sample account, points, vouchers, and orders are fictional.</div>
        <p class="foot"><a href="{{ url('/') }}">← Back to menu</a></p>
    </main>
</body>
</html>
