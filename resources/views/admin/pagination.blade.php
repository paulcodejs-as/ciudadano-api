@if ($paginator->hasPages())
    <nav class="pager" aria-label="Paginación">
        @if ($paginator->onFirstPage())
            <span class="muted">← Anteriores</span>
        @else
            <a href="{{ $paginator->previousPageUrl() }}" rel="prev">← Anteriores</a>
        @endif
        <span class="muted">Página {{ $paginator->currentPage() }} de {{ $paginator->lastPage() }}</span>
        @if ($paginator->hasMorePages())
            <a href="{{ $paginator->nextPageUrl() }}" rel="next">Siguientes →</a>
        @else
            <span class="muted">Siguientes →</span>
        @endif
    </nav>
@endif
