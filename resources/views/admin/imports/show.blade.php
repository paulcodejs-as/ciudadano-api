@extends('admin.layout')

@section('title', "Importación #{$import->id}")

@php($inProgress = in_array($import->status, ['pending', 'running'], true))

@if ($inProgress)
    @push('head')
        <meta http-equiv="refresh" content="3">
    @endpush
@endif

@section('content')
    <p><a href="{{ route('admin.imports.index') }}">← Importaciones</a></p>

    <section class="card">
        <div class="card-head">
            <div>
                <h1>Importación #{{ $import->id }}</h1>
                <p class="muted file">{{ $import->file_name }}</p>
            </div>
            @include('admin.imports._status', ['status' => $import->status])
        </div>

        @if ($inProgress)
            <p class="muted">Procesando… esta página se actualiza sola cada 3 segundos.</p>
        @endif

        <dl class="facts">
            <div><dt>Filas leídas</dt><dd>{{ number_format($import->rows_read, 0, '.', ' ') }}</dd></div>
            <div><dt>Filas usadas</dt><dd>{{ number_format($import->rows_imported, 0, '.', ' ') }}</dd></div>
            <div><dt>Omitidas</dt><dd>{{ number_format($import->rows_skipped, 0, '.', ' ') }}</dd></div>
            <div><dt>Usuario</dt><dd>{{ $import->user?->name ?? 'Consola' }}</dd></div>
            <div><dt>Inicio</dt><dd>{{ $import->started_at?->format('d/m/Y H:i:s') ?? '–' }}</dd></div>
            <div><dt>Duración</dt><dd>{{ $import->started_at && $import->finished_at ? $import->started_at->diffInSeconds($import->finished_at).' s' : '–' }}</dd></div>
        </dl>

        @if ($import->status === 'done')
            <p class="muted small">Las filas omitidas suelen ser del resto del país: solo se guardan Lima Metropolitana y Callao. El mapa ya muestra los datos nuevos.</p>
        @endif

        <h2>Registro</h2>
        <pre class="log">{{ $import->log ?: 'Sin registro todavía.' }}</pre>
    </section>
@endsection
