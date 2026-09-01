<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title') &mdash; VillonFarm POS</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #1a472a 0%, #2d6a4f 50%, #40916c 100%);
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
        }

        .card {
            background: #fff;
            border-radius: 1.25rem;
            box-shadow: 0 25px 60px rgba(0,0,0,.25);
            padding: 2.5rem 2.75rem;
            width: 100%;
            max-width: 440px;
            text-align: center;
        }

        .logo {
            margin-bottom: 1.5rem;
        }

        .logo svg {
            width: 52px;
            height: 52px;
        }

        .logo h1 {
            margin-top: .6rem;
            font-size: 1.4rem;
            font-weight: 700;
            color: #1a472a;
            letter-spacing: -.3px;
        }

        .code {
            font-size: 3rem;
            font-weight: 800;
            color: #1a472a;
            letter-spacing: -.5px;
        }

        .message {
            font-size: 1.15rem;
            font-weight: 700;
            color: #111827;
            margin-top: .35rem;
        }

        .hint {
            font-size: .9rem;
            color: #6b7280;
            margin-top: .6rem;
            line-height: 1.5;
        }

        .btn {
            display: inline-block;
            margin-top: 1.75rem;
            padding: .7rem 1.5rem;
            background: linear-gradient(135deg, #1a472a, #2d6a4f);
            color: #fff;
            border: none;
            border-radius: .6rem;
            font-size: .95rem;
            font-weight: 600;
            text-decoration: none;
            transition: opacity .2s, transform .1s;
        }

        .btn:hover { opacity: .9; }
        .btn:active { transform: scale(.98); }

        .footer-note {
            margin-top: 1.75rem;
            font-size: .8rem;
            color: #9ca3af;
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">
            <svg viewBox="0 0 52 52" fill="none" xmlns="http://www.w3.org/2000/svg">
                <circle cx="26" cy="26" r="26" fill="#1a472a"/>
                <path d="M26 38 C26 38 14 30 14 20 C14 14 20 10 26 10 C32 10 38 14 38 20 C38 30 26 38 26 38Z" fill="#52b788"/>
                <line x1="26" y1="38" x2="26" y2="22" stroke="#fff" stroke-width="1.5" stroke-linecap="round"/>
            </svg>
            <h1>VillonFarm POS</h1>
        </div>

        <p class="code">@yield('code')</p>
        <h2 class="message">@yield('message')</h2>
        <p class="hint">@yield('hint', "Something went wrong on our end. Please try again later.")</p>

        <a class="btn" href="{{ url('/') }}">Go home</a>

        <p class="footer-note">&copy; {{ date('Y') }} VillonFarm &mdash; Point of Sale</p>
    </div>
</body>
</html>