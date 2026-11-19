<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Set your password | INTERFREIGHT GROUP</title>
    <style>
        *{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#f2f5f8;color:#102f4b;font:15px/1.5 "DM Sans",Arial,sans-serif}.card{width:min(100%,540px);padding:36px;background:#fff;border:1px solid #dae3eb;border-radius:14px;box-shadow:0 18px 55px #173d5b12}.brand{margin:0 0 26px;color:#174d78;font-size:16px;font-weight:800;letter-spacing:.09em}.eyebrow{color:#4686a8;font-size:11px;font-weight:800;letter-spacing:.12em}.card h1{margin:8px 0;color:#102f4b;font-size:28px;line-height:1.2}.intro{margin:0 0 24px;color:#62788b}.notice{margin:0 0 20px;padding:12px 14px;border:1px solid #b9dbe7;border-radius:7px;background:#f1fafc;color:#255d77;font-size:13px}.error{margin:0 0 16px;padding:10px 12px;border-radius:6px;background:#fff3f2;color:#a6423c;font-size:13px}.field{display:grid;gap:6px;margin:0 0 16px}.field label{font-size:13px;font-weight:700}.field input{width:100%;height:46px;padding:0 12px;border:1px solid #ccd8e1;border-radius:6px;font:inherit;color:#173d5b}.field input:focus{outline:3px solid #55a9c633;border-color:#3985a7}.hint{margin:-4px 0 18px;color:#718598;font-size:12px}.button{width:100%;height:46px;border:0;border-radius:6px;background:#184b72;color:white;font-weight:700;font-size:14px;cursor:pointer}.button:hover{background:#103b5d}@media(max-width:520px){.card{padding:25px 20px}}
    </style>
</head>
<body>
    <main class="card">
        <p class="brand">INTERFREIGHT GROUP</p>
        <span class="eyebrow">ACCOUNT SECURITY</span>
        <h1>Set your new password</h1>
        <p class="intro">Your administrator issued a temporary password. Choose a new one to continue to InterFreight Central.</p>
        <p class="notice">Use at least 12 characters with uppercase and lowercase letters, a number, and a symbol. Do not reuse your temporary password.</p>
        @if ($errors->any())<div class="error" role="alert">{{ $errors->first() }}</div>@endif
        <form method="post" action="{{ route('password.first-login.update') }}">
            @csrf
            <div class="field"><label for="current_password">Temporary password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required autofocus></div>
            <div class="field"><label for="password">New password</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" required></div>
            <div class="field"><label for="password_confirmation">Confirm new password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="12" required></div>
            <button class="button" type="submit">Update password and continue</button>
        </form>
    </main>
</body>
</html>
