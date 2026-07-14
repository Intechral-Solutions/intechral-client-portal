@php
$styles = [
    'open'         => 'background-color:var(--surface-accent);color:var(--accent);',
    'in_progress'  => 'background-color:#eff6ff;color:#2563eb;',
    'pending_user' => 'background-color:#faf5ff;color:#7c3aed;',
    'resolved'     => 'background-color:#f0fdf4;color:#16a34a;',
    'closed'       => 'background-color:var(--surface-elevated);color:var(--text-secondary);',
];
$labels = [
    'open'         => 'Open',
    'in_progress'  => 'In Progress',
    'pending_user' => 'Pending',
    'resolved'     => 'Resolved',
    'closed'       => 'Closed',
];
$style = $styles[$status] ?? '';
$label = $labels[$status] ?? ucfirst($status);
@endphp
<span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
      style="{{ $style }}">{{ $label }}</span>
