@props(['active' => false, 'href' => '#'])

@php
    // Active: highlighted background + emerald accent.
    // Inactive: muted gray, brightens on hover.
    // The SVG inside the slot inherits color via `currentColor` so we don't
    // need a separate icon wrapper — color cascades from the link.
    $base = 'group flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium transition-colors';
    $state = $active
        ? 'bg-emerald-50 text-emerald-700'
        : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50';
@endphp

<a href="{{ $href }}" {{ $attributes->class("$base $state") }}>
    {{ $slot }}
</a>
