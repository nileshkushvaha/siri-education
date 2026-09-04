@php
    $appName = config('app.name', 'Application');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Error') - {{ $appName }}</title>
    <meta name="description" content="@yield('meta_description', 'The request could not be completed.')">
    <style>
        :root {
            color-scheme: dark;
            --bg: #070b14;
            --panel: rgba(255, 255, 255, 0.045);
            --panel-border: rgba(255, 255, 255, 0.09);
            --text: #f8fafc;
            --muted: #cbd5e1;
            --muted-soft: #94a3b8;
            --brand: #6366f1;
            --brand-strong: #4f46e5;
            --ring: rgba(165, 180, 252, 0.45);
        }

        * {
            box-sizing: border-box;
        }

        html,
        body {
            min-height: 100%;
        }

        body {
            margin: 0;
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background:
                radial-gradient(circle at top, rgba(99, 102, 241, 0.18), transparent 34rem),
                linear-gradient(135deg, #06080f 0%, #111827 52%, #08111f 100%);
            color: var(--text);
        }

        .shell {
            min-height: 100vh;
            display: grid;
            grid-template-rows: auto 1fr auto;
        }

        .brand {
            width: min(100%, 72rem);
            margin: 0 auto;
            padding: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }

        .brand svg {
            display: block;
            height: 2.75rem;
            width: auto;
        }

        main {
            display: grid;
            place-items: center;
            padding: 3rem 1.25rem;
        }

        .panel {
            width: min(100%, 42rem);
            text-align: center;
            padding: clamp(2rem, 7vw, 4.5rem);
            border: 1px solid var(--panel-border);
            border-radius: 1.25rem;
            background: var(--panel);
            box-shadow: 0 1.5rem 5rem rgba(0, 0, 0, 0.26);
            backdrop-filter: blur(18px);
        }

        .code {
            margin: 0;
            font-size: clamp(5rem, 18vw, 10rem);
            line-height: 0.88;
            font-weight: 900;
            letter-spacing: 0;
            color: #a5b4fc;
        }

        h1 {
            margin: 1.5rem 0 0;
            font-size: clamp(1.75rem, 5vw, 3rem);
            line-height: 1.08;
            font-weight: 800;
            letter-spacing: 0;
        }

        .message {
            max-width: 30rem;
            margin: 1rem auto 0;
            color: var(--muted);
            font-size: 1rem;
            line-height: 1.7;
        }

        .error-actions {
            margin-top: 2rem;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
        }

        .error-actions a,
        .error-actions button {
            min-height: 2.75rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 1px solid rgba(255, 255, 255, 0.11);
            border-radius: 0.65rem;
            padding: 0.7rem 1.15rem;
            background: rgba(255, 255, 255, 0.06);
            color: var(--text);
            font: inherit;
            font-size: 0.95rem;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
            transition: background-color 160ms ease, border-color 160ms ease, transform 160ms ease;
        }

        .error-actions a:first-child,
        .error-actions button:first-child {
            border-color: transparent;
            background: var(--brand);
        }

        .error-actions a:hover,
        .error-actions button:hover {
            transform: translateY(-1px);
            border-color: rgba(255, 255, 255, 0.18);
            background: rgba(255, 255, 255, 0.1);
        }

        .error-actions a:first-child:hover,
        .error-actions button:first-child:hover {
            background: var(--brand-strong);
        }

        .error-actions a:focus-visible,
        .error-actions button:focus-visible {
            outline: 3px solid var(--ring);
            outline-offset: 3px;
        }

        footer {
            padding: 1.25rem;
            text-align: center;
            color: var(--muted-soft);
            font-size: 0.85rem;
        }
    </style>
    @include('partials.seo.tracking-head')
</head>
<body>
    @include('partials.seo.tracking-body')
    <div class="shell">
        <header class="brand" aria-label="{{ $appName }}">
            <x-ui.brand-logo variant="dark" :label="$appName" />
        </header>

        <main>
            <section class="panel" aria-labelledby="error-title">
                <p class="code">@yield('code', '')</p>
                <h1 id="error-title">@yield('error-title', 'Something went wrong')</h1>
                <p class="message">@yield('error-message', 'Please try again, or head back to the homepage.')</p>

                <div class="error-actions">
                    @hasSection('error-actions')
                        @yield('error-actions')
                    @else
                        <a href="{{ url('/') }}">Go Home</a>
                    @endif
                </div>
            </section>
        </main>

        <footer>
            {{ $appName }}
        </footer>
    </div>
</body>
</html>
