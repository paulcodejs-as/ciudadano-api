<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ciudadano · Denuncias por distrito en Lima y Callao</title>
    <meta name="description" content="Mapa de denuncias policiales registradas por distrito en Lima Metropolitana y Callao, con tasa por 10 000 habitantes, ranking y evolución mensual.">
    <meta name="theme-color" content="#fcfcfb">
    @vite(['resources/css/map.css', 'resources/js/map.js'])
</head>
<body>
    <header class="topbar">
        <div class="brand">
            <span class="brand-mark" aria-hidden="true"></span>
            <div>
                <h1>Ciudadano</h1>
                <p>Denuncias registradas por distrito · Lima Metropolitana y Callao</p>
            </div>
        </div>
    </header>

    <form class="toolbar" id="filters" aria-label="Filtros del mapa" onsubmit="return false">
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
            <label><input type="radio" name="metric" value="rate" checked><span>Tasa por 10 000 hab.</span></label>
            <label><input type="radio" name="metric" value="total"><span>Total</span></label>
        </fieldset>

        <button type="button" class="btn" id="locate-btn">
            <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><circle cx="12" cy="12" r="3.5" fill="currentColor"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="12" cy="12" r="7" fill="none" stroke="currentColor" stroke-width="2"/></svg>
            Mi ubicación
        </button>

        <p class="period-label" id="period-label" aria-live="polite"></p>
    </form>

    <main class="layout">
        <section class="map-wrap" aria-label="Mapa de distritos">
            <div id="map" role="application" aria-label="Mapa de Lima y Callao coloreado por denuncias"></div>
            <div class="legend" id="legend" aria-live="polite"></div>
            <div class="map-status" id="map-status" hidden></div>
        </section>

        <aside class="panel" id="panel">
            @unless ($hasData)
                <div class="notice notice-warn">Todavía no hay denuncias importadas. Corre <code>php artisan ciudadano:import-sidpol</code>.</div>
            @endunless

            <section class="card" id="detail" aria-live="polite">
                <p class="hint">Toca un distrito en el mapa o en el ranking para ver su detalle.</p>
            </section>

            <section class="card">
                <div class="card-head">
                    <h2>Ranking de distritos</h2>
                    <span class="muted" id="ranking-caption"></span>
                </div>
                <ol class="ranking" id="ranking"></ol>
            </section>

            <section class="card notices">
                <h2>Antes de sacar conclusiones</h2>
                <ul id="notices"></ul>
                <ul>
                    <li>La tasa usa la población <strong>residente</strong>. Los distritos con mucha población flotante (Cercado de Lima, San Isidro, Miraflores, balnearios) salen más altos porque mucha gente que denuncia ahí vive en otro distrito.</li>
                    <li>No hay datos abiertos con la ubicación exacta de cada denuncia: el nivel más fino es distrito por mes.</li>
                </ul>
            </section>

            <footer class="sources">
                Fuentes: Denuncias Policiales (SIDPOL, PNP/Mininter) en
                <a href="https://www.datosabiertos.gob.pe/dataset/denuncias-policiales" target="_blank" rel="noopener">datosabiertos.gob.pe</a> ·
                Límites y población: capa Distritos INEI 2025 (PNUD) · Mapa base © colaboradores de OpenStreetMap.
            </footer>
        </aside>
    </main>

    <script>window.CIUDADANO = @json($config);</script>
</body>
</html>
