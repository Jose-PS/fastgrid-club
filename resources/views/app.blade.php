<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

        <script nonce="{{ Vite::cspNonce() }}">
            (function () {
                var theme = null;

                try {
                    theme = localStorage.getItem('sg-theme');
                } catch (error) {
                    theme = null;
                }

                if (theme !== 'light' && theme !== 'dark') {
                    theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches ? 'light' : 'dark';
                }

                document.documentElement.setAttribute('data-theme', theme);
            })();
        </script>

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @php
            $pageMeta = app(\App\Support\PageMeta::class);
            $meta = $pageMeta->forRoute($pageMeta->currentRouteName());
        @endphp
        @if ($meta !== null)
            <meta name="description" content="{{ $meta['description'] }}">
            <link rel="canonical" href="{{ $meta['canonical'] }}">
            @foreach ($meta['alternates'] as $hreflang => $href)
                <link rel="alternate" hreflang="{{ $hreflang }}" href="{{ $href }}">
            @endforeach
            <meta property="og:title" content="{{ $meta['title'] }}">
            <meta property="og:description" content="{{ $meta['ogDescription'] }}">
            <meta property="og:type" content="website">
            <meta property="og:url" content="{{ $meta['canonical'] }}">
            <meta property="og:site_name" content="{{ $meta['siteName'] }}">
            <meta property="og:locale" content="{{ $meta['locale'] }}">
            <meta property="og:image" content="{{ $meta['image'] }}">
            <meta name="twitter:card" content="summary_large_image">
            <meta name="twitter:title" content="{{ $meta['title'] }}">
            <meta name="twitter:description" content="{{ $meta['ogDescription'] }}">
            <meta name="twitter:image" content="{{ $meta['image'] }}">
            @if ($meta['twitterSite'] !== null)
                <meta name="twitter:site" content="{{ $meta['twitterSite'] }}">
            @endif
            @if ($meta['jsonLd'] !== null)
                <script type="application/ld+json" nonce="{{ Vite::cspNonce() }}">{!! json_encode($meta['jsonLd'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!}</script>
            @endif
        @endif
        <x-inertia::head>
            <title>{{ $meta['title'] ?? config('app.name', 'Laravel') }}</title>
        </x-inertia::head>
    </head>
    <body class="font-sans antialiased">
        <noscript>
            <p role="alert" class="p-3 text-center text-sm">{{ __('noscript.message') }}</p>
        </noscript>
        <x-inertia::app />
    </body>
</html>
