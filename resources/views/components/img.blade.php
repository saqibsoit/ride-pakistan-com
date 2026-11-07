{{--
    Responsive image: AVIF/WebP <source>s from the variant sidecar, intrinsic
    width/height to prevent layout shift, lazy by default.

    <x-img src="hero.jpg" alt="..." sizes="100vw" priority class="..." />
    - src: path on the disk (default disk "site" = public/images)
    - alt: required; pass alt="" for decorative images
    - priority: eager + fetchpriority=high (hero / LCP image only)
    - eager: load immediately without raising priority (above-the-fold, not LCP)
--}}
@props([
    'src',
    'alt',
    'disk' => null,
    'sizes' => '100vw',
    'priority' => false,
    'eager' => false,
])

@php
    $image = \App\Support\ResponsiveImage::for($src, $disk);
@endphp

<picture class="contents">
    @foreach ($image->sources as $type => $srcset)
        <source type="{{ $type }}" srcset="{{ $srcset }}" sizes="{{ $sizes }}">
    @endforeach
    <img
        src="{{ $image->src }}"
        alt="{{ $alt }}"
        @if ($image->width && $image->height)
            width="{{ $image->width }}"
            height="{{ $image->height }}"
        @endif
        @if ($priority)
            loading="eager"
            fetchpriority="high"
        @elseif ($eager)
            loading="eager"
            decoding="async"
        @else
            loading="lazy"
            decoding="async"
        @endif
        {{ $attributes }}
    >
</picture>
