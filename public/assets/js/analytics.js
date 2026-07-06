// =============================================================================
// analytics.js — Gizlilik dostu kullanım analitiği (arama, konum görüntüleme,
// rota talebi). Diğer modüller App.analytics.trackLocationView /
// trackRouteRequest / scheduleSearchAnalytics ile bu modülü çağırır.
// =============================================================================
window.App = window.App || {};
App.state = App.state || {};

const analyticsState = {
  searchTimer: null,
  lastSearchTerm: '',
  lastSearchAt: 0,
  locationViews: new Map(),
  routeRequests: new Map()
};

function analyticsItemPayload(item) {
  return {
    location_id: item?.id ?? null,
    location_name: item?.name ?? '',
    location_type: item?.type || item?._cat || ''
  };
}

function sendAnalytics(eventType, data = {}) {
  const payload = JSON.stringify({ event_type: eventType, ...data });
  try {
    if (navigator.sendBeacon) {
      const blob = new Blob([payload], { type: 'application/json' });
      navigator.sendBeacon('analytics.php', blob);
      return;
    }
    fetch('analytics.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: payload,
      keepalive: true
    }).catch(() => {});
  } catch (_) {
    // Analitik gönderimi harita kullanımını etkilemez.
  }
}

function trackLocationView(item) {
  if (!item?.coords) return;
  const key = String(item.id ?? item.name ?? '');
  const now = Date.now();
  if ((analyticsState.locationViews.get(key) ?? 0) > now - 30000) return;
  analyticsState.locationViews.set(key, now);
  sendAnalytics('location_view', analyticsItemPayload(item));
}

function trackRouteRequest(item) {
  if (!item?.coords) return;
  const key = `${item.id ?? item.name ?? ''}:${App.state.routeMode}`;
  const now = Date.now();
  if ((analyticsState.routeRequests.get(key) ?? 0) > now - 5000) return;
  analyticsState.routeRequests.set(key, now);
  sendAnalytics('route_request', { ...analyticsItemPayload(item), route_mode: App.state.routeMode });
}

function scheduleSearchAnalytics(raw) {
  const term = String(raw ?? '').replace(/\s+/g, ' ').trim();
  clearTimeout(analyticsState.searchTimer);
  if (term.length < 2) return;
  analyticsState.searchTimer = setTimeout(() => {
    const now = Date.now();
    if (term === analyticsState.lastSearchTerm && analyticsState.lastSearchAt > now - 30000) return;
    analyticsState.lastSearchTerm = term;
    analyticsState.lastSearchAt = now;
    sendAnalytics('search', { search_term: term });
  }, 1000);
}

App.analytics = {
  sendAnalytics,
  trackLocationView,
  trackRouteRequest,
  scheduleSearchAnalytics
};
