@php
    $statusInfo = [
        'pending' => ['En cola', 'pending', '◷'],
        'running' => ['Procesando', 'running', '◔'],
        'done' => ['Completada', 'done', '✓'],
        'failed' => ['Fallida', 'failed', '✕'],
    ][$status] ?? [$status, 'pending', '•'];
@endphp
<span class="badge badge-{{ $statusInfo[1] }}"><span aria-hidden="true">{{ $statusInfo[2] }}</span> {{ $statusInfo[0] }}</span>
