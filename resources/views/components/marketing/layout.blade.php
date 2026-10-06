@props([
    'title' => null,
    'description' => null,
])
@php
    $product = config('marketing.product_name');
    $pageTitle = $title ?? "{$product} | Susu, Savings & Loan Management Software for Ghana";
    $pageDescription = $description ?? "{$product} helps susu collectors, microfinance institutions and savings & loans companies run daily collections, savings, loans and accounting — on the web, on agents' phones and offline on Windows.";
    $family = app(\App\Services\OguaFamily::class);
    $company = $family->company();
    $gaId = config('marketing.ga_measurement_id');
@endphp
<!DOCTYPE html>
<html lang="en-GH">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#1C1712">

    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $pageDescription }}">
    <link rel="canonical" href="{{ url()->current() }}">

    <meta property="og:type" content="website">
    <meta property="og:site_name" content="{{ $product }}">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:image" content="{{ asset('images/marketing/og-image.png') }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="icon" type="image/svg+xml" href="{{ asset('images/marketing/favicon.svg') }}">
    <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('images/marketing/icon-192.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/marketing/icon-192.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600;9..144,700&family=JetBrains+Mono:wght@500&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">

    <script type="application/ld+json">
    {!! json_encode([
        '@context' => 'https://schema.org',
        '@graph' => [
            [
                '@type' => 'SoftwareApplication',
                'name' => $product,
                'applicationCategory' => 'FinanceApplication',
                'operatingSystem' => 'Web, Android, Windows',
                'description' => $pageDescription,
                'url' => route('marketing.home'),
                'publisher' => ['@id' => $company['url'].'#organization'],
            ],
            [
                '@type' => 'Organization',
                '@id' => $company['url'].'#organization',
                'name' => $company['name'],
                'url' => $company['url'],
                'brand' => array_map(fn (array $familyProduct) => [
                    '@type' => 'Brand',
                    'name' => $familyProduct['name'],
                    'url' => $familyProduct['href'],
                ], $family->products(includeCurrent: true)),
            ],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}
    </script>

    @if ($gaId)
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ $gaId }}"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag(){dataLayer.push(arguments);}
            gtag('js', new Date());
            gtag('config', @js($gaId));
        </script>
    @endif

    @vite(['resources/css/marketing.css', 'resources/js/marketing.js'])
</head>
<body class="font-sans text-fin-ink antialiased">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-[100] focus:rounded-full focus:bg-fin-ink focus:px-5 focus:py-2.5 focus:text-sm focus:font-bold focus:text-fin-paper">
        Skip to content
    </a>

    @include('marketing.partials.family-bar')
    @include('marketing.partials.header')

    <main id="main-content">
        {{ $slot }}
    </main>

    @include('marketing.partials.footer')
</body>
</html>
