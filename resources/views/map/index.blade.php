<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ciudadano · Denuncias registradas en el Perú</title>
    <meta name="description" content="Mapa de denuncias policiales registradas en el Perú por departamento, provincia y distrito: tasa por 10 000 habitantes, cambio anual, ranking, comparación y contexto de cada distrito.">
    <meta name="theme-color" content="#fcfcfb">
    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('img/favicon-32.png') }}">
    <link rel="apple-touch-icon" href="{{ asset('img/apple-touch-icon.png') }}">
    @vite(['resources/css/map.css', 'resources/js/map.js'])
</head>
<body>
    <header class="topbar">
        <div class="brand">
            <img class="brand-mark" src="{{ asset('img/ciudadano-mark.png') }}" alt="" width="40" height="40">
            <div>
                <h1>Ciudadano</h1>
                <p>Denuncias registradas por departamento, provincia y distrito · Perú</p>
            </div>
        </div>
    </header>

    <form class="toolbar" id="filters" aria-label="Filtros del mapa" onsubmit="return false">
        <fieldset class="filter-group" aria-label="Ubicación">
            <legend class="group-title">Ubicación</legend>
            <label class="field">
                <span>Departamento</span>
                <select id="f-department">
                    <option value="">Todo el Perú</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->code }}">{{ \Illuminate\Support\Str::title(mb_strtolower($department->name)) }}</option>
                    @endforeach
                </select>
            </label>
            <label class="field">
                <span>Provincia</span>
                <select id="f-province" disabled>
                    <option value="">Todas las provincias</option>
                </select>
            </label>
            <label class="field">
                <span>Distrito</span>
                <select id="f-district" disabled>
                    <option value="">Todos los distritos</option>
                </select>
            </label>
        </fieldset>

        <fieldset class="filter-group" aria-label="Datos">
            <legend class="group-title">Datos</legend>
            <label class="field">
                <span>Periodo</span>
                <select name="period" id="f-period">
                    <option value="">Últimos 12 meses</option>
                    @foreach ($years as $year)
                        <option value="{{ $year['value'] }}">{{ $year['label'] }}</option>
                    @endforeach
                </select>
            </label>

            <label class="field">
                <span>Modalidad</span>
                <select name="crime_type" id="f-crime-type">
                    <option value="">Todas las modalidades</option>
                    @foreach ($crimeTypes as $type)
                        <option value="{{ $type->slug }}">{{ $type->name }}</option>
                    @endforeach
                </select>
            </label>

            <fieldset class="field segmented" id="f-metric">
                <legend>Métrica</legend>
                <div class="seg-options">
                    <label><input type="radio" name="metric" value="rate" checked><span>Tasa por 10 000 hab.</span></label>
                    <label><input type="radio" name="metric" value="total"><span>Total</span></label>
                    <label><input type="radio" name="metric" value="change"><span>Cambio anual</span></label>
                </div>
            </fieldset>
        </fieldset>

        <div class="toolbar-actions">
            <button type="button" class="btn" id="locate-btn">
                <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><circle cx="12" cy="12" r="3.5" fill="currentColor"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="12" r="7" fill="none" stroke="currentColor" stroke-width="2"/></svg>
                Mi ubicación
            </button>
            <button type="button" class="btn btn-ghost" id="reset-btn">Limpiar</button>
        </div>
    </form>

    <div class="crumbbar">
        <nav class="breadcrumb" id="breadcrumb" aria-label="Ubicación actual"></nav>
        <p class="period-label" id="period-label" aria-live="polite"></p>
    </div>

    <main class="layout">
        <section class="map-wrap" aria-label="Mapa">
            <div id="map" role="application" aria-label="Mapa del Perú coloreado por denuncias registradas"></div>

            <div class="view-switch" role="radiogroup" aria-label="Tipo de mapa">
                <button type="button" role="radio" data-view="poly" aria-checked="true" title="Colorea cada zona por la métrica elegida">
                    <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M3 6l6-3 6 3 6-3v15l-6 3-6-3-6 3z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M9 3v15M15 6v15" stroke="currentColor" stroke-width="2"/></svg>
                    Zonas
                </button>
                <button type="button" role="radio" data-view="heat" aria-checked="false" title="Intensidad aproximada a partir del centro de cada distrito">
                    <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="currentColor" opacity=".25"/><circle cx="12" cy="12" r="5.5" fill="currentColor" opacity=".5"/><circle cx="12" cy="12" r="2.5" fill="currentColor"/></svg>
                    Calor
                </button>
                <button type="button" role="radio" data-view="bubbles" aria-checked="false" title="Burbuja proporcional al total de denuncias">
                    <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><circle cx="8" cy="14" r="5" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="17" cy="8" r="3" fill="none" stroke="currentColor" stroke-width="2"/><circle cx="18" cy="17" r="2" fill="currentColor"/></svg>
                    Burbujas
                </button>
            </div>

            <div class="legend" id="legend" aria-live="polite"></div>

            <div class="timeline" id="timeline">
                <button type="button" class="tl-play" id="tl-play" aria-label="Reproducir mes a mes">
                    <svg class="i-play" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M7 4l13 8-13 8z" fill="currentColor"/></svg>
                    <svg class="i-pause" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M6 4h4v16H6zM14 4h4v16h-4z" fill="currentColor"/></svg>
                </button>
                <div class="tl-track">
                    <input type="range" id="tl-range" min="0" max="11" value="11" step="1" aria-label="Mes que muestra el mapa">
                    <div class="tl-labels"><span id="tl-start"></span><strong id="tl-current">Todo el periodo</strong><span id="tl-end"></span></div>
                </div>
                <button type="button" class="tl-all" id="tl-all" aria-pressed="true">Todo el periodo</button>
            </div>
            <div class="map-status" id="map-status" hidden></div>
        </section>

        <aside class="panel" id="panel">
            @unless ($hasData)
                <div class="notice notice-warn">Todavía no hay denuncias importadas. Corre <code>php artisan ciudadano:import-sidpol</code>.</div>
            @endunless

            <section class="card" id="detail" aria-live="polite">
                <p class="hint">Cargando…</p>
            </section>

            <section class="card">
                <div class="card-head">
                    <h2 id="ranking-title">Ranking</h2>
                    <span class="muted" id="ranking-caption"></span>
                </div>
                <ol class="ranking" id="ranking"></ol>
            </section>

            <section class="card notices">
                <h2>Antes de sacar conclusiones</h2>
                <ul id="notices"></ul>
                <ul>
                    <li>La tasa usa la población <strong>residente</strong>. Los lugares con mucha población flotante (centros comerciales y financieros, balnearios, ciudades turísticas) salen más altos porque mucha gente denuncia ahí pero vive en otro lado.</li>
                    <li>No hay datos abiertos con la ubicación exacta de cada denuncia: el nivel más fino es distrito por mes.</li>
                </ul>
            </section>

            <footer class="sources">
                Fuentes: Denuncias Policiales (SIDPOL, PNP/Mininter) en
                <a href="https://www.datosabiertos.gob.pe/dataset/denuncias-policiales" target="_blank" rel="noopener">datosabiertos.gob.pe</a> ·
                Límites y población 2024: capa Distritos INEI 2025 (PNUD) · Mapa base © colaboradores de OpenStreetMap.
            </footer>
        </aside>
    </main>

    <script>window.CIUDADANO = @json($config);</script>
</body>
</html>
