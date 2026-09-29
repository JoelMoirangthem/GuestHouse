@props(['name', 'class' => 'h-[1.0625rem] w-[1.0625rem] shrink-0'])

{{--
  Inline SVG icons. Kept inline rather than pulled from an icon font or CDN so
  there is no extra network request and no flash of unstyled icons — and so the
  system works on an air-gapped government network.
  aria-hidden because every icon in this app sits beside a visible text label.
--}}

@php
    $paths = [
        'grid' => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'building' => '<path d="M3 21h18M5 21V5a2 2 0 0 1 2-2h7a2 2 0 0 1 2 2v16M9 7h2M9 11h2M9 15h2M16 21V11h3a1 1 0 0 1 1 1v9"/>',
        'inbox' => '<path d="M4 13h4l2 3h4l2-3h4M4 13V6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v7M4 13v5a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-5"/>',
        'check-badge' => '<path d="M9 12.5l2 2 4.5-4.5"/><path d="M12 3l2.2 1.6 2.7-.3 1.1 2.5 2.3 1.4-.6 2.7.6 2.7-2.3 1.4-1.1 2.5-2.7-.3L12 21l-2.2-1.6-2.7.3-1.1-2.5L3.7 15.8l.6-2.7-.6-2.7 2.3-1.4 1.1-2.5 2.7.3L12 3Z"/>',
        'document' => '<path d="M14 3v5h5M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8l-5-5Z"/><path d="M9 13h6M9 17h4"/>',
        'logout' => '<path d="M15 17l5-5-5-5M20 12H9M12 20H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h6"/>',
        'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'lock' => '<rect x="4.5" y="10.5" width="15" height="10" rx="2"/><path d="M8 10.5V7a4 4 0 0 1 8 0v3.5"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'academic' => '<path d="M3 9.5 12 5l9 4.5-9 4.5-9-4.5Z"/><path d="M7 11.7v4.1c0 .6 2.2 2.2 5 2.2s5-1.6 5-2.2v-4.1"/><path d="M21 9.5v5"/>',
        'briefcase' => '<rect x="3" y="7.5" width="18" height="12" rx="2"/><path d="M8.5 7.5V6a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v1.5"/><path d="M3 12.5h18"/>',
        'users' => '<circle cx="9" cy="8.5" r="3"/><path d="M3.5 19.5a5.5 5.5 0 0 1 11 0"/><path d="M16 6.2a3 3 0 0 1 0 5.6"/><path d="M17.5 14.5a5.5 5.5 0 0 1 3 4.5"/>',
        'key' => '<circle cx="8" cy="15" r="3.5"/><path d="M10.5 12.5 19 4"/><path d="M16 4h3v3"/>',
    ];
@endphp

<svg {{ $attributes->merge(['class' => $class]) }}
     viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"
     aria-hidden="true">
    {!! $paths[$name] ?? $paths['document'] !!}
</svg>
