/**
 * Mapa público de Ciudadano (alcance nacional, por niveles):
 * Perú → departamentos → provincias → distritos, con filtros en cascada, ranking,
 * detalle de cada región con su contexto y comparación de distritos.
 * Tres vistas (zonas, calor, burbujas) y una línea de tiempo que anima el mapa mes a mes.
 * Todos los datos vienen de /api/v1.
 */
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import './leaflet-global';
import 'leaflet.heat';
import {
    Chart, LineController, LineElement, PointElement, LinearScale, CategoryScale, Tooltip, Filler,
} from 'chart.js';

Chart.register(LineController, LineElement, PointElement, LinearScale, CategoryScale, Tooltip, Filler);

const CFG = window.CIUDADANO;
const MONTHS = CFG.months;

// Magnitud (tasa / total): rampa secuencial de un solo tono, 5 clases por cuantiles.
const RAMP = ['#cde2fb', '#86b6ef', '#3987e5', '#1c5cab', '#0d366b'];
const NO_DATA = '#e4e3df';

// Cambio anual: divergente azul (baja) ↔ rojo (sube) con gris neutro al medio. Umbrales fijos.
const CHANGE_CLASSES = [
    { color: '#1c5cab', label: 'Baja más de 15 %', test: (v) => v < -15 },
    { color: '#86b6ef', label: 'Baja entre 5 y 15 %', test: (v) => v < -5 },
    { color: '#f0efec', label: 'Estable (±5 %)', test: (v) => v <= 5 },
    { color: '#f4a6a0', label: 'Sube entre 5 y 15 %', test: (v) => v <= 15 },
    { color: '#b3261e', label: 'Sube más de 15 %', test: () => true },
];

// Series categóricas (comparación): azul y naranja, en orden fijo.
const SERIES = ['#2a78d6', '#eb6834'];
const PREVIOUS_LINE = '#8a8984';

const LEVEL_LABEL = { department: 'departamento', province: 'provincia', district: 'distrito' };
const LEVEL_PLURAL = { department: 'Departamentos', province: 'Provincias', district: 'Distritos' };

const nf = new Intl.NumberFormat('es-PE');
const nf1 = new Intl.NumberFormat('es-PE', { maximumFractionDigits: 1 });
const pct = new Intl.NumberFormat('es-PE', { maximumFractionDigits: 1, signDisplay: 'exceptZero' });

const els = {
    department: document.getElementById('f-department'),
    province: document.getElementById('f-province'),
    district: document.getElementById('f-district'),
    period: document.getElementById('f-period'),
    crimeType: document.getElementById('f-crime-type'),
    metricInputs: document.querySelectorAll('#f-metric input'),
    locate: document.getElementById('locate-btn'),
    reset: document.getElementById('reset-btn'),
    breadcrumb: document.getElementById('breadcrumb'),
    periodLabel: document.getElementById('period-label'),
    legend: document.getElementById('legend'),
    status: document.getElementById('map-status'),
    detail: document.getElementById('detail'),
    ranking: document.getElementById('ranking'),
    rankingTitle: document.getElementById('ranking-title'),
    rankingCaption: document.getElementById('ranking-caption'),
    notices: document.getElementById('notices'),
    viewButtons: document.querySelectorAll('.view-switch button'),
    timeline: document.getElementById('timeline'),
    tlPlay: document.getElementById('tl-play'),
    tlRange: document.getElementById('tl-range'),
    tlAll: document.getElementById('tl-all'),
    tlStart: document.getElementById('tl-start'),
    tlEnd: document.getElementById('tl-end'),
    tlCurrent: document.getElementById('tl-current'),
};

const VIEWS = ['poly', 'heat', 'bubbles'];
const VIEW_URL = { heat: 'calor', bubbles: 'burbujas' };
// Calor con la misma rampa azul de la magnitud (sin rojo/semáforo).
// Rampa clásica de mapa de calor (frío → caliente), solo para la vista Calor.
const HEAT_GRADIENT = { 0: '#2b6cd8', 0.25: '#1fb5d6', 0.45: '#5fd16a', 0.65: '#f5d33a', 0.82: '#f58a1f', 1: '#d7191c' };
const BUBBLE_MAX = { department: 34, province: 26, district: 20 };

const state = {
    dep: '', // código de departamento (2)
    prov: '', // código de provincia (4)
    district: null, // ubigeo seleccionado (6)
    compare: null, // ubigeo con el que se compara
    period: '',
    crimeType: '',
    metric: 'rate',
    collection: null, // última FeatureCollection
    breaks: [],
    view: 'poly', // poly | heat | bubbles
    frame: null, // índice del mes que muestra el mapa (null = todo el periodo)
    timeline: null, // { periods, byCode: Map(code → {totals, values}), metric }
    heatTimeline: null, // igual, a nivel distrito (para el calor)
    points: null, // puntos del nivel actual (burbujas)
    heatPoints: null, // puntos de distritos (calor)
};

let geoLayer = null;
let overlayLayer = null; // calor o burbujas
let playTimer = null;
let loadToken = 0;
let youMarker = null;
let seriesChart = null;
let fittedParent = undefined; // padre para el que ya se ajustó el zoom
const layersByCode = new Map();
const cache = new Map();

/* ---------- Utilidades ---------- */

function statsQuery(extra = {}) {
    const params = new URLSearchParams();
    if (state.period) params.set('year', state.period);
    if (state.crimeType) params.set('crime_type', state.crimeType);
    params.set('metric', state.metric);
    for (const [k, v] of Object.entries(extra)) if (v) params.set(k, v);
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

const mapParent = () => state.prov || state.dep || null;
const mapLevel = () => (state.prov ? 'district' : state.dep ? 'province' : 'department');

function periodText(ym) {
    const [y, m] = ym.split('-').map(Number);
    return `${MONTHS[m - 1]} ${y}`;
}

function rangeText(from, to) {
    return `${periodText(from)} – ${periodText(to)}`;
}

function metricName(metric) {
    return { rate: 'denuncias por 10 000 hab.', total: 'denuncias', change: 'frente al año anterior' }[metric];
}

function formatValue(v, metric) {
    if (v === null || v === undefined) return metric === 'change' ? 'sin comparación' : 'sin dato';
    if (metric === 'change') return `${pct.format(v)} %`;
    return metric === 'rate' ? nf1.format(v) : nf.format(v);
}

/** Flecha + texto: el sentido nunca depende solo del color. */
function changeText(v) {
    if (v === null || v === undefined) return 'sin comparación';
    if (Math.abs(v) <= 5) return `≈ ${pct.format(v)} % (estable)`;
    return `${v > 0 ? '▲' : '▼'} ${pct.format(v)} %`;
}

function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function titleCase(s) {
    return String(s ?? '').toLowerCase().replace(/(^|\s|-|\()(\p{L})/gu, (m, sep, ch) => sep + ch.toUpperCase())
        .replace(/\b(De|Del|La|Las|Los|El|Y|E)\b/g, (w) => w.toLowerCase()).replace(/^./, (c) => c.toUpperCase());
}

function crimeLabel() {
    return state.crimeType ? els.crimeType.selectedOptions[0].textContent : null;
}

function withinText(within) {
    if (!within || within.level === 'country') return 'en el Perú';
    return within.level === 'province' ? `en la provincia de ${titleCase(within.name)}` : `en ${titleCase(within.name)}`;
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

function currentMetric() {
    return state.collection?.properties.metric ?? state.metric;
}

/** Color de la rampa secuencial según cortes por cuantiles. */
function classColor(value, breaks) {
    if (value === null || value === undefined) return NO_DATA;
    let i = 0;
    while (i < breaks.length && value >= breaks[i]) i++;
    const idx = breaks.length ? Math.round((i / breaks.length) * (RAMP.length - 1)) : RAMP.length - 1;
    return RAMP[idx];
}

function colorFor(value) {
    if (value === null || value === undefined) return NO_DATA;
    if (currentMetric() === 'change') return CHANGE_CLASSES.find((c) => c.test(value)).color;
    return classColor(value, state.breaks);
}

/** Valores que muestra el mapa: los del mes elegido en la línea de tiempo o los del periodo. */
function displayed(p) {
    if (state.frame !== null && state.timeline) {
        const row = state.timeline.byCode.get(p.code);
        return row ? { value: row.values[state.frame], total: row.totals[state.frame] } : { value: null, total: 0 };
    }
    return { value: p.value, total: p.total };
}

function percentile(values, q) {
    const sorted = values.filter((v) => v !== null && v !== undefined).sort((a, b) => a - b);
    if (!sorted.length) return 0;
    return sorted[Math.min(sorted.length - 1, Math.floor(q * sorted.length))];
}

/* ---------- Mapa ---------- */

const map = L.map('map', { zoomControl: true, minZoom: 4, maxZoom: 16 }).setView(CFG.center, CFG.zoom);
L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
}).addTo(map);

function featureStyle(feature) {
    const { code } = feature.properties;
    const { value } = displayed(feature.properties);
    const isMain = code === state.district;
    const isCompare = code === state.compare;
    const noComparison = currentMetric() === 'change' && value === null;

    // En calor y burbujas los polígonos quedan como contorno clicable.
    if (state.view !== 'poly') {
        return {
            fillColor: state.view === 'bubbles' ? colorFor(value) : '#ffffff',
            fillOpacity: state.view === 'bubbles' ? 0.12 : 0.02,
            color: isMain ? '#0b0b0b' : isCompare ? SERIES[1] : '#6b6a66',
            weight: isMain || isCompare ? 3 : 0.7,
            dashArray: null,
            opacity: isMain || isCompare ? 1 : 0.55,
        };
    }

    return {
        fillColor: noComparison ? '#ffffff' : colorFor(value),
        fillOpacity: noComparison ? 0.35 : 0.78,
        color: isMain ? '#0b0b0b' : isCompare ? SERIES[1] : (noComparison ? '#8a8984' : '#ffffff'),
        weight: isMain || isCompare ? 3 : 1,
        dashArray: noComparison && !isMain && !isCompare ? '3 3' : null,
        opacity: 1,
    };
}

function tooltipHtml(p) {
    const metric = currentMetric();
    const n = state.collection.features.length;
    const hint = p.level === 'district' ? '' : `<div class="muted tip-hint">Clic para ver sus ${p.level === 'department' ? 'provincias' : 'distritos'}</div>`;
    const head = `<strong>${escapeHtml(titleCase(p.name))}</strong> <span class="muted">· ${LEVEL_LABEL[p.level]}</span>`;

    if (state.frame !== null && state.timeline) {
        const { value, total } = displayed(p);
        const month = periodText(state.timeline.periods[state.frame]);
        const main = metric === 'change' ? `${changeText(value)} frente al mismo mes del año anterior` : `${formatValue(value, metric)} ${metricName(metric)}`;
        return `${head}<div class="tip-month">${escapeHtml(month)}</div><div>${main}</div>`
            + `${metric !== 'total' ? `<div class="muted">${nf.format(total)} denuncias en el mes</div>` : ''}${hint}`;
    }

    // Peso dentro de su padre (o del país): "12 % de las denuncias de Lima".
    const sum = state.collection.features.reduce((s, f) => s + f.properties.total, 0);
    const share = sum ? (p.total / sum) * 100 : null;
    const parentName = state.collection.properties.parent ? titleCase(state.collection.properties.parent.name) : 'el Perú';

    const rows = [
        `<div class="tip-grid">`,
        `<span>Tasa</span><b>${p.rate === null ? 'sin población' : `${nf1.format(p.rate)} <small>por 10 000 hab.</small>`}</b>`,
        `<span>Denuncias</span><b>${nf.format(p.total)}</b>`,
        `<span>Cambio anual</span><b>${changeText(p.change_pct)}</b>`,
        `</div>`,
    ].join('');
    const rank = p.rank ? `Puesto ${p.rank} de ${n} por ${metricName(metric)}` : 'Sin posición';
    const shareText = share !== null ? ` · ${nf1.format(share)} % de las denuncias de ${escapeHtml(parentName)}` : '';
    return `${head}${rows}<div class="muted">${rank}${shareText}</div>${hint}`;
}

function onFeatureClick(p) {
    if (p.level === 'department') goTo({ dep: p.code });
    else if (p.level === 'province') goTo({ dep: state.dep || p.code.slice(0, 2), prov: p.code });
    else if (state.compare && p.code !== state.district) setCompare(p.code);
    else selectDistrict(p.code);

    if (window.matchMedia('(max-width: 899px)').matches && p.level === 'district') {
        els.detail.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
}

/** Cortes de color: del periodo o, con la línea de tiempo, de todos los meses (escala fija al animar). */
function computeBreaks() {
    if (currentMetric() === 'change') {
        state.breaks = [];
    } else if (state.frame !== null && state.timeline) {
        state.breaks = quantileBreaks([...state.timeline.byCode.values()].flatMap((r) => r.values));
    } else {
        state.breaks = quantileBreaks(state.collection.features.map((f) => f.properties.value));
    }
}

/**
 * Con un distrito elegido solo se dibujan él (y el distrito comparado), igual que al
 * elegir una provincia desaparecen las demás. Devuelve los códigos visibles o null (todos).
 */
function focusCodes() {
    if (!state.district || state.collection?.properties.level !== 'district') return null;
    return new Set([state.district, state.compare].filter(Boolean));
}

function applyFocus() {
    if (!geoLayer) return;
    const visible = focusCodes();
    const focused = visible && layersByCode.has(state.district);
    for (const [code, layer] of layersByCode) {
        const show = !focused || visible.has(code);
        if (show && !map.hasLayer(layer)) {
            map.addLayer(layer);
            geoLayer.resetStyle(layer);
        } else if (!show && map.hasLayer(layer)) {
            layer.closeTooltip();
            map.removeLayer(layer);
        }
    }
}

function bringSelectedToFront() {
    for (const c of [state.compare, state.district]) {
        const layer = layersByCode.get(c);
        if (layer && map.hasLayer(layer)) layer.bringToFront();
    }
}

function highlight(code, on) {
    const layer = layersByCode.get(code);
    if (layer && geoLayer && map.hasLayer(layer)) {
        if (on) {
            layer.setStyle({ weight: 3, color: code === state.compare ? SERIES[1] : '#0b0b0b', dashArray: null, opacity: 1 });
            layer.bringToFront();
        } else {
            geoLayer.resetStyle(layer);
            bringSelectedToFront();
        }
    }
    els.ranking.querySelector(`button[data-code="${code}"]`)?.classList.toggle('is-hover', on);
}

function renderMap(collection) {
    state.collection = collection;
    computeBreaks();

    if (geoLayer) geoLayer.remove();
    layersByCode.clear();

    geoLayer = L.geoJSON(collection, {
        style: featureStyle,
        onEachFeature(feature, layer) {
            const { code } = feature.properties;
            layersByCode.set(code, layer);
            layer.bindTooltip(() => tooltipHtml(feature.properties), { sticky: true, direction: 'top', className: 'map-tip' });
            layer.on({
                mouseover: () => highlight(code, true),
                mouseout: () => highlight(code, false),
                click: () => onFeatureClick(feature.properties),
            });
        },
    }).addTo(map);
    applyFocus();

    // Ajusta el zoom cuando cambia el nivel/padre (no al cambiar periodo o métrica).
    const parent = mapParent();
    if (fittedParent !== parent && geoLayer.getLayers().length) {
        if (state.district && layersByCode.has(state.district)) zoomTo(state.district);
        else map.fitBounds(geoLayer.getBounds(), { padding: [12, 12] });
        fittedParent = parent;
    }

    renderOverlay();
    renderLegend(collection.properties);
}

/* ---------- Calor y burbujas ---------- */

function heatRadius() {
    return Math.max(12, Math.min(70, Math.round(10 * 1.45 ** (map.getZoom() - 5))));
}

/** Calor: cada distrito aporta su centroide, pesado por la tasa (o el total). */
function heatData() {
    const points = state.heatPoints || [];
    const tl = state.frame !== null ? state.heatTimeline : null;
    const valueOf = (pt) => {
        if (!tl) return pt.value;
        const row = tl.byCode.get(pt.code);
        return row ? row.values[state.frame] : null;
    };
    // Normaliza con el percentil 95 para que unos pocos distritos extremos no apaguen el resto.
    const scale = tl
        ? percentile([...tl.byCode.values()].flatMap((r) => r.values), 0.95)
        : percentile(points.map((p) => p.value), 0.95);
    const visible = focusCodes();
    return points
        .filter((pt) => !visible || visible.has(pt.code))
        .map((pt) => [pt.lat, pt.lng, Math.min(1, Math.max(0, (valueOf(pt) ?? 0) / (scale || 1)))])
        .filter((p) => p[2] > 0);
}

function bubbleLayer() {
    const level = state.collection.properties.level;
    const maxR = BUBBLE_MAX[level] ?? 20;
    const totals = state.frame !== null && state.timeline
        ? [...state.timeline.byCode.values()].flatMap((r) => r.totals)
        : state.points.map((p) => p.total);
    const maxTotal = Math.max(1, ...totals);

    const group = L.featureGroup();
    const visible = focusCodes();
    const sorted = state.points.filter((pt) => !visible || visible.has(pt.code)).sort((a, b) => displayed(b).total - displayed(a).total); // grandes abajo
    for (const pt of sorted) {
        const { value, total } = displayed(pt);
        if (!total) continue;
        const isSel = pt.code === state.district || pt.code === state.compare;
        const marker = L.circleMarker([pt.lat, pt.lng], {
            radius: Math.max(3, Math.sqrt(total / maxTotal) * maxR),
            fillColor: colorFor(value),
            fillOpacity: 0.85,
            color: isSel ? '#0b0b0b' : '#ffffff',
            weight: isSel ? 2.5 : 1,
        });
        const feature = state.collection.features.find((f) => f.properties.code === pt.code);
        marker.bindTooltip(() => tooltipHtml(feature ? feature.properties : pt), { direction: 'top', className: 'map-tip' });
        marker.on({
            click: () => onFeatureClick(feature ? feature.properties : pt),
            mouseover: () => highlight(pt.code, true),
            mouseout: () => highlight(pt.code, false),
        });
        group.addLayer(marker);
    }
    return group;
}

async function ensurePoints() {
    const parent = mapParent();
    if (state.view === 'heat' && !state.heatPoints) {
        // El calor no tiene sentido con el % de cambio: usa la tasa.
        const metric = state.metric === 'change' ? 'rate' : state.metric;
        const res = await api(`/map/points?${statsQuery({ level: 'district', parent, metric })}`);
        state.heatPoints = res.data;
    }
    if (state.view === 'bubbles' && !state.points) {
        const res = await api(`/map/points?${statsQuery({ level: mapLevel(), parent })}`);
        state.points = res.data;
    }
}

async function ensureHeatTimeline() {
    if (state.view !== 'heat' || state.frame === null || state.heatTimeline) return;
    const metric = state.metric === 'change' ? 'rate' : state.metric;
    state.heatTimeline = indexTimeline(await api(`/map/timeline?${statsQuery({ level: 'district', parent: mapParent(), metric })}`));
}

async function renderOverlay() {
    const token = loadToken;
    try {
        await ensurePoints();
        await ensureHeatTimeline();
    } catch (e) {
        setStatus(`No se pudieron cargar los puntos: ${e.message}`);
        return;
    }
    if (token !== loadToken) return;

    if (state.view === 'heat') {
        const data = heatData();
        if (overlayLayer instanceof L.HeatLayer) {
            overlayLayer.setLatLngs(data);
            return;
        }
        if (overlayLayer) overlayLayer.remove();
        overlayLayer = L.heatLayer(data, { radius: heatRadius(), blur: Math.round(heatRadius() * 0.8), max: 1, maxZoom: 9, minOpacity: 0.25, gradient: HEAT_GRADIENT }).addTo(map);
        return;
    }

    if (overlayLayer) overlayLayer.remove();
    overlayLayer = null;
    if (state.view === 'bubbles') overlayLayer = bubbleLayer().addTo(map);
}

function clearOverlay() {
    if (overlayLayer) overlayLayer.remove();
    overlayLayer = null;
}

map.on('zoomend', () => {
    if (state.view === 'heat' && overlayLayer?.setOptions) {
        overlayLayer.setOptions({ radius: heatRadius(), blur: Math.round(heatRadius() * 0.8) });
    }
});

function setView(view) {
    if (!VIEWS.includes(view) || view === state.view) return;
    state.view = view;
    els.viewButtons.forEach((b) => b.setAttribute('aria-checked', String(b.dataset.view === view)));
    clearOverlay();
    syncUrl();
    if (!state.collection) return;
    restyle();
    renderOverlay();
    renderLegend(state.collection.properties);
}

/* ---------- Leyenda ---------- */

function frameSuffix() {
    return state.frame !== null && state.timeline ? ` · ${periodText(state.timeline.periods[state.frame])}` : '';
}

function renderLegend(props) {
    const metric = props.metric;

    if (state.view === 'heat') {
        const base = state.metric === 'change' || metric === 'change' ? 'rate' : metric;
        const label = base === 'rate' ? 'tasa por 10 000 hab.' : 'total de denuncias';
        els.legend.innerHTML = `<p class="legend-title">Intensidad · ${label}${frameSuffix()}</p>
            <div class="legend-gradient"></div><div class="legend-scale"><span>baja</span><span>alta</span></div>
            <p class="legend-note">Cada distrito aporta un punto en su centro, pesado por su ${label}. Es una vista aproximada: no indica calles ni lugares exactos.${metric === 'change' ? ' El cambio anual se ve en Zonas o Burbujas.' : ''}</p>`;
        return;
    }

    const rows = colorRows(props);
    let extra = '';
    if (state.view === 'bubbles') {
        const level = props.level;
        const maxR = BUBBLE_MAX[level] ?? 20;
        const totals = state.frame !== null && state.timeline
            ? [...state.timeline.byCode.values()].flatMap((r) => r.totals)
            : (state.points || state.collection.features.map((f) => f.properties)).map((p) => p.total);
        const maxTotal = Math.max(1, ...totals);
        const samples = [maxTotal, maxTotal / 4, maxTotal / 16].map((t) => Math.max(1, Math.round(t)));
        extra = `<div class="legend-bubbles">${samples.map((t) => {
            const d = Math.max(6, Math.sqrt(t / maxTotal) * maxR * 2);
            return `<span><i style="width:${d}px;height:${d}px"></i>${nf.format(t)}</span>`;
        }).join('')}</div><p class="legend-note">Tamaño = denuncias${state.frame !== null ? ' en el mes' : ''}; color = ${metric === 'change' ? 'cambio anual' : metric === 'rate' ? 'tasa' : 'total'}.</p>`;
    }
    const title = metric === 'change'
        ? (state.frame !== null ? 'Cambio frente al mismo mes del año anterior' : `Cambio frente a ${rangeText(props.comparison.from, props.comparison.to)}`)
        : metric === 'rate' ? `Denuncias por 10 000 hab.${state.frame !== null ? ' en el mes' : ''}` : 'Total de denuncias';
    els.legend.innerHTML = `<p class="legend-title">${title}${frameSuffix()}</p><ul>${rows.join('')}</ul>${extra}`;
}

function colorRows(props) {
    const metric = props.metric;
    const rows = [];

    if (metric === 'change') {
        for (const c of [...CHANGE_CLASSES].reverse()) rows.push(`<li><i style="background:${c.color}"></i>${c.label}</li>`);
        if (state.view === 'poly') rows.push('<li><i class="legend-dashed"></i>Sin comparación</li>');
        return rows;
    }

    const b = state.breaks;
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
    return rows;
}

function restyle() {
    applyFocus();
    if (geoLayer) geoLayer.setStyle(featureStyle);
    bringSelectedToFront();
    if ((state.view === 'bubbles' && state.points) || (state.view === 'heat' && state.heatPoints)) renderOverlay();
}

/* ---------- Línea de tiempo (mes a mes) ---------- */

function indexTimeline(res) {
    return {
        periods: res.data.periods,
        metric: res.meta.metric,
        byCode: new Map(res.data.items.map((i) => [i.code, i])),
    };
}

function resetTimeline() {
    stopPlay();
    state.frame = null;
    state.timeline = null;
    state.heatTimeline = null;
    state.points = null;
    state.heatPoints = null;
    const props = state.collection?.properties;
    if (!props) return;
    const months = monthsBetween(props.from, props.to);
    els.tlRange.max = String(Math.max(0, months.length - 1));
    els.tlRange.value = els.tlRange.max;
    els.tlStart.textContent = periodText(props.from);
    els.tlEnd.textContent = periodText(props.to);
    updateTimelineUi();
}

function monthsBetween(from, to) {
    const out = [];
    let [y, m] = from.split('-').map(Number);
    const [ty, tm] = to.split('-').map(Number);
    while (y < ty || (y === ty && m <= tm)) {
        out.push(`${y}-${String(m).padStart(2, '0')}`);
        if (++m > 12) { m = 1; y++; }
    }
    return out;
}

function updateTimelineUi() {
    const aggregate = state.frame === null;
    els.timeline.classList.toggle('is-aggregate', aggregate);
    els.tlAll.setAttribute('aria-pressed', String(aggregate));
    const props = state.collection?.properties;
    els.tlCurrent.textContent = aggregate
        ? (props ? `Todo el periodo (${rangeText(props.from, props.to)})` : 'Todo el periodo')
        : periodText(state.timeline.periods[state.frame]);
}

async function ensureTimeline() {
    if (state.timeline) return state.timeline;
    els.tlPlay.disabled = true;
    try {
        state.timeline = indexTimeline(await api(`/map/timeline?${statsQuery({ level: mapLevel(), parent: mapParent() })}`));
    } finally {
        els.tlPlay.disabled = false;
    }
    return state.timeline;
}

/** Muestra un mes (índice) o, con null, todo el periodo. */
async function setFrame(index) {
    const token = loadToken;
    if (index !== null) {
        try {
            await ensureTimeline();
        } catch (e) {
            setStatus(`No se pudo cargar la evolución mensual: ${e.message}`);
            stopPlay();
            return;
        }
        if (token !== loadToken) return;
        index = Math.max(0, Math.min(state.timeline.periods.length - 1, index));
    }
    state.frame = index;
    els.tlRange.value = String(index ?? els.tlRange.max);
    computeBreaks();
    restyle();
    if (state.view === 'heat') await renderOverlay();
    renderLegend(state.collection.properties);
    updateTimelineUi();
}

function stopPlay() {
    clearInterval(playTimer);
    playTimer = null;
    els.tlPlay.classList.remove('is-playing');
    els.tlPlay.setAttribute('aria-label', 'Reproducir mes a mes');
}

async function togglePlay() {
    if (playTimer) {
        stopPlay();
        return;
    }
    const last = Number(els.tlRange.max);
    const start = state.frame === null || state.frame >= last ? 0 : state.frame + 1;
    await setFrame(start);
    els.tlPlay.classList.add('is-playing');
    els.tlPlay.setAttribute('aria-label', 'Pausar');
    playTimer = setInterval(() => {
        if (state.frame >= last) {
            stopPlay();
            return;
        }
        setFrame(state.frame + 1);
    }, 1100);
}

/* ---------- Ranking, ruta y avisos ---------- */

function renderRanking() {
    const { features, properties } = state.collection;
    const metric = properties.metric;
    const level = properties.level;
    const items = [...features].sort((a, b) => (a.properties.rank ?? 1e9) - (b.properties.rank ?? 1e9));
    const max = metric === 'change'
        ? Math.max(1, ...features.map((f) => Math.abs(f.properties.value ?? 0)))
        : (properties.max_value || 1);

    const parentName = properties.parent ? ` de ${titleCase(properties.parent.name)}` : ' del Perú';
    els.rankingTitle.textContent = `${LEVEL_PLURAL[level]}${parentName}`;
    els.rankingCaption.textContent = { rate: 'por 10 000 hab.', total: 'total', change: 'cambio anual' }[metric];
    els.ranking.innerHTML = items.map(({ properties: p }) => {
        const v = p.value;
        const width = v === null ? 0 : Math.max(2, (Math.abs(v) / max) * 100);
        const barColor = metric === 'change' && v !== null ? colorFor(v) : '';
        const current = p.code === state.district || p.code === state.compare ? ' aria-current="true"' : '';
        return `<li><button type="button" data-code="${p.code}"${current}>
            <span class="r-pos">${p.rank ?? '–'}</span>
            <span class="r-name">${escapeHtml(titleCase(p.name))}</span>
            <span class="r-val">${formatValue(v, metric)}</span>
            <span class="r-bar" style="width:${width}%${barColor ? `;background:${barColor}` : ''}"></span>
        </button></li>`;
    }).join('');
}

function renderBreadcrumb(crumbs) {
    const all = [...crumbs];
    if (state.district) {
        const f = state.collection?.features.find((x) => x.properties.code === state.district);
        if (f) all.push({ code: state.district, level: 'district', name: f.properties.name });
    }
    els.breadcrumb.innerHTML = all.map((c, i) => {
        const label = c.level === 'country' ? 'Perú' : titleCase(c.name);
        const last = i === all.length - 1;
        return last
            ? `<span aria-current="page">${escapeHtml(label)}</span>`
            : `<button type="button" class="crumb" data-level="${c.level}" data-code="${c.code ?? ''}">${escapeHtml(label)}</button><span class="sep" aria-hidden="true">›</span>`;
    }).join('');
}

function renderNotices(properties) {
    els.notices.innerHTML = (properties.notices || []).map((n) => `<li>${escapeHtml(n)}</li>`).join('');
    const crime = crimeLabel()?.toLowerCase() ?? 'todas las modalidades';
    els.periodLabel.textContent = `${rangeText(properties.from, properties.to)} · ${crime}`;
}

/* ---------- Filtros en cascada ---------- */

function fillSelect(select, items, placeholder, selected) {
    select.innerHTML = `<option value="">${placeholder}</option>`
        + items.map((i) => `<option value="${i.code}"${i.code === selected ? ' selected' : ''}>${escapeHtml(titleCase(i.name))}</option>`).join('');
    select.disabled = false;
}

function resetSelect(select, placeholder) {
    select.innerHTML = `<option value="">${placeholder}</option>`;
    select.disabled = true;
}

async function syncSelects() {
    els.department.value = state.dep;
    if (state.dep) {
        const res = await api(`/geo/departments/${state.dep}/provinces`);
        fillSelect(els.province, res.data, 'Todas las provincias', state.prov);
    } else {
        resetSelect(els.province, 'Todas las provincias');
    }
    if (state.prov) {
        const res = await api(`/geo/provinces/${state.prov}/districts`);
        fillSelect(els.district, res.data, 'Todos los distritos', state.district ?? '');
    } else {
        resetSelect(els.district, 'Todos los distritos');
    }
}

/** Navega a una ubicación: { dep, prov, district }. */
async function goTo({ dep = '', prov = '', district = null } = {}) {
    if (district) {
        prov = district.slice(0, 4);
        dep = district.slice(0, 2);
    } else if (prov) {
        dep = prov.slice(0, 2);
    }
    const parentChanged = state.dep !== dep || state.prov !== prov;
    state.dep = dep;
    state.prov = prov;
    state.district = district;
    if (!district || state.compare === district) state.compare = null;

    syncUrl();
    syncSelects().catch(() => {});
    if (parentChanged) await load();
    else {
        restyle();
        renderRanking();
        renderBreadcrumb(state.collection.properties.breadcrumb);
        showDetail();
    }
    if (district) zoomTo(district);
}

function zoomTo(code) {
    const layer = layersByCode.get(code);
    if (layer) map.fitBounds(layer.getBounds(), { maxZoom: 13, padding: [30, 30] });
}

/* ---------- Detalle ---------- */

function showDetail() {
    if (state.district) return state.compare ? loadCompare() : loadRegionDetail(state.district);
    if (state.prov) return loadRegionDetail(state.prov);
    if (state.dep) return loadRegionDetail(state.dep);
    return renderCountrySummary();
}

function selectDistrict(code) {
    goTo({ district: code });
}

function renderCountrySummary() {
    const { features, properties } = state.collection;
    const total = features.reduce((s, f) => s + f.properties.total, 0);
    const population = features.reduce((s, f) => s + (f.properties.population ?? 0), 0);
    const prevAll = features.every((f) => f.properties.previous_total !== null);
    const previous = prevAll ? features.reduce((s, f) => s + f.properties.previous_total, 0) : null;
    const change = previous ? Math.round(((total - previous) / previous) * 1000) / 10 : null;
    const rate = population ? Math.round((total / population) * 100000) / 10 : null;

    els.detail.innerHTML = `
        <div class="card-head">
            <div>
                <p class="eyebrow">País</p>
                <h2 class="district-name">Perú</h2>
                <p class="muted">${features.length} departamentos · ${nf.format(population)} hab.</p>
            </div>
        </div>
        <div class="kpis">
            <div class="kpi"><span class="kpi-value">${rate === null ? '–' : nf1.format(rate)}</span><span class="kpi-label">por 10 000 hab.</span></div>
            <div class="kpi"><span class="kpi-value">${nf.format(total)}</span><span class="kpi-label">denuncias${crimeLabel() ? ` de ${escapeHtml(crimeLabel().toLowerCase())}` : ''}</span></div>
            <div class="kpi"><span class="kpi-value kpi-change">${change === null ? '–' : changeText(change)}</span><span class="kpi-label">frente al año anterior</span></div>
        </div>
        <p class="muted small">${rangeText(properties.from, properties.to)}.</p>
        <p class="hint">Elige un departamento en el mapa, en el ranking o en los filtros para bajar a sus provincias y distritos.</p>
    `;
}

async function loadRegionDetail(code) {
    els.detail.innerHTML = '<p class="hint">Cargando…</p>';
    try {
        const res = await api(`/regions/${code}?${statsQuery()}`);
        const current = state.district || state.prov || state.dep;
        if (current !== code || state.compare) return;
        renderRegionDetail(res);
    } catch (e) {
        els.detail.innerHTML = `<p class="notice notice-warn">${escapeHtml(e.message)}</p>`;
    }
}

function benchmarkHtml(data) {
    const level = data.region.level;
    const selfLabel = { department: 'Este departamento', province: 'Esta provincia', district: 'Este distrito' }[level];
    const rows = [
        { label: selfLabel, rate: data.rate, self: true },
        ...data.context.averages.map((a) => ({
            label: a.level === 'country' ? 'Promedio del Perú'
                : a.level === 'province' ? `Provincia de ${titleCase(a.name)}` : `Departamento de ${titleCase(a.name)}`,
            rate: a.rate,
        })),
    ];
    if (data.rate === null || rows.every((r) => r.rate === null)) return '';

    const max = Math.max(...rows.map((r) => r.rate ?? 0), 1);
    const nearest = data.context.averages.find((a) => a.rate !== null);
    let summary = '';
    if (nearest) {
        const diff = ((data.rate / nearest.rate) - 1) * 100;
        const who = nearest.level === 'country' ? 'el promedio del Perú'
            : nearest.level === 'province' ? 'su provincia' : 'su departamento';
        summary = Math.abs(diff) < 5
            ? `Su tasa es <strong>similar</strong> a la de ${who}.`
            : `Su tasa es <strong>${nf.format(Math.round(Math.abs(diff)))} % ${diff > 0 ? 'más alta' : 'más baja'}</strong> que la de ${who}.`;
    }

    return `
        <h3>Comparado con su entorno</h3>
        ${summary ? `<p class="bench-summary">${summary}</p>` : ''}
        <ul class="bench">
            ${rows.map((r) => `<li class="${r.self ? 'is-self' : ''}">
                <span class="b-name">${escapeHtml(r.label)}</span>
                <span class="b-bar"><span style="width:${r.rate === null ? 0 : Math.max(1.5, (r.rate / max) * 100)}%"></span></span>
                <span class="b-val">${r.rate === null ? '–' : nf1.format(r.rate)}</span>
            </li>`).join('')}
        </ul>
        <p class="muted small">Denuncias por 10 000 habitantes, mismo periodo y modalidad.</p>`;
}

function neighborsHtml(data) {
    const list = data.context.neighbors;
    if (!list.length) return '';
    const ownProvince = data.breadcrumb?.find((c) => c.level === 'province')?.name;
    return `
        <h3>Distritos vecinos</h3>
        <ul class="neighbors">
            ${list.map((n) => `<li><button type="button" data-ubigeo="${n.ubigeo}">
                <span class="n-name">${escapeHtml(titleCase(n.name))}${n.province !== ownProvince ? ` <span class="muted">(${escapeHtml(titleCase(n.province))})</span>` : ''}</span>
                <span class="n-val">${n.rate === null ? '–' : nf1.format(n.rate)}</span>
                <span class="n-chg">${n.change_pct === null ? '' : `${n.change_pct > 0 ? '▲' : n.change_pct < 0 ? '▼' : ''} ${pct.format(n.change_pct)} %`}</span>
            </button></li>`).join('')}
        </ul>
        <p class="muted small">Tasa por 10 000 hab. y cambio anual. Clic para ir al distrito.</p>`;
}

function renderRegionDetail({ data, meta }) {
    const r = data.region;
    const isDistrict = r.level === 'district';
    const metric = data.ranking.metric;
    const maxType = Math.max(1, ...data.by_crime_type.map((t) => t.total));
    const crime = crimeLabel();
    const comparable = meta.comparison.available;
    const parents = (data.breadcrumb || []).filter((c) => c.level !== 'country' && c.code !== r.code).map((c) => titleCase(c.name));

    els.detail.innerHTML = `
        <div class="card-head">
            <div>
                <p class="eyebrow">${LEVEL_LABEL[r.level]}${parents.length ? ` · ${escapeHtml(parents.join(' › '))}` : ''}</p>
                <h2 class="district-name">${escapeHtml(titleCase(r.name))}</h2>
                <p class="muted">${r.population ? `${nf.format(r.population)} hab.` : 'sin dato de población'}</p>
            </div>
            <button type="button" class="icon-btn" id="detail-close" aria-label="Subir un nivel">×</button>
        </div>
        <div class="kpis">
            <div class="kpi"><span class="kpi-value">${data.rate === null ? '–' : nf1.format(data.rate)}</span><span class="kpi-label">por 10 000 hab.</span></div>
            <div class="kpi"><span class="kpi-value">${nf.format(data.total)}</span><span class="kpi-label">denuncias${crime ? ` de ${escapeHtml(crime.toLowerCase())}` : ''}</span></div>
            <div class="kpi"><span class="kpi-value">${data.ranking.position ?? '–'}<small>/${data.ranking.of}</small></span><span class="kpi-label">puesto ${escapeHtml(withinText(data.ranking.within))}</span></div>
        </div>
        <p class="change-line">${comparable && data.change_pct !== null
            ? `<strong>${changeText(data.change_pct)}</strong> frente a ${rangeText(meta.comparison.from, meta.comparison.to)} <span class="muted">(${nf.format(data.previous_total)} → ${nf.format(data.total)})</span>`
            : '<span class="muted">Sin datos del mismo periodo del año anterior para comparar.</span>'}</p>
        <p class="muted small">${rangeText(meta.from, meta.to)}. Puesto 1 = ${metric === 'change' ? 'mayor aumento' : metric === 'rate' ? 'mayor tasa' : 'más denuncias'}.</p>

        ${benchmarkHtml(data)}
        ${isDistrict ? neighborsHtml(data) : ''}

        <h3>Evolución mensual${crime ? ` · ${escapeHtml(crime)}` : ''}</h3>
        ${comparable ? `<ul class="chart-legend">
            <li><i style="background:${SERIES[0]}"></i>${rangeText(meta.from, meta.to)}</li>
            <li><i class="dashed" style="border-color:${PREVIOUS_LINE}"></i>Año anterior</li>
        </ul>` : ''}
        <div class="chart-box"><canvas id="series-chart" aria-label="Denuncias por mes"></canvas></div>

        ${CFG.firstPeriod ? `<h3>Mapa de calor mensual${crime ? ` · ${escapeHtml(crime)}` : ''}</h3>
        <div id="calendar"><p class="hint">Cargando…</p></div>` : ''}

        <h3>Por modalidad</h3>
        <ul class="types">
            ${data.by_crime_type.map((t) => `
                <li>
                    <span class="t-name">${escapeHtml(t.name)}</span>
                    <span class="t-bar"><span style="width:${t.total ? Math.max(1.5, (t.total / maxType) * 100) : 0}%"></span></span>
                    <span class="t-val">${nf.format(t.total)}</span>
                    ${comparable ? `<span class="t-chg">${t.change_pct === null ? '–' : `${t.change_pct > 0 ? '▲' : t.change_pct < 0 ? '▼' : ''} ${pct.format(t.change_pct)} %`}</span>` : ''}
                </li>`).join('')}
        </ul>
        ${state.crimeType ? '<p class="muted small">El desglose por modalidad siempre muestra las 7, aunque filtres por una.</p>' : ''}

        ${isDistrict ? `<div class="compare-box">${compareSelectHtml(null)}</div>` : ''}
    `;

    document.getElementById('detail-close').addEventListener('click', goUp);
    els.detail.querySelectorAll('.neighbors button').forEach((b) => b.addEventListener('click', () => goTo({ district: b.dataset.ubigeo })));
    if (isDistrict) bindCompareSelect();

    const datasets = [lineDataset(data.monthly.map((m) => m.total), SERIES[0], { fill: true })];
    if (comparable) {
        datasets.push(lineDataset(data.monthly.map((m) => m.previous_total), PREVIOUS_LINE, { dashed: true, label: 'Año anterior' }));
    }
    renderChart(data.monthly.map((m) => m.period), datasets, (v) => `${nf.format(v)} denuncias`);
    if (CFG.firstPeriod) loadCalendar(r.code);
}

/* ---------- Calendario de calor (todos los meses desde 2018) ---------- */

async function loadCalendar(code) {
    const box = () => document.getElementById('calendar');
    const params = new URLSearchParams({ from: CFG.firstPeriod, to: CFG.latestPeriod, metric: 'total' });
    if (state.crimeType) params.set('crime_type', state.crimeType);
    try {
        const res = await api(`/regions/${code}?${params}`);
        if (!box() || (state.district || state.prov || state.dep) !== code) return;
        renderCalendar(box(), res.data);
    } catch (e) {
        if (box()) box().innerHTML = `<p class="muted small">${escapeHtml(e.message)}</p>`;
    }
}

function renderCalendar(box, data) {
    const useRate = data.region.population > 0;
    const valueOf = (m) => (useRate ? m.rate : m.total);
    const byPeriod = new Map(data.monthly.map((m) => [m.period, m]));
    const breaks = quantileBreaks(data.monthly.map(valueOf));
    const firstYear = Number(CFG.firstPeriod.slice(0, 4));
    const lastYear = Number(CFG.latestPeriod.slice(0, 4));
    const fmt = (m) => `${periodText(m.period)}: ${nf.format(m.total)} denuncias${useRate ? ` · ${nf1.format(m.rate)} por 10 000 hab.` : ''}`;

    let html = '<div class="calendar" role="grid" aria-label="Denuncias por mes y año"><span></span>';
    html += MONTHS.map((m) => `<span class="c-head">${m.slice(0, 1).toUpperCase()}</span>`).join('');
    for (let y = lastYear; y >= firstYear; y--) {
        html += `<span class="c-year">${y}</span>`;
        for (let mo = 1; mo <= 12; mo++) {
            const period = `${y}-${String(mo).padStart(2, '0')}`;
            const m = byPeriod.get(period);
            const cut = period === CFG.cutoff ? ' is-cutoff' : '';
            html += m
                ? `<span class="c-cell${cut}" style="background:${classColor(valueOf(m), breaks)}" data-tip="${escapeHtml(fmt(m))}" title="${escapeHtml(fmt(m))}"></span>`
                : `<span class="c-cell is-empty${cut}"></span>`;
        }
    }
    html += '</div>';
    html += `<div class="calendar-legend"><span>menos</span>${RAMP.map((c) => `<i style="background:${c}"></i>`).join('')}<span>más</span>
        <i class="cut"></i><span>cambio SIDPOL (${escapeHtml(periodText(CFG.cutoff))})</span></div>
        <p class="calendar-tip" aria-live="polite">${useRate ? 'Tasa por 10 000 hab.' : 'Total de denuncias'} de cada mes. Pasa el cursor por una celda.</p>`;
    box.innerHTML = html;

    const tip = box.querySelector('.calendar-tip');
    const initial = tip.textContent;
    box.querySelector('.calendar').addEventListener('mouseover', (e) => {
        const cell = e.target.closest('[data-tip]');
        tip.textContent = cell ? cell.dataset.tip : initial;
    });
    box.querySelector('.calendar').addEventListener('mouseleave', () => { tip.textContent = initial; });
}

/** Cierra el detalle actual subiendo un nivel. */
function goUp() {
    if (state.district) goTo({ prov: state.prov });
    else if (state.prov) goTo({ dep: state.dep });
    else goTo({});
}

/* ---------- Comparación de distritos ---------- */

function compareSelectHtml(current) {
    const options = [...state.collection.features]
        .map((f) => f.properties)
        .filter((p) => p.level === 'district' && p.code !== state.district)
        .sort((a, b) => a.name.localeCompare(b.name, 'es'))
        .map((p) => `<option value="${p.code}"${p.code === current ? ' selected' : ''}>${escapeHtml(titleCase(p.name))}</option>`)
        .join('');
    return `<label class="compare-pick"><span>${current ? 'Comparar con' : 'Comparar con otro distrito de la provincia'}</span>
        <select id="compare-select"><option value="">Elige un distrito…</option>${options}</select></label>`;
}

function bindCompareSelect() {
    document.getElementById('compare-select')?.addEventListener('change', (e) => {
        if (e.target.value) setCompare(e.target.value);
    });
}

function setCompare(code) {
    if (!code || code === state.district) return;
    state.compare = code;
    restyle();
    renderRanking();
    syncUrl();
    loadCompare();
}

function clearCompare() {
    state.compare = null;
    restyle();
    renderRanking();
    syncUrl();
    loadRegionDetail(state.district);
}

async function loadCompare() {
    const pair = [state.district, state.compare];
    els.detail.innerHTML = '<p class="hint">Cargando comparación…</p>';
    try {
        const res = await api(`/districts/compare?${statsQuery({ ubigeos: pair.join(',') })}`);
        if (state.district !== pair[0] || state.compare !== pair[1]) return;
        renderCompare(res);
    } catch (e) {
        els.detail.innerHTML = `<p class="notice notice-warn">${escapeHtml(e.message)}</p>`;
    }
}

function renderCompare({ data, meta }) {
    const [a, b] = data;
    const useRate = a.district.population && b.district.population;
    const unit = useRate ? 'por 10 000 hab.' : 'denuncias';
    const typeValue = (t) => (useRate ? t.rate : t.total);
    const fmtType = (v) => (v === null ? '–' : useRate ? nf1.format(v) : nf.format(v));
    const byTypeB = Object.fromEntries(b.by_crime_type.map((t) => [t.slug, t]));
    const name = (x) => escapeHtml(titleCase(x.district.name));
    const head = (i, x) => `<span class="th-name"><i class="swatch" style="background:${SERIES[i]}"></i><span>${name(x)}</span></span>`;
    const row = (label, fa, fb) => `<tr><th scope="row">${label}</th><td>${fa}</td><td>${fb}</td></tr>`;

    els.detail.innerHTML = `
        <div class="card-head">
            <h2>Comparación</h2>
            <button type="button" class="link-btn" id="compare-close">Quitar comparación</button>
        </div>
        <p class="muted small">${rangeText(meta.from, meta.to)}${crimeLabel() ? ` · ${escapeHtml(crimeLabel())}` : ''}. Toca otro distrito en el mapa para cambiar el segundo.</p>

        <div class="table-scroll">
        <table class="compare-table">
            <thead><tr><th></th><th scope="col">${head(0, a)}</th><th scope="col">${head(1, b)}</th></tr></thead>
            <tbody>
                ${row('Por 10 000 hab.', a.rate === null ? '–' : nf1.format(a.rate), b.rate === null ? '–' : nf1.format(b.rate))}
                ${row('Denuncias', nf.format(a.total), nf.format(b.total))}
                ${row('Puesto en la provincia', `${a.ranking.position ?? '–'}/${a.ranking.of}`, `${b.ranking.position ?? '–'}/${b.ranking.of}`)}
                ${row('Cambio anual', changeText(a.change_pct), changeText(b.change_pct))}
                ${row('Población', a.district.population ? nf.format(a.district.population) : '–', b.district.population ? nf.format(b.district.population) : '–')}
            </tbody>
        </table>
        </div>

        <h3>Evolución mensual · ${unit}</h3>
        <ul class="chart-legend">
            <li><i style="background:${SERIES[0]}"></i>${name(a)}</li>
            <li><i style="background:${SERIES[1]}"></i>${name(b)}</li>
        </ul>
        <div class="chart-box"><canvas id="series-chart" aria-label="Comparación mensual"></canvas></div>

        <h3>Por modalidad · ${unit}</h3>
        <div class="table-scroll">
        <table class="compare-table types-table">
            <thead><tr><th scope="col">Modalidad</th><th scope="col">${head(0, a)}</th><th scope="col">${head(1, b)}</th></tr></thead>
            <tbody>
                ${a.by_crime_type.map((t) => row(escapeHtml(t.name), fmtType(typeValue(t)), fmtType(typeValue(byTypeB[t.slug])))).join('')}
            </tbody>
        </table>
        </div>
        ${useRate ? '' : '<p class="muted small">Falta la población de algún distrito: se comparan totales, no tasas.</p>'}

        <div class="compare-box">${compareSelectHtml(state.compare)}</div>
    `;

    document.getElementById('compare-close').addEventListener('click', clearCompare);
    bindCompareSelect();
    els.detail.scrollIntoView({ behavior: 'smooth', block: 'start' });

    const values = (x) => x.monthly.map((m) => (useRate ? m.rate : m.total));
    renderChart(
        a.monthly.map((m) => m.period),
        [
            lineDataset(values(a), SERIES[0], { label: titleCase(a.district.name) }),
            lineDataset(values(b), SERIES[1], { label: titleCase(b.district.name) }),
        ],
        (v) => (useRate ? `${nf1.format(v)} por 10 000 hab.` : `${nf.format(v)} denuncias`),
        true,
    );
}

/* ---------- Gráfico de línea ---------- */

function lineDataset(data, color, { fill = false, dashed = false, label = '' } = {}) {
    return {
        label,
        data,
        borderColor: color,
        backgroundColor: fill ? 'rgba(42, 120, 214, 0.10)' : 'transparent',
        fill,
        borderWidth: 2,
        borderDash: dashed ? [5, 4] : [],
        pointRadius: 0,
        pointHoverRadius: 5,
        pointHoverBorderWidth: 2,
        pointHoverBorderColor: '#fcfcfb',
        pointHoverBackgroundColor: color,
        tension: 0.25,
    };
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

function renderChart(labels, datasets, formatY, multi = false) {
    const canvas = document.getElementById('series-chart');
    if (seriesChart) seriesChart.destroy();

    seriesChart = new Chart(canvas, {
        type: 'line',
        data: { labels, datasets },
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
                    displayColors: multi || datasets.length > 1,
                    boxPadding: 4,
                    callbacks: {
                        title: (items) => periodText(items[0].label),
                        label: (item) => {
                            const prefix = item.dataset.label ? `${item.dataset.label}: ` : '';
                            return item.parsed.y === null ? `${prefix}sin dato` : `${prefix}${formatY(item.parsed.y)}`;
                        },
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
                            return labels.length > 24 ? y : `${MONTHS[Number(m) - 1]} ${y.slice(2)}`;
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

/* ---------- Carga y URL ---------- */

function setStatus(text) {
    els.status.hidden = !text;
    els.status.textContent = text || '';
}

async function load() {
    const token = ++loadToken;
    stopPlay();
    setStatus('Cargando datos…');
    try {
        const collection = await api(`/map/regions?${statsQuery({ parent: mapParent() })}`);
        if (token !== loadToken) return;
        clearOverlay();
        state.collection = collection;
        resetTimeline();
        renderMap(collection);
        renderRanking();
        renderBreadcrumb(collection.properties.breadcrumb);
        renderNotices(collection.properties);
        setStatus('');
        showDetail();
    } catch (e) {
        setStatus(`No se pudieron cargar los datos: ${e.message}`);
    }
}

function syncUrl() {
    const params = new URLSearchParams();
    if (state.district) params.set('distrito', state.district);
    else if (state.prov) params.set('prov', state.prov);
    else if (state.dep) params.set('dep', state.dep);
    if (state.district && state.compare) params.set('comparar', state.compare);
    if (state.period) params.set('year', state.period);
    if (state.crimeType) params.set('crime_type', state.crimeType);
    if (state.metric !== 'rate') params.set('metric', state.metric);
    if (VIEW_URL[state.view]) params.set('vista', VIEW_URL[state.view]);
    const qs = params.toString();
    history.replaceState(null, '', qs ? `?${qs}` : location.pathname);
}

function readUrl() {
    const params = new URLSearchParams(location.search);
    const year = params.get('year') ?? '';
    if ([...els.period.options].some((o) => o.value === year)) state.period = year;
    const type = params.get('crime_type') ?? '';
    if ([...els.crimeType.options].some((o) => o.value === type)) state.crimeType = type;
    const metric = params.get('metric');
    if (['total', 'change'].includes(metric)) state.metric = metric;
    const view = Object.keys(VIEW_URL).find((k) => VIEW_URL[k] === params.get('vista'));
    if (view) state.view = view;
    els.viewButtons.forEach((b) => b.setAttribute('aria-checked', String(b.dataset.view === state.view)));

    // Enlaces antiguos (?distrito=150122) siguen funcionando: el departamento y la provincia salen del ubigeo.
    const district = params.get('distrito');
    const prov = params.get('prov');
    const dep = params.get('dep');
    if (district && /^\d{6}$/.test(district)) {
        state.district = district;
        state.prov = district.slice(0, 4);
        state.dep = district.slice(0, 2);
        const other = params.get('comparar');
        if (other && /^\d{6}$/.test(other) && other !== district) state.compare = other;
    } else if (prov && /^\d{4}$/.test(prov)) {
        state.prov = prov;
        state.dep = prov.slice(0, 2);
    } else if (dep && /^\d{2}$/.test(dep)) {
        state.dep = dep;
    }

    els.period.value = state.period;
    els.crimeType.value = state.crimeType;
    els.metricInputs.forEach((i) => { i.checked = i.value === state.metric; });
}

/* ---------- Eventos ---------- */

els.department.addEventListener('change', () => goTo({ dep: els.department.value }));
els.province.addEventListener('change', () => goTo(els.province.value ? { prov: els.province.value } : { dep: state.dep }));
els.district.addEventListener('change', () => goTo(els.district.value ? { district: els.district.value } : { prov: state.prov }));

els.period.addEventListener('change', () => { state.period = els.period.value; syncUrl(); load(); });
els.crimeType.addEventListener('change', () => { state.crimeType = els.crimeType.value; syncUrl(); load(); });
els.metricInputs.forEach((input) => input.addEventListener('change', () => {
    state.metric = input.value;
    syncUrl();
    load();
}));

els.reset.addEventListener('click', () => {
    state.period = '';
    state.crimeType = '';
    state.metric = 'rate';
    els.period.value = '';
    els.crimeType.value = '';
    els.metricInputs.forEach((i) => { i.checked = i.value === 'rate'; });
    fittedParent = undefined;
    state.dep = '__reset__'; // fuerza recarga aunque ya estemos en el país
    goTo({});
});

els.breadcrumb.addEventListener('click', (e) => {
    const btn = e.target.closest('button.crumb');
    if (!btn) return;
    const { level, code } = btn.dataset;
    if (level === 'country') goTo({});
    else if (level === 'department') goTo({ dep: code });
    else if (level === 'province') goTo({ prov: code });
});

els.ranking.addEventListener('mouseover', (e) => {
    const btn = e.target.closest('button[data-code]');
    if (btn && !btn.contains(e.relatedTarget)) highlight(btn.dataset.code, true);
});
els.ranking.addEventListener('mouseout', (e) => {
    const btn = e.target.closest('button[data-code]');
    if (btn && !btn.contains(e.relatedTarget)) highlight(btn.dataset.code, false);
});

els.viewButtons.forEach((b) => b.addEventListener('click', () => setView(b.dataset.view)));
els.tlPlay.addEventListener('click', togglePlay);
els.tlAll.addEventListener('click', () => { stopPlay(); setFrame(null); });
els.tlRange.addEventListener('input', () => { stopPlay(); setFrame(Number(els.tlRange.value)); });

els.ranking.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-code]');
    if (!btn) return;
    const feature = state.collection.features.find((f) => f.properties.code === btn.dataset.code);
    if (feature) onFeatureClick(feature.properties);
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
                radius: 7, color: '#fcfcfb', weight: 2, fillColor: '#0b0b0b', fillOpacity: 1,
            }).addTo(map).bindTooltip('Estás aquí');
            setStatus('');
            state.compare = null;
            goTo({ district: res.data.ubigeo });
        } catch (e) {
            setStatus(e.status === 422 ? 'Tu ubicación está fuera del Perú.' : e.message);
        } finally {
            els.locate.disabled = false;
        }
    }, () => {
        els.locate.disabled = false;
        setStatus('No se pudo obtener tu ubicación (permiso denegado o sin señal).');
    }, { enableHighAccuracy: false, timeout: 10000 });
});

readUrl();
syncSelects().catch(() => {});
load();
