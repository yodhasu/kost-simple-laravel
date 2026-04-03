<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"  @class(['dark' => ($appearance ?? 'system') == 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        {{-- Inline script to detect system dark mode preference and apply it immediately --}}
        <script>
            (function() {
                const appearance = '{{ $appearance ?? "system" }}';

                if (appearance === 'system') {
                    const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                    if (prefersDark) {
                        document.documentElement.classList.add('dark');
                    }
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color based on our theme in app.css --}}
        <style>
            html {
                background-color: oklch(1 0 0);
            }

            html.dark {
                background-color: oklch(0.145 0 0);
            }

            body {
                margin: 0;
                min-height: 100vh;
                background: #f8fafc;
            }

            html.dark body {
                background: #f8fafc;
            }

            [data-app-shell] {
                position: fixed;
                inset: 0;
                z-index: 9999;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 24px;
                transition: opacity 180ms ease, visibility 180ms ease;
                background: rgba(248, 250, 252, 0.98);
            }

            html.dark [data-app-shell] {
                background: rgba(248, 250, 252, 0.98);
            }

            [data-app-shell].is-hidden {
                opacity: 0;
                visibility: hidden;
                pointer-events: none;
            }

            [data-app-shell-card] {
                width: min(100%, 1080px);
                border-radius: 28px;
                padding: 32px;
                background: rgba(255, 255, 255, 0.96);
                border: 1px solid rgba(226, 232, 240, 0.85);
                box-shadow: 0 18px 60px rgba(148, 163, 184, 0.12);
                backdrop-filter: blur(12px);
            }

            html.dark [data-app-shell-card] {
                background: rgba(255, 255, 255, 0.96);
                border: 1px solid rgba(226, 232, 240, 0.85);
                box-shadow: 0 18px 60px rgba(148, 163, 184, 0.12);
            }

            [data-shell-bar],
            [data-shell-pill],
            [data-shell-line],
            [data-shell-card-line] {
                position: relative;
                overflow: hidden;
                background: rgba(226, 232, 240, 0.95);
            }

            html.dark [data-shell-bar],
            html.dark [data-shell-pill],
            html.dark [data-shell-line],
            html.dark [data-shell-card-line] {
                background: rgba(226, 232, 240, 0.95);
            }

            [data-shell-bar]::after,
            [data-shell-pill]::after,
            [data-shell-line]::after,
            [data-shell-card-line]::after {
                content: '';
                position: absolute;
                inset: 0;
                transform: translateX(-100%);
                background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.92), transparent);
                animation: app-shell-shimmer 1.25s infinite;
            }

            html.dark [data-shell-bar]::after,
            html.dark [data-shell-pill]::after,
            html.dark [data-shell-line]::after,
            html.dark [data-shell-card-line]::after {
                background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.92), transparent);
            }

            [data-shell-bar] {
                height: 22px;
                width: 220px;
                border-radius: 999px;
            }

            [data-shell-pill] {
                height: 42px;
                width: 144px;
                border-radius: 999px;
            }

            [data-shell-line] {
                height: 16px;
                border-radius: 999px;
            }

            [data-shell-card-line] {
                height: 14px;
                border-radius: 999px;
            }

            @keyframes app-shell-shimmer {
                100% {
                    transform: translateX(100%);
                }
            }
        </style>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.ts', "resources/js/pages/{$page['component']}.vue"])
        <x-inertia::head>
            <title>{{ config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <div data-app-shell>
            <div data-app-shell-card>
                <div style="display:flex;align-items:center;justify-content:space-between;gap:24px;flex-wrap:wrap;">
                    <div style="display:grid;gap:12px;min-width:260px;flex:1 1 420px;">
                        <div data-shell-bar></div>
                        <div data-shell-line style="width:78%;"></div>
                        <div data-shell-line style="width:56%;"></div>
                    </div>
                    <div data-shell-pill></div>
                </div>

                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:18px;margin-top:28px;">
                    <div style="display:grid;gap:12px;padding:20px;border-radius:22px;background:#ffffff;border:1px solid rgba(226,232,240,0.85);">
                        <div data-shell-card-line style="width:44%;"></div>
                        <div data-shell-line style="width:72%;"></div>
                    </div>
                    <div style="display:grid;gap:12px;padding:20px;border-radius:22px;background:#ffffff;border:1px solid rgba(226,232,240,0.85);">
                        <div data-shell-card-line style="width:38%;"></div>
                        <div data-shell-line style="width:68%;"></div>
                    </div>
                    <div style="display:grid;gap:12px;padding:20px;border-radius:22px;background:#ffffff;border:1px solid rgba(226,232,240,0.85);">
                        <div data-shell-card-line style="width:41%;"></div>
                        <div data-shell-line style="width:70%;"></div>
                    </div>
                </div>

                <div style="display:grid;gap:14px;margin-top:28px;">
                    <div data-shell-line style="width:22%;height:18px;"></div>
                    <div style="display:grid;gap:12px;padding:24px;border-radius:24px;background:#ffffff;border:1px solid rgba(226,232,240,0.85);">
                        <div data-shell-card-line style="width:92%;"></div>
                        <div data-shell-card-line style="width:88%;"></div>
                        <div data-shell-card-line style="width:94%;"></div>
                        <div data-shell-card-line style="width:85%;"></div>
                        <div data-shell-card-line style="width:90%;"></div>
                    </div>
                </div>
            </div>
        </div>
        <x-inertia::app />
    </body>
</html>
