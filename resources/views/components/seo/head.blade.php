{{-- SEO head tags from App\Support\Seo\SeoData. Rendered by the layout. --}}
@props(['seo'])

<title>{{ $seo->title }}</title>
<meta name="description" content="{{ $seo->description }}">
<meta name="robots" content="{{ $seo->robots }}">
<link rel="canonical" href="{{ $seo->canonical }}">

<meta property="og:site_name" content="{{ app(\App\Support\Settings::class)->string(\App\Enums\SettingKey::BusinessName) }}">
<meta property="og:type" content="{{ $seo->ogType }}">
<meta property="og:title" content="{{ $seo->title }}">
<meta property="og:description" content="{{ $seo->description }}">
<meta property="og:url" content="{{ $seo->canonical }}">
<meta property="og:image" content="{{ $seo->image }}">
<meta property="og:image:alt" content="{{ $seo->imageAlt }}">
<meta property="og:locale" content="{{ app()->getLocale() === 'ur' ? 'ur_PK' : 'en_PK' }}">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $seo->title }}">
<meta name="twitter:description" content="{{ $seo->description }}">
<meta name="twitter:image" content="{{ $seo->image }}">
<meta name="twitter:image:alt" content="{{ $seo->imageAlt }}">

@if ($seo->schema !== [])
    <script type="application/ld+json" @nonce>{!! $seo->jsonLd() !!}</script>
@endif
