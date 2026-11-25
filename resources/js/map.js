/**
 * Mapa público de Ciudadano: coropletas por distrito (Leaflet), ranking y
 * detalle con serie mensual (Chart.js). Todos los datos vienen de /api/v1.
 */
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import {
    Chart, LineController, LineElement, PointElement, LinearScale, CategoryScale, Tooltip, Filler,
} from 'chart.js';

Chart.register(LineController, LineElement, PointElement, LinearScale, CategoryScale, Tooltip, Filler);

const CFG = window.CIUDADANO;
const MONTHS = CFG.months;

// Rampa secuencial (un solo tono, claro → oscuro): 5 clases.
const RAMP = ['#cde2fb', '#86b6ef', '#3987e5', '#1c5cab', '#0d366b'];
const NO_DATA = '#e4e3df';

const nf = new Intl.NumberFormat('es-PE');
const nf1 = new Intl.NumberFormat('es-PE', { maximumFractionDigits: 1 });

const els = {
    period: document.getElementById('f-period'),
    crimeType: document.getElementById('f-crime-type'),
    metricInputs: document.querySelectorAll('#f-metric input'),
    locate: document.getElementById('locate-btn'),
    periodLabel: document.getElementById('period-label'),
    legend: document.getElementById('legend'),
    status: document.getElementById('map-status'),
    detail: document.getElementById('detail'),
    ranking: document.getElementById('ranking'),
    rankingCaption: document.getElementById('ranking-caption'),
    notices: document.getElementById('notices'),
};

const state = {
    period: '',
    crimeType: '',
    metric: 'rate',
    selected: null, // ubigeo
    collection: null, // última FeatureCollection
    breaks: [],
};

let geoLayer = null;
let youMarker = null;
let seriesChart = null;
const layersByUbigeo = new Map();
const cache = new Map();

/* ---------- Utilidades ---------- */

function query(extra = {}) {
    const params = new URLSearchParams();
    if (state.period) params.set('year', state.period);
    if (state.crimeType) params.set('crime_type', state.crimeType);
    params.set('metric', state.metric);
    for (const [k, v] of Object.entries(extra)) params.set(k, v);
    return params.toString();
}

async function api(path) {
    const url = `${CFG.apiBase}${path}`;
    if (!cache.has(url)) {
        cache.set(url, fetch(url, { headers: { Accept: 'application/json' } }).then(async (res) => {
            const body = await res.json().catch(() => ({}));
            if (!res.ok) {
                cache.delete(url);
                const err = new Error(body.message || `Error ${res.status}`);
                err.status = res.status;
                throw err;
            }
            return body;
        }));
    }
    return cache.get(url);
}

function periodText(ym) {
    const [y, m] = ym.split('-').map(Number);
    return `${MONTHS[m - 1]} ${y}`;
}

function metricName(metric) {
    return metric === 'rate' ? 'denuncias por 10 000 hab.' : 'denuncias';
}

function formatValue(v, metric) {
    if (v === null || v === undefined) return 'sin dato';
    return metric === 'rate' ? nf1.format(v) : nf.format(v);
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function titleCase(s) {
    return String(s).toLowerCase().replace(/(^|\s|-)(\p{L})/gu, (m, sep, ch) => sep + ch.toUpperCase())
        .replace(/\b(De|Del|La|Las|Los|El|Y|E)\b/g, (w) => w.toLowerCase()).replace(/^./, (c) => c.toUpperCase());
}

/** Cortes por cuantiles sobre los valores no nulos (hasta 5 clases, sin repetir). */
function quantileBreaks(values) {
    const sorted = values.filter((v) => v !== null).sort((a, b) => a - b);
    if (!sorted.length) return [];
    const breaks = [];
    for (let i = 1; i < RAMP.length; i++) {
        const v = sorted[Math.min(sorted.length - 1, Math.floor((i / RAMP.length) * sorted.length))];
        if (!breaks.length || v > breaks[breaks.length - 1]) breaks.push(v);
    }
    return breaks;
}

function colorFor(value) {
    if (value === null || value === undefined) return NO_DATA;
    let i = 0;
    while (i < state.breaks.length && value >= state.breaks[i]) i++;
    // Con menos cortes que clases, se reparten a lo ancho de la rampa.
    const idx = state.breaks.length ? Math.round((i / state.breaks.length) * (RAMP.length - 1)) : RAMP.length - 1;
    return RAMP[idx];
}

/* ---------- Mapa ---------- */

const map = L.map('map', { zoomControl: true, minZoom: 9, maxZoom: 16 }).setView(CFG.center, 10);
L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
}).addTo(map);

function featureStyle(feature) {
    const selected = feature.properties.ubigeo === state.selected;
    return {
        fillColor: colorFor(feature.properties.value),
        fillOpacity: 0.78,
        color: selected ? '#0b0b0b' : '#ffffff',
        weight: selected ? 3 : 1,
        opacity: 1,
    };
}

function tooltipHtml(p) {
    const metric = state.collection.properties.metric;
    const rank = p.rank ? `Puesto ${p.rank} de ${state.collection.features.length}` : 'Sin posición';
    return `<strong>${escapeHtml(titleCase(p.name))}</strong><br>${formatValue(p.value, metric)} ${metricName(metric)}<br><span class="muted">${rank}</span>`;
}

function renderMap(collection) {
    state.collection = collection;
    state.breaks = quantileBreaks(collection.features.map((f) => f.properties.value));

    if (geoLayer) geoLayer.remove();
    layersByUbigeo.clear();

    geoLayer = L.geoJSON(collection, {
        style: featureStyle,
        onEachFeature(feature, layer) {
            layersByUbigeo.set(feature.properties.ubigeo, layer);
            layer.bindTooltip(() => tooltipHtml(feature.properties), { sticky: true, direction: 'top', className: 'map-tip' });
            layer.on({
                mouseover: () => layer.setStyle({ weight: feature.properties.ubigeo === state.selected ? 3 : 2.5, color: '#0b0b0b' }),
                mouseout: () => geoLayer.resetStyle(layer),
                click: () => selectDistrict(feature.properties.ubigeo),
            });
        },
    }).addTo(map);

    if (!map._fitted) {
        map.fitBounds(geoLayer.getBounds(), { padding: [10, 10] });
        map._fitted = true;
    }

    renderLegend(collection.properties.metric);
}

function renderLegend(metric) {
    const b = state.breaks;
    const rows = [];
    if (b.length) {
        const edges = [null, ...b, null];
        for (let i = 0; i < edges.length - 1; i++) {
            const lo = edges[i];
            const hi = edges[i + 1];
            const sample = lo === null ? (hi - 1e-9) : lo;
            const label = lo === null ? `menos de ${formatValue(hi, metric)}`
                : hi === null ? `${formatValue(lo, metric)} o más`
                    : `${formatValue(lo, metric)} – ${formatValue(hi, metric)}`;
            rows.push(`<li><i style="background:${colorFor(sample)}"></i>${label}</li>`);
        }
    }
    if (state.collection.features.some((f) => f.properties.value === null)) {
        rows.push(`<li><i style="background:${NO_DATA}"></i>Sin población</li>`);
    }
    els.legend.innerHTML = `<p class="legend-title">${metric === 'rate' ? 'Denuncias por 10 000 hab.' : 'Total de denuncias'}</p><ul>${rows.join('')}</ul>`;
}

function restyle() {
    if (geoLayer) geoLayer.setStyle(featureStyle);
    const layer = layersByUbigeo.get(state.selected);
    if (layer) layer.bringToFront();
}

/* ---------- Ranking y avisos ---------- */

function renderRanking() {
    const { features, properties } = state.collection;
    const metric = properties.metric;
    const items = [...features].sort((a, b) => (a.properties.rank ?? 1e9) - (b.properties.rank ?? 1e9));
    const max = properties.max_value || 1;

    els.rankingCaption.textContent = metric === 'rate' ? 'por 10 000 hab.' : 'total';
    els.ranking.innerHTML = items.map(({ properties: p }) => {
        const pct = p.value === null ? 0 : Math.max(2, (p.value / max) * 100);
        const active = p.ubigeo === state.selected ? ' aria-current="true"' : '';
        return `<li><button type="button" data-ubigeo="${p.ubigeo}"${active}>
            <span class="r-pos">${p.rank ?? '–'}</span>
            <span class="r-name">${escapeHtml(titleCase(p.name))}</span>
            <span class="r-val">${formatValue(p.value, metric)}</span>
            <span class="r-bar" style="width:${pct}%"></span>
        </button></li>`;
    }).join('');
}

function renderNotices(properties) {
    els.notices.innerHTML = (properties.notices || []).map((n) => `<li>${escapeHtml(n)}</li>`).join('');
    const crime = state.crimeType ? els.crimeType.selectedOptions[0].textContent.toLowerCase() : 'todas las modalidades';
    els.periodLabel.textContent = `${periodText(properties.from)} – ${periodText(properties.to)} · ${crime}`;
}

/* ---------- Detalle ---------- */

async function selectDistrict(ubigeo, { pan = false } = {}) {
    state.selected = ubigeo;
    restyle();
    renderRanking();
    syncUrl();

    const layer = layersByUbigeo.get(ubigeo);
    if (pan && layer) map.fitBounds(layer.getBounds(), { maxZoom: 13, padding: [30, 30] });

    els.detail.innerHTML = '<p class="hint">Cargando…</p>';
    try {
        const res = await api(`/districts/${ubigeo}?${query()}`);
        if (state.selected !== ubigeo) return;
        renderDetail(res);
    } catch (e) {
        els.detail.innerHTML = `<p class="notice notice-warn">${escapeHtml(e.message)}</p>`;
    }
}

function renderDetail({ data, meta }) {
    const d = data.district;
    const metric = data.ranking.metric;
    const maxType = Math.max(1, ...data.by_crime_type.map((t) => t.total));
    const crimeLabel = state.crimeType ? els.crimeType.selectedOptions[0].textContent : null;

    els.detail.innerHTML = `
        <div class="card-head">
            <div>
                <h2 class="district-name">${escapeHtml(titleCase(d.name))}</h2>
                <p class="muted">${escapeHtml(titleCase(d.province))} · ${d.population ? `${nf.format(d.population)} hab.` : 'sin dato de población'}</p>
            </div>
            <button type="button" class="icon-btn" id="detail-close" aria-label="Cerrar detalle">×</button>
        </div>
        <div class="kpis">
            <div class="kpi"><span class="kpi-value">${data.rate === null ? '–' : nf1.format(data.rate)}</span><span class="kpi-label">por 10 000 hab.</span></div>
            <div class="kpi"><span class="kpi-value">${nf.format(data.total)}</span><span class="kpi-label">denuncias${crimeLabel ? ` de ${escapeHtml(crimeLabel.toLowerCase())}` : ''}</span></div>
            <div class="kpi"><span class="kpi-value">${data.ranking.position ?? '–'}<small>/${data.ranking.of}</small></span><span class="kpi-label">puesto (${metric === 'rate' ? 'tasa' : 'total'})</span></div>
        </div>
        <p class="muted small">${periodText(meta.from)} – ${periodText(meta.to)}. Puesto 1 = más denuncias.</p>

        <h3>Evolución mensual${crimeLabel ? ` · ${escapeHtml(crimeLabel)}` : ''}</h3>
        <div class="chart-box"><canvas id="series-chart" aria-label="Denuncias por mes"></canvas></div>

        <h3>Por modalidad</h3>
        <ul class="types">
            ${data.by_crime_type.map((t) => `
                <li>
                    <span class="t-name">${escapeHtml(t.name)}</span>
                    <span class="t-bar"><span style="width:${t.total ? Math.max(1.5, (t.total / maxType) * 100) : 0}%"></span></span>
                    <span class="t-val">${nf.format(t.total)}</span>
                </li>`).join('')}
        </ul>
        ${state.crimeType ? '<p class="muted small">El desglose por modalidad siempre muestra las 7, aunque filtres por una.</p>' : ''}
    `;

    document.getElementById('detail-close').addEventListener('click', clearSelection);
    renderSeries(data.monthly);
}

/** Línea vertical en el mes del corte de SIDPOL (nov 2024). */
const cutoffPlugin = {
    id: 'cutoff',
    afterDatasetsDraw(chart) {
        const idx = chart.data.labels.indexOf(CFG.cutoff);
        if (idx < 1) return;
        const { ctx, chartArea: area, scales: { x } } = chart;
        const px = x.getPixelForValue(idx);
        ctx.save();
        ctx.strokeStyle = '#8a8984';
        ctx.setLineDash([4, 4]);
        ctx.lineWidth = 1;
        ctx.beginPath();
        ctx.moveTo(px, area.top);
        ctx.lineTo(px, area.bottom);
        ctx.stroke();
        ctx.setLineDash([]);
        ctx.fillStyle = '#52514e';
        ctx.font = '11px system-ui, sans-serif';
        ctx.textAlign = px > area.right - 90 ? 'right' : 'left';
        ctx.fillText('Cambio SIDPOL', px + (ctx.textAlign === 'right' ? -4 : 4), area.top + 10);
        ctx.restore();
    },
};

function renderSeries(monthly) {
    const canvas = document.getElementById('series-chart');
    if (seriesChart) seriesChart.destroy();

    seriesChart = new Chart(canvas, {
        type: 'line',
        data: {
            labels: monthly.map((m) => m.period),
            datasets: [{
                data: monthly.map((m) => m.total),
                borderColor: '#2a78d6',
                backgroundColor: 'rgba(42, 120, 214, 0.10)',
                fill: true,
                borderWidth: 2,
                pointRadius: 0,
                pointHoverRadius: 5,
                pointHoverBorderWidth: 2,
                pointHoverBorderColor: '#fcfcfb',
                pointHoverBackgroundColor: '#2a78d6',
                tension: 0.25,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: { display: false },
                tooltip: {
                    backgroundColor: '#0b0b0b',
                    padding: 8,
                    displayColors: false,
                    callbacks: {
                        title: (items) => periodText(items[0].label),
                        label: (item) => `${nf.format(item.parsed.y)} denuncias`,
                    },
                },
            },
            scales: {
                x: {
                    grid: { display: false },
                    border: { color: '#d6d5d0' },
                    ticks: {
                        color: '#6b6a66',
                        maxRotation: 0,
                        autoSkip: true,
                        maxTicksLimit: 6,
                        callback(value) {
                            const label = this.getLabelForValue(value);
                            const [y, m] = label.split('-');
                            return monthly.length > 24 ? y : `${MONTHS[Number(m) - 1]} ${y.slice(2)}`;
                        },
                    },
                },
                y: {
                    beginAtZero: true,
                    grid: { color: '#eeede9' },
                    border: { display: false },
                    ticks: { color: '#6b6a66', maxTicksLimit: 5, callback: (v) => nf.format(v) },
                },
            },
        },
        plugins: [cutoffPlugin],
    });
}

function clearSelection() {
    state.selected = null;
    restyle();
    renderRanking();
    syncUrl();
    els.detail.innerHTML = '<p class="hint">Toca un distrito en el mapa o en el ranking para ver su detalle.</p>';
}

/* ---------- Carga y filtros ---------- */

function setStatus(text) {
    els.status.hidden = !text;
    els.status.textContent = text || '';
}

async function load() {
    setStatus('Cargando datos…');
    try {
        const collection = await api(`/map/districts?${query()}`);
        renderMap(collection);
        renderRanking();
        renderNotices(collection.properties);
        setStatus('');
        if (state.selected) selectDistrict(state.selected);
    } catch (e) {
        setStatus(`No se pudieron cargar los datos: ${e.message}`);
    }
}

function syncUrl() {
    const params = new URLSearchParams();
    if (state.period) params.set('year', state.period);
    if (state.crimeType) params.set('crime_type', state.crimeType);
    if (state.metric !== 'rate') params.set('metric', state.metric);
    if (state.selected) params.set('distrito', state.selected);
    const qs = params.toString();
    history.replaceState(null, '', qs ? `?${qs}` : location.pathname);
}

function readUrl() {
    const params = new URLSearchParams(location.search);
    const year = params.get('year') ?? '';
    if ([...els.period.options].some((o) => o.value === year)) state.period = year;
    const type = params.get('crime_type') ?? '';
    if ([...els.crimeType.options].some((o) => o.value === type)) state.crimeType = type;
    if (params.get('metric') === 'total') state.metric = 'total';
    const ubigeo = params.get('distrito');
    if (ubigeo && /^\d{6}$/.test(ubigeo)) state.selected = ubigeo;

    els.period.value = state.period;
    els.crimeType.value = state.crimeType;
    els.metricInputs.forEach((i) => { i.checked = i.value === state.metric; });
}

els.period.addEventListener('change', () => { state.period = els.period.value; syncUrl(); load(); });
els.crimeType.addEventListener('change', () => { state.crimeType = els.crimeType.value; syncUrl(); load(); });
els.metricInputs.forEach((input) => input.addEventListener('change', () => {
    state.metric = input.value;
    syncUrl();
    load();
}));

els.ranking.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-ubigeo]');
    if (btn) selectDistrict(btn.dataset.ubigeo, { pan: true });
});

/* ---------- Mi ubicación ---------- */

els.locate.addEventListener('click', () => {
    if (!navigator.geolocation) {
        setStatus('Tu navegador no permite obtener la ubicación.');
        return;
    }
    els.locate.disabled = true;
    setStatus('Buscando tu ubicación…');

    navigator.geolocation.getCurrentPosition(async ({ coords }) => {
        try {
            const res = await api(`/districts/locate?lat=${coords.latitude.toFixed(6)}&lng=${coords.longitude.toFixed(6)}`);
            if (youMarker) youMarker.remove();
            youMarker = L.circleMarker([coords.latitude, coords.longitude], {
                radius: 7, color: '#fcfcfb', weight: 2, fillColor: '#eb6834', fillOpacity: 1,
            }).addTo(map).bindTooltip('Estás aquí');
            setStatus('');
            selectDistrict(res.data.ubigeo, { pan: true });
        } catch (e) {
            setStatus(e.status === 422 ? 'Tu ubicación está fuera de Lima Metropolitana y Callao.' : e.message);
        } finally {
            els.locate.disabled = false;
        }
    }, () => {
        els.locate.disabled = false;
        setStatus('No se pudo obtener tu ubicación (permiso denegado o sin señal).');
    }, { enableHighAccuracy: false, timeout: 10000 });
});

readUrl();
load();
