@props(['active' => false, 'href' => '#'])

@php
    // Active: filled brand-soft pill with brand text (a fill, not a side-bar, so the
    // current page is unmistakable in both themes).
    // Inactive: muted, brightens on hover.
    // The SVG inside the slot inherits color via `currentColor` so we don't
    // need a separate icon wrapper — color cascades from the link.
    $base = 'group flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-semibold transition-colors';
    $state = $active
        ? 'bg-brand-soft text-brand-text'
        : 'text-gray-600 hover:text-gray-900 hover:bg-gray-100';
@endphp

<a href="{{ $href }}" wire:navigate @if($active) aria-current="page" @endif {{ $attributes->class("$base $state") }}>
    {{ $slot }}
</a>
