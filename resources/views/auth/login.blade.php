<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login &mdash; VillonFarm POS</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #f9fafb 0%, #e5e7eb 100%);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        .card {
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 1.25rem;
            box-shadow: 0 25px 60px rgba(0,0,0,.12);
            padding: 2.5rem 2.75rem;
            width: 100%;
            max-width: 420px;
        }

        .logo {
            text-align: center;
            margin-bottom: 1.75rem;
        }

        .logo svg {
            width: 52px;
            height: 52px;
        }

        .logo h1 {
            margin-top: .6rem;
            font-size: 1.4rem;
            font-weight: 700;
            color: #111827;
            letter-spacing: -.3px;
        }

        .logo p {
            font-size: .82rem;
            color: #6b7280;
            margin-top: .2rem;
        }

        .alert {
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #dc2626;
            border-radius: .6rem;
            padding: .75rem 1rem;
            font-size: .87rem;
            margin-bottom: 1.25rem;
        }

        .form-group {
            margin-bottom: 1.1rem;
        }

        label {
            display: block;
            font-size: .85rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: .4rem;
        }

        input[type="email"],
        input[type="password"],
        input[type="text"] {
            width: 100%;
            padding: .65rem .9rem;
            border: 1.5px solid #d1d5db;
            border-radius: .6rem;
            font-size: .95rem;
            color: #111827;
            transition: border-color .2s, box-shadow .2s;
            outline: none;
        }

        input:focus {
            border-color: #111827;
            box-shadow: 0 0 0 3px rgba(17,24,39,.12);
        }

        input.is-invalid {
            border-color: #dc2626;
        }

        .error-text {
            color: #dc2626;
            font-size: .8rem;
            margin-top: .3rem;
        }

        .remember-row {
            display: flex;
            align-items: center;
            gap: .5rem;
            margin-bottom: 1.4rem;
        }

        .remember-row input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: #111827;
            cursor: pointer;
        }

        .remember-row label {
            margin: 0;
            font-weight: 400;
            color: #6b7280;
            cursor: pointer;
        }

        .btn {
            width: 100%;
            padding: .75rem;
            background: linear-gradient(135deg, #111827, #374151);
            color: #fff;
            border: none;
            border-radius: .6rem;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: opacity .2s, transform .1s;
        }

        .btn:hover { opacity: .9; }
        .btn:active { transform: scale(.98); }

        .footer-note {
            text-align: center;
            margin-top: 1.5rem;
            font-size: .8rem;
            color: #9ca3af;
        }

        .password-wrapper {
            position: relative;
        }

        .password-wrapper input {
            padding-right: 2.75rem;
        }

        .toggle-password {
            position: absolute;
            right: .65rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            padding: 0;
            color: #6b7280;
            display: flex;
            align-items: center;
            transition: color .2s;
        }

        .toggle-password:hover {
            color: #111827;
        }

        .toggle-password svg {
            width: 20px;
            height: 20px;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">
            <!-- Simple leaf icon -->
            <svg viewBox="0 0 52 52" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="26" cy="26" r="26" fill="#111827"/>
                <path d="M26 38 C26 38 14 30 14 20 C14 14 20 10 26 10 C32 10 38 14 38 20 C38 30 26 38 26 38Z" fill="#9ca3af"/>
                <line x1="26" y1="38" x2="26" y2="22" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
            <h1>VillonFarm POS</h1>
            <p>Sign in to your account</p>
        </div>

        @if($errors->any())
            <div class="alert">
                {{ $errors->first() }}
            </div>
        @endif

        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div class="form-group">
                <label for="email">Email address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="{{ old('email') }}"
                    autocomplete="email"
                    autofocus
                    class="{{ $errors->has('email') ? 'is-invalid' : '' }}"
                    placeholder="you@example.com"
                >
                @error('email')
                    <p class="error-text">{{ $message }}</p>
                @enderror
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <div class="password-wrapper">
                    <input
                        type="password"
                        id="password"
                        name="password"
                        autocomplete="current-password"
                        class="{{ $errors->has('password') ? 'is-invalid' : '' }}"
                        placeholder="••••••••"
                    >
                    <button type="button" class="toggle-password" onclick="togglePassword()" aria-label="Toggle password visibility">
                        <svg id="eye-open" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" />
                        </svg>
                        <svg id="eye-closed" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" style="display:none;">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 0-4.243-4.243m4.242 4.242L9.88 9.88" />
                        </svg>
                    </button>
                </div>
                @error('password')
                    <p class="error-text">{{ $message }}</p>
                @enderror
            </div>

            <div class="remember-row">
                <input type="checkbox" id="remember" name="remember">
                <label for="remember">Remember me</label>
            </div>

            <button type="submit" class="btn">Sign in</button>
        </form>

        <p class="footer-note">&copy; {{ date('Y') }} VillonFarm &mdash; Point of Sale</p>
    </div>

    <script>
        function togglePassword() {
            const input = document.getElementById('password');
            const eyeOpen = document.getElementById('eye-open');
            const eyeClosed = document.getElementById('eye-closed');

            if (input.type === 'password') {
                input.type = 'text';
                eyeOpen.style.display = 'none';
                eyeClosed.style.display = 'block';
            } else {
                input.type = 'password';
                eyeOpen.style.display = 'block';
                eyeClosed.style.display = 'none';
            }
        }
    </script>
</body>
</html>
