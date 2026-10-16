<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        {{-- Pages are rendered client-side (no Inertia SSR: 20i has no Node), so
             this is the title/description that no-JS visitors, link previews
             and the first paint see — hence fixed, not APP_NAME. --}}
        <title inertia>offing.uk — what's in the offing</title>
        <meta name="description" content="A shared calendar for the people in your life.">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        @routes
        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        <noscript>
            <p class="bg-background text-foreground p-6 text-center">
                offing.uk needs JavaScript to run. Please turn it on and reload the page.
            </p>
        </noscript>
        @inertia
    </body>
</html>
