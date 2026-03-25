@php
$styles = [
    'critical' => 'background-color:#fef2f2;color:#dc2626;',
    'high'     => 'background-color:#fff7ed;color:#ea580c;',
    'medium'   => 'background-color:#fefce8;color:#ca8a04;',
    'low'      => 'background-color:#f0fdf4;color:#16a34a;',
];
$style = $styles[$priority] ?? '';
@endphp
<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize"
      style="{{ $style }}">{{ $priority }}</span>
