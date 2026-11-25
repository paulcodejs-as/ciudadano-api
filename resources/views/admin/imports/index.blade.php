@extends('admin.layout')

@section('title', 'Importaciones')

@php
    $months = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'set', 'oct', 'nov', 'dic'];
    $ym = fn (?string $p) => $p ? $months[(int) substr($p, 5, 2) - 1].' '.substr($p, 0, 4) : '–';
@endphp

@section('content')
    <h1>Datos e importaciones</h1>

    <section class="stats" aria-label="Datos cargados">
        <div class="stat">
            <span class="stat-value">{{ number_format($summary['total_reports'], 0, '.', ' ') }}</span>
            <span class="stat-label">denuncias cargadas</span>
        </div>
        <div class="stat">
            <span class="stat-value">{{ $ym($summary['from']) }} – {{ $ym($summary['to']) }}</span>
            <span class="stat-label">periodo con datos</span>
        </div>
        <div class="stat">
            <span class="stat-value">{{ $summary['districts'] }}<small>/50</small></span>
            <span class="stat-label">distritos con polígono</span>
        </div>
        <div class="stat">
            <span class="stat-value">{{ $summary['districts_with_population'] }}<small>/{{ $summary['districts'] }}</small></span>
            <span class="stat-label">distritos con población</span>
        </div>
    </section>

    <section class="card">
        <h2>Subir CSV de Denuncias Policiales</h2>
        <p class="muted">
            Descárgalo a mano de <a href="https://www.datosabiertos.gob.pe/dataset/denuncias-policiales" target="_blank" rel="noopener">datosabiertos.gob.pe</a>
            (el histórico o un archivo por año). Se guardan solo Lima Metropolitana y Callao. Subir un mes que ya existe lo reemplaza: no se duplica.
        </p>

        <form method="POST" action="{{ route('admin.imports.store') }}" enctype="multipart/form-data" class="upload" id="upload-form"
              data-limit="{{ $uploadLimitBytes }}">
            @csrf
            <label class="dropzone">
                <input type="file" name="file" accept=".csv,text/csv" required id="file-input">
                <span class="dz-text" id="file-label">Elige o arrastra un archivo .csv</span>
                <span class="muted small">Límite actual: {{ $uploadLimitMb }} MB</span>
            </label>

            @error('file')
                <div class="alert alert-error" role="alert">{{ $message }}</div>
            @enderror
            <div class="alert alert-error" role="alert" id="size-error" hidden></div>

            <button type="submit" class="btn btn-primary" id="upload-btn">Subir e importar</button>
        </form>

        @if ($uploadLimitMb < 30)
            <p class="muted small hint-limit">
                El límite lo pone tu <code>php.ini</code>. Para subir el histórico completo (~30 MB) usa
                <code>upload_max_filesize = 64M</code> y <code>post_max_size = 64M</code> y reinicia el servidor,
                o impórtalo por consola: <code>php artisan ciudadano:import-sidpol ruta/al/archivo.csv</code>.
            </p>
        @endif
    </section>

    <section class="card">
        <h2>Historial</h2>
        @if ($imports->isEmpty())
            <p class="muted">Todavía no hay importaciones.</p>
        @else
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th scope="col">#</th>
                            <th scope="col">Archivo</th>
                            <th scope="col">Estado</th>
                            <th scope="col" class="num">Filas usadas</th>
                            <th scope="col" class="num">Leídas</th>
                            <th scope="col">Usuario</th>
                            <th scope="col">Fecha</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($imports as $import)
                            <tr>
                                <td><a href="{{ route('admin.imports.show', $import) }}">{{ $import->id }}</a></td>
                                <td class="file"><a href="{{ route('admin.imports.show', $import) }}">{{ $import->file_name }}</a></td>
                                <td>@include('admin.imports._status', ['status' => $import->status])</td>
                                <td class="num">{{ number_format($import->rows_imported, 0, '.', ' ') }}</td>
                                <td class="num">{{ number_format($import->rows_read, 0, '.', ' ') }}</td>
                                <td>{{ $import->user?->name ?? 'Consola' }}</td>
                                <td class="nowrap">{{ $import->created_at->format('d/m/Y H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            {{ $imports->links('admin.pagination') }}
        @endif
    </section>

    <script>
        (function () {
            const form = document.getElementById('upload-form');
            const input = document.getElementById('file-input');
            const label = document.getElementById('file-label');
            const error = document.getElementById('size-error');
            const btn = document.getElementById('upload-btn');
            const limit = Number(form.dataset.limit);

            input.addEventListener('change', () => {
                const file = input.files[0];
                error.hidden = true;
                btn.disabled = false;
                if (!file) { label.textContent = 'Elige o arrastra un archivo .csv'; return; }
                label.textContent = `${file.name} · ${(file.size / 1024 / 1024).toFixed(1)} MB`;
                if (file.size > limit) {
                    error.textContent = `El archivo pesa ${(file.size / 1024 / 1024).toFixed(1)} MB y el límite es ${(limit / 1024 / 1024).toFixed(1)} MB. Sube el límite en php.ini o impórtalo por consola.`;
                    error.hidden = false;
                    btn.disabled = true;
                }
            });

            form.addEventListener('submit', () => {
                btn.disabled = true;
                btn.textContent = 'Subiendo e importando…';
            });
        })();
    </script>
@endsection
