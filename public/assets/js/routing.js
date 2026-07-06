// =============================================================================
// routing.js — Yön tarifi kartı ve canlı rota çizimi (OSRM, başarısızsa düz
// çizgi/haversine fallback). Moda özel OSRM base URL desteği (kendi
// barındırılan osrm-car/osrm-foot) burada çözülür; bkz. public/index.php
// (window.GAUN_CONFIG.routing) ve README "Kendi barındırılan OSRM" bölümü.
// =============================================================================
window.App = window.App || {};
App.state = App.state || {};
Object.assign(App.state, {
  routeMode: 'walk',
  lastRouteOrigin: null,   // gereksiz yeniden hesaplamayı önler
  routeRequestSeq: 0,      // yarış koşulu koruması
  routeLine: null
});

const ROUTING_CONFIG = window.GAUN_CONFIG?.routing || {};
const OSRM_BASE_URL = String(ROUTING_CONFIG.osrmBaseUrl || 'https://router.project-osrm.org').replace(/\/+$/, '');
const OSRM_TIMEOUT_MS = Number.isFinite(Number(ROUTING_CONFIG.timeoutMs))
  ? Math.min(20000, Math.max(500, Number(ROUTING_CONFIG.timeoutMs)))
  : 4000;
const OSRM_PROFILES = {
  walk: ROUTING_CONFIG.profiles?.walk || 'foot',
  drive: ROUTING_CONFIG.profiles?.drive || 'driving'
};
// Moda özel base URL (ör. kendi barındırılan osrm-car/osrm-foot) tanımlıysa
// o kullanılır; yoksa genel OSRM_BASE_URL'e düşülür (public demo davranışı).
// Genel demo tek profille çalıştığından (araç yolu verisi) yaya rotası da
// aslında araç rotası olur — moda özel sunucular bu ayrımı gerçek yapar.
function osrmBaseUrlFor(modeKey) {
  const override = ROUTING_CONFIG.baseUrls?.[modeKey];
  return override ? String(override).replace(/\/+$/, '') : OSRM_BASE_URL;
}
const ROUTE_MODES = {
  walk:  { labelKey: 'route.walk',  profile: OSRM_PROFILES.walk,  color: '#3d6922', speed: 85,  refresh: 10, baseUrl: osrmBaseUrlFor('walk') },
  drive: { labelKey: 'route.drive', profile: OSRM_PROFILES.drive, color: '#1a73e8', speed: 800, refresh: 25, baseUrl: osrmBaseUrlFor('drive') }
};
const dirGoBtn = document.getElementById('dirGo');
const directionsCard = document.getElementById('directionsCard');

function routeModeLabel(modeKey = App.state.routeMode) {
  return App.i18n.t(ROUTE_MODES?.[modeKey]?.labelKey ?? 'route.walk');
}

function clearRouteLine() {
  if (App.state.routeLine) { App.map.map.removeLayer(App.state.routeLine); App.state.routeLine = null; }
}

function routeEtaText(meters) {
  const mode = ROUTE_MODES[App.state.routeMode];
  const minutes = Math.max(1, Math.round(meters / mode.speed));
  const minuteLabel = App.state.lang === 'tr' ? 'dk' : 'min';
  return `${App.map.formatDistance(meters)} • ~${minutes} ${minuteLabel} ${App.i18n.lowerUi(routeModeLabel())}`;
}

function syncRouteModeButtons() {
  document.querySelectorAll('.route-mode').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.mode === App.state.routeMode);
  });
}

function syncDirGoButton() {
  const icon = dirGoBtn.querySelector('.material-symbols-outlined');
  const label = document.getElementById('dirGoLabel');
  if (App.state.watchId === null) {
    icon.textContent = 'my_location';
    label.textContent = App.i18n.t('route.useLocation');
  } else {
    icon.textContent = 'near_me';
    label.textContent = App.i18n.t('route.live');
  }
}

function drawFallbackLine(meters) {
  clearRouteLine();
  const mode = ROUTE_MODES[App.state.routeMode];
  App.state.routeLine = L.polyline([App.state.userLatLng, App.state.selectedItem.coords], {
    color: mode.color, weight: 5, opacity: 0.85, dashArray: '10, 8'
  }).addTo(App.map.map);
  document.getElementById('dirStats').textContent =
    App.i18n.t('route.approx', { eta: routeEtaText(meters) });
}

function routeServiceMessage(reason, meters) {
  const suffix = reason ? ` (${reason})` : '';
  return App.i18n.t('route.serviceUnavailable', { reason: suffix, eta: routeEtaText(meters) });
}

function normalizeRouteBaseUrl(value) {
  return String(value || '').replace(/\/+$/, '');
}

function routeServiceCandidates(mode) {
  const candidates = [
    { baseUrl: mode.baseUrl, profile: mode.profile },
  ];

  if (normalizeRouteBaseUrl(mode.baseUrl) !== OSRM_BASE_URL) {
    candidates.push({ baseUrl: OSRM_BASE_URL, profile: mode.profile });
  }

  if (mode.profile !== 'driving') {
    candidates.push({ baseUrl: OSRM_BASE_URL, profile: 'driving' });
  }

  const seen = new Set();
  return candidates
    .map(candidate => ({
      baseUrl: normalizeRouteBaseUrl(candidate.baseUrl),
      profile: candidate.profile || 'driving'
    }))
    .filter(candidate => {
      const key = `${candidate.baseUrl}|${candidate.profile}`;
      if (!candidate.baseUrl || seen.has(key)) return false;
      seen.add(key);
      return true;
    });
}

async function fetchWithTimeout(url, timeoutMs) {
  const controller = new AbortController();
  const timer = window.setTimeout(() => controller.abort(), timeoutMs);
  try {
    return await fetch(url, { signal: controller.signal });
  } finally {
    window.clearTimeout(timer);
  }
}

async function fetchRoute(candidate, origin, destination) {
  const [dLat, dLng] = destination;
  const url = `${candidate.baseUrl}/route/v1/${encodeURIComponent(candidate.profile)}/` +
    `${origin.lng},${origin.lat};${dLng},${dLat}` +
    `?overview=full&geometries=geojson`;
  const res = await fetchWithTimeout(url, OSRM_TIMEOUT_MS);
  if (!res.ok) throw new Error(`HTTP ${res.status}`);

  const json = await res.json();
  const route = json?.routes?.[0];
  if (!route?.geometry || typeof route.distance !== 'number') {
    throw new Error(App.i18n.t('route.invalidResponse'));
  }
  return route;
}

function routeErrorReason(error) {
  if (error?.name === 'AbortError') return App.i18n.t('route.timeout');
  if (error instanceof TypeError) return App.i18n.t('route.networkError');
  return error?.message || App.i18n.t('route.networkError');
}

async function updateRouteLine() {
  const stats = document.getElementById('dirStats');
  if (!App.state.selectedItem?.coords) { clearRouteLine(); stats.textContent = ''; return; }
  if (!App.state.userLatLng) {
    clearRouteLine();
    stats.textContent = App.i18n.t('route.needsLocation', { mode: routeModeLabel() });
    return;
  }

  const meters = App.map.distanceMeters(App.state.userLatLng, App.state.selectedItem.coords);
  const seq = ++App.state.routeRequestSeq;
  App.state.lastRouteOrigin = L.latLng(App.state.userLatLng);
  const mode = ROUTE_MODES[App.state.routeMode];

  clearRouteLine();
  stats.textContent = App.i18n.t('route.calculating', { mode: routeModeLabel() });

  let lastError = null;
  try {
    for (const candidate of routeServiceCandidates(mode)) {
      try {
        const route = await fetchRoute(candidate, App.state.userLatLng, App.state.selectedItem.coords);
        if (seq !== App.state.routeRequestSeq) return; // eski istek geldi, yok say

        clearRouteLine();
        App.state.routeLine = L.geoJSON(route.geometry, {
          style: { color: mode.color, weight: 6, opacity: 0.9, lineCap: 'round' }
        }).addTo(App.map.map);
        stats.textContent = App.i18n.t('route.result', { mode: routeModeLabel(), eta: routeEtaText(route.distance) });
        return;
      } catch (error) {
        lastError = error;
      }
    }
  } catch (error) {
    lastError = error;
  }

  if (seq !== App.state.routeRequestSeq) return;
  drawFallbackLine(meters);
  stats.textContent = routeServiceMessage(routeErrorReason(lastError), meters);
}

// ---------------------------------------------------------------------------
// Yön tarifi kartı
// ---------------------------------------------------------------------------
function showDirections(item, track = true) {
  if (!item?.coords) return;
  App.state.selectedItem = item;
  document.getElementById('dirName').textContent = App.i18n.displayLocationName(item);
  document.getElementById('dirMeta').textContent =
    [App.i18n.displayTaxonomy(item.type), item.address].filter(Boolean).join(' • ') || App.i18n.t('route.campusLocation');
  syncDirGoButton();
  updateRouteLine();
  directionsCard.classList.remove('hidden');
  if (track) App.analytics.trackLocationView(item);
}

document.getElementById('routeModeGroup').addEventListener('click', (e) => {
  const btn = e.target.closest('.route-mode');
  if (!btn || !ROUTE_MODES[btn.dataset.mode]) return;
  App.state.routeMode = btn.dataset.mode;
  syncRouteModeButtons();
  App.state.lastRouteOrigin = null;
  updateRouteLine();
});

dirGoBtn.addEventListener('click', () => {
  if (!App.state.selectedItem?.coords) return;
  App.analytics.trackRouteRequest(App.state.selectedItem);
  if (App.state.watchId === null) App.map.startTracking();
  else updateRouteLine();
});

document.getElementById('dirClose').addEventListener('click', () => {
  directionsCard.classList.add('hidden');
  App.state.routeRequestSeq++;
  clearRouteLine();
});

App.routing = {
  ROUTE_MODES,
  routeModeLabel,
  clearRouteLine,
  routeEtaText,
  syncRouteModeButtons,
  syncDirGoButton,
  drawFallbackLine,
  routeServiceMessage,
  routeServiceCandidates,
  fetchWithTimeout,
  fetchRoute,
  updateRouteLine,
  showDirections
};
