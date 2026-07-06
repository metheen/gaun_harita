// =============================================================================
// map.js — Leaflet harita örneği, marker yönetimi, canlı konum takibi ve
// "Yakınımdakiler" listesi. Popup HTML'i App.popup.buildPopup'tan gelir;
// dil/tema değişince App.i18n.applyLocale() bu modülün refreshUserMarkerPopup/
// refreshMarkerPopups/renderNearby fonksiyonlarını çağırır.
// =============================================================================
window.App = window.App || {};
App.state = App.state || {};
Object.assign(App.state, {
  introCancelled: false,
  markers: {},
  selectedMarker: null,
  selectedItem: null,
  todayEventMarkers: [],
  userLatLng: null,
  watchId: null,
  userMarker: null,
  accuracyCircle: null,
  firstFix: false
});

// ---------------------------------------------------------------------------
// Kategori ikonları
// ---------------------------------------------------------------------------
const categoryIcons = {
  'Akademik Binalar': '🎓', 'İdari Binalar': '🏛️', 'Merkezler': '🏬', 'Enstitüler': '📖',
  'Yüksekokullar': '🎯', 'Meslek Yüksekokulları': '🧰', 'Kafeler': '☕', 'Hastaneler': '🏥',
  'Yemekhaneler': '🍽️', 'Yurtlar': '🏠', 'Kütüphaneler': '📚', 'Spor Binaları': '🏋️‍♂️',
  'Konser Alanları': '🎵', 'ARGE Binaları': '🔬', 'Uluslararası Ofisler': '🌐',
  'Değişim Programları': '✈️', 'Konservatuvar': '🎻', 'Yabancı Diller': '🗣️',
  'Kategori': '🏫', 'Bina': '🏢', 'Bölüm': '📚', 'Fakülte': '🎓', 'İdari Bina': '🏛️',
  'Merkez': '🏬', 'Enstitü': '📖', 'Yüksekokul': '🎯', 'Meslek Yüksekokulu': '🧰',
  'Kafe': '☕', 'Hastane': '🏥', 'Kütüphane': '📚', 'Spor Binası': '🏋️‍♂️',
  'Konser Alanı': '🎵', 'Değişim Programı': '✈️', 'Kyk Kız Öğrenci Yurdu': '🏠',
  'Çarşı': '🛍️', 'Etik Kurullar': '⚖️', 'Diğer': '📍'
};

// Marker renkleri
const categoryColors = {
  'Akademik Binalar': 'blue', 'İdari Binalar': 'red', 'Merkezler': 'darkgreen',
  'Enstitüler': 'orange', 'Yüksekokullar': 'violet', 'Meslek Yüksekokulları': 'darkred',
  'Kafeler': 'brown', 'Hastaneler': 'darkblue', 'Yemekhaneler': 'teal', 'Yurtlar': 'cadetblue',
  'Kütüphaneler': 'darkpurple', 'Spor Binaları': 'pink', 'Konser Alanları': 'lightgreen',
  'ARGE Binaları': 'navy', 'Uluslararası Ofisler': 'cyan', 'Değişim Programları': 'yellow',
  'Konservatuvar': 'purple', 'Yabancı Diller': 'lightblue', 'Kategori': 'blue', 'Bina': 'darkblue',
  'Bölüm': 'green', 'Fakülte': 'purple', 'İdari Bina': 'red', 'Merkez': 'darkgreen',
  'Enstitü': 'orange', 'Yüksekokul': 'violet', 'Meslek Yüksekokulu': 'darkred', 'Kafe': 'brown',
  'Hastane': 'darkblue', 'Kütüphane': 'darkpurple', 'Spor Binası': 'pink', 'Konser Alanı': 'lightgreen',
  'Değişim Programı': 'yellow', 'Kyk Kız Öğrenci Yurdu': 'cadetblue', 'Yurtlar': 'cadetblue',
  'Diğer': 'gray', 'default': 'blue'
};

// ---------------------------------------------------------------------------
// Harita
// ---------------------------------------------------------------------------
const map = L.map('map', { zoomControl: false }).setView([37.0361399, 37.3118329], 15);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap contributors' }).addTo(map);

// Ayarlar sayfasından (bordo/settings.php) window.GAUN_CONFIG.settings ile
// gelirse onu kullan, yoksa mevcut sabit değerlere düş (geriye dönük uyumlu).
const configSettings = window.GAUN_CONFIG && window.GAUN_CONFIG.settings;
const initialView = (configSettings && Array.isArray(configSettings.mapCenter))
  ? configSettings.mapCenter
  : [37.0375, 37.3135];
const initialZoom = (configSettings && Number.isFinite(configSettings.mapZoom))
  ? configSettings.mapZoom
  : 16;
// Açılış animasyonu: bir konuma odaklanıldıysa (ör. ?loc= derin bağlantısı)
// haritayı geri çekmesin diye iptal edilebilir.
setTimeout(() => {
  if (!App.state.introCancelled) map.setView(initialView, initialZoom, { animate: true, duration: 1.2 });
}, 800);

// ---------------------------------------------------------------------------
// Yardımcılar (mevcut davranış korunur)
// ---------------------------------------------------------------------------
const DEFAULT_MARKER_COLOR = '#3388ff';
function safeColor(color) {
  if (!color) return DEFAULT_MARKER_COLOR;
  const probe = new Option().style;
  probe.color = color;
  return probe.color !== '' ? color : DEFAULT_MARKER_COLOR;
}

function dotIcon(color, selected) {
  const size = selected ? 24 : 16;
  const ring = selected ? `<span class="ring" style="color:${color}"></span>` : '';
  const shadow = selected ? '0 2px 10px rgba(0,0,0,0.35)' : '0 1px 4px rgba(0,0,0,0.3)';
  return L.divIcon({
    className: 'loc-dot',
    html: `<div style="position:relative;width:${size}px;height:${size}px;">${ring}
             <div style="position:absolute;inset:0;background:${color};border-radius:50%;
                         border:${selected ? 3 : 2}px solid #fff;box-shadow:${shadow};"></div>
           </div>`,
    iconSize: [size, size], iconAnchor: [size / 2, size / 2], popupAnchor: [0, -size / 2]
  });
}

function normalizeType(type) {
  if (!type) return 'Diğer';
  return type.trim().toLowerCase().replace(/(^|\s)\w/g, letter => letter.toUpperCase());
}

function distanceMeters(a, b) {
  const toRad = deg => deg * Math.PI / 180;
  const r = 6371000;
  const lat1 = Array.isArray(a) ? a[0] : a.lat;
  const lng1 = Array.isArray(a) ? a[1] : a.lng;
  const lat2 = Array.isArray(b) ? b[0] : b.lat;
  const lng2 = Array.isArray(b) ? b[1] : b.lng;
  const dLat = toRad(lat2 - lat1);
  const dLng = toRad(lng2 - lng1);
  const s1 = Math.sin(dLat / 2);
  const s2 = Math.sin(dLng / 2);
  const h = s1 * s1 + Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) * s2 * s2;
  return 2 * r * Math.asin(Math.sqrt(h));
}

function formatDistance(meters) {
  return meters >= 1000 ? `${(meters / 1000).toFixed(1)} km` : `${Math.round(meters)} m`;
}

function walkingMinutes(meters) {
  return Math.max(1, Math.round(meters / 75));
}

function eventIcon(count) {
  const countHtml = count > 1 ? `<span class="event-count">${Math.min(count, 9)}</span>` : '';
  return L.divIcon({
    className: 'event-loc-dot',
    html: `<div class="event-dot">
      <span class="event-pulse"></span>
      <span class="event-core"><span class="material-symbols-outlined">event_available</span></span>
      ${countHtml}
    </div>`,
    iconSize: [30, 30],
    iconAnchor: [15, 15],
    tooltipAnchor: [0, -17]
  });
}

function todayInIstanbul() {
  try {
    const parts = new Intl.DateTimeFormat('tr-TR-u-ca-gregory', {
      timeZone: 'Europe/Istanbul',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit'
    }).formatToParts(new Date());
    const byType = Object.fromEntries(parts.map(p => [p.type, p.value]));
    if (byType.year && byType.month && byType.day) {
      return `${byType.year}-${byType.month}-${byType.day}`;
    }
  } catch (_) {
    // Çok eski tarayıcılar için yerel saate düş; sunucu today alanı varsa zaten öncelikli.
  }
  return new Date().toISOString().slice(0, 10);
}

function eventLocationKey(value) {
  return App.i18n.foldTr(value)
    .replace(/\b(gaziantep universitesi|gaun|gaü?n|universitesi|universite|kampusu|kampus|merkezinde|sahnesinde)\b/gu, ' ')
    .replace(/\b(ve|ile|de|da|nde|nda)\b/gu, ' ')
    .replace(/[^a-z0-9]+/gu, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function eventTokens(value) {
  const stop = new Set(['salon', 'salonu', 'merkez', 'merkezi', 'bina', 'binasi']);
  return eventLocationKey(value)
    .split(' ')
    .filter(t => t.length >= 3 && !stop.has(t));
}

function eventLocationScore(locationText, entry) {
  const locKey = eventLocationKey(locationText);
  if (!locKey) return 0;
  const itemKey = eventLocationKey([entry.item.name, entry.item.name_en].filter(Boolean).join(' '));
  if (!itemKey) return 0;
  if (locKey === itemKey) return 100;
  if (itemKey.length >= 6 && locKey.includes(itemKey)) return 90;
  if (locKey.length >= 6 && itemKey.includes(locKey)) return 85;

  const locTokens = new Set(eventTokens(locationText));
  const itemTokens = new Set(eventTokens([entry.item.name, entry.item.name_en, entry.type, entry.cat].filter(Boolean).join(' ')));
  if (locTokens.size < 2 || itemTokens.size < 2) return 0;
  const hits = [...locTokens].filter(t => itemTokens.has(t)).length;
  const ratio = hits / Math.min(locTokens.size, itemTokens.size);
  return ratio >= 0.75 ? 70 + hits : 0;
}

function findEventLocation(locationText) {
  if (!App.search?.searchList?.length) return null;
  let best = null;
  let tied = false;
  App.search.searchList.forEach(entry => {
    const score = eventLocationScore(locationText, entry);
    if (score > (best?.score ?? 0)) {
      best = { entry, score };
      tied = false;
    } else if (score > 0 && score === best?.score) {
      tied = true;
    }
  });
  return best && best.score >= 70 && !tied ? best.entry.item : null;
}

function clearTodayEventMarkers() {
  App.state.todayEventMarkers.forEach(marker => map.removeLayer(marker));
  App.state.todayEventMarkers = [];
}

function eventDescription(text) {
  const desc = App.plainText(text);
  return desc.length > 150 ? `${desc.slice(0, 147).trim()}...` : desc;
}

function eventTooltipHtml(events, item) {
  const t = App.i18n.t;
  const title = events.length > 1 ? t('events.todayPlural') : t('events.today');
  const visible = events.slice(0, 3);
  const more = events.length - visible.length;
  return `<div class="event-tip">
    <div class="event-tip-kicker">${App.escapeHtml(title)}</div>
    <div class="event-tip-place">${App.escapeHtml(App.i18n.displayLocationName(item))}</div>
    ${visible.map(ev => {
      const meta = [ev.time, ev.location].filter(Boolean).join(' • ');
      const desc = eventDescription(ev.description);
      return `<div class="event-tip-item">
        <div class="event-tip-title">${App.escapeHtml(ev.title || '')}</div>` +
        (meta ? `<div class="event-tip-meta">${App.escapeHtml(meta)}</div>` : '') +
        (desc ? `<div class="event-tip-desc">${App.escapeHtml(desc)}</div>` : '') +
      `</div>`;
    }).join('')}
    ${more > 0 ? `<div class="event-tip-more">${App.escapeHtml(t('events.more', { count: more }))}</div>` : ''}
  </div>`;
}

function renderTodayEventMarkers() {
  clearTodayEventMarkers();
  const events = App.state.bulletinData?.events;
  if (!Array.isArray(events) || !App.search?.searchList?.length) return;

  const today = App.state.bulletinToday || todayInIstanbul();
  const grouped = new Map();
  events
    .filter(ev => ev && ev.date === today && ev.location)
    .forEach(ev => {
      const item = findEventLocation(ev.location);
      if (!item?.coords) return;
      const key = String(item.id ?? item.name);
      if (!grouped.has(key)) grouped.set(key, { item, events: [] });
      grouped.get(key).events.push(ev);
    });

  grouped.forEach(group => {
    const marker = L.marker(group.item.coords, {
      icon: eventIcon(group.events.length),
      zIndexOffset: 650,
      riseOnHover: true
    })
      .bindTooltip(eventTooltipHtml(group.events, group.item), {
        className: 'event-tooltip',
        direction: 'top',
        opacity: 1,
        sticky: true
      })
      .addTo(map);
    marker.on('click', () => focusItem(group.item));
    App.state.todayEventMarkers.push(marker);
  });
}

// Kök kategori adı -> DB'deki kategori rengi (çocuklar devralır).
const catColorByName = {};

// Bir konum/kategori için görsel rengi çözer.
// Öncelik: kendi rengi > kategorisinin DB rengi > sabit palet > varsayılan.
function resolveColor(item, catName) {
  const c = item.color || catColorByName[catName]
    || categoryColors[catName] || categoryColors[normalizeType(item.type)]
    || categoryColors['default'];
  return safeColor(c);
}

// ---------------------------------------------------------------------------
// Marker yönetimi (mevcut davranış korunur)
// ---------------------------------------------------------------------------
function selectMarker(marker) {
  if (App.state.selectedMarker && App.state.selectedMarker !== marker) {
    App.state.selectedMarker.setIcon(dotIcon(App.state.selectedMarker._color, false));
  }
  marker.setIcon(dotIcon(marker._color, true));
  App.state.selectedMarker = marker;
}

function addMarker(item) {
  const key = item.id ?? item.name;
  if (App.state.markers[key]) { selectMarker(App.state.markers[key]); return App.state.markers[key]; }
  const color = resolveColor(item, item._cat);
  const marker = L.marker(item.coords, { icon: dotIcon(color, false) })
    .bindPopup(App.popup.buildPopup(item), { maxWidth: 320, minWidth: 220 })
    .addTo(map);
  marker._color = color;
  marker._item = item;
  App.state.markers[key] = marker;
  // Doğrudan marker tıklaması da paylaşılabilir URL'yi güncellesin.
  marker.on('click', () => {
    App.state.selectedItem = item;
    selectMarker(marker);
    App.routing.showDirections(item);
    App.ui.updateLocUrl(item);
  });
  return marker;
}

// Bir konuma odaklan: haritayı taşı, marker ekle/seç, popup + yön kartını göster.
function focusItem(item, zoom = 18) {
  if (!item?.coords) return;
  App.state.introCancelled = true; // açılış animasyonu odağı ezmesin
  App.state.selectedItem = item;
  map.setView(item.coords, zoom, { animate: true });
  const marker = addMarker(item);
  selectMarker(marker);
  marker.openPopup();
  App.routing.showDirections(item);
  App.ui.updateLocUrl(item);
  App.ui.closeDrawersMobile();
}

function focusLocation(key) {
  const item = App.search.locationIndex[key];
  if (item) focusItem(item);
}

// ---------------------------------------------------------------------------
// Yakınımdakiler: canlı konuma göre en yakın konumlar (kenar çubuğunda)
// ---------------------------------------------------------------------------
function renderNearby() {
  const section = document.getElementById('nearbySection');
  if (!App.state.userLatLng) { section.classList.add('hidden'); return; }

  const nearest = App.search.searchList
    .map(e => ({ e, d: distanceMeters(App.state.userLatLng, e.item.coords) }))
    .sort((a, b) => a.d - b.d)
    .slice(0, 6);

  document.getElementById('nearbyItems').innerHTML = nearest.map(({ e, d }) => {
    const key = e.item.id ?? e.item.name;
    const color = resolveColor(e.item, e.cat);
    return `<button class="nearby-item w-full text-left flex items-center gap-sm px-sm py-[7px] rounded-xl hover:bg-surface-container-low transition-colors" data-loc="${App.escapeHtml(String(key))}">
      <span class="w-1.5 h-1.5 rounded-full shrink-0" style="background:${color}"></span>
      <span class="flex-1 truncate text-[13px] font-medium text-on-surface">${App.escapeHtml(App.i18n.displayLocationName(e.item))}</span>
      <span class="text-[11px] font-bold text-primary shrink-0">${formatDistance(d)}</span>
    </button>`;
  }).join('');
  section.classList.remove('hidden');
}
document.getElementById('nearbyItems').addEventListener('click', (e) => {
  const btn = e.target.closest('.nearby-item');
  if (btn) focusLocation(btn.dataset.loc);
});

// applyLocale() sonrası: açık kullanıcı/marker popup'larını yeni dile göre tazele.
function refreshUserMarkerPopup() {
  if (App.state.userMarker) App.state.userMarker.setPopupContent(App.i18n.t('geo.youAreHere'));
}
function refreshMarkerPopups() {
  Object.values(App.state.markers ?? {}).forEach(marker => {
    if (marker._item) marker.setPopupContent(App.popup.buildPopup(marker._item));
  });
}

// ---------------------------------------------------------------------------
// Harita kontrolleri
// ---------------------------------------------------------------------------
document.getElementById('zoomIn').addEventListener('click', () => map.zoomIn());
document.getElementById('zoomOut').addEventListener('click', () => map.zoomOut());
document.getElementById('resetBtn').addEventListener('click', () => {
  for (const key in App.state.markers) map.removeLayer(App.state.markers[key]);
  App.state.markers = {}; App.state.selectedMarker = null;
  App.state.selectedItem = null;
  App.state.routeRequestSeq++;
  App.routing.clearRouteLine();
  document.getElementById('directionsCard').classList.add('hidden');
  map.setView(initialView, initialZoom, { animate: true });
});

// ---------------------------------------------------------------------------
// Canlı konum takibi (watchPosition): nabız marker + doğruluk halkası + rota
// ---------------------------------------------------------------------------
const locateBtn = document.getElementById('locateBtn');

const userIcon = L.divIcon({
  className: 'user-loc',
  html: '<div class="user-dot"><span class="pulse"></span><span class="core"></span></div>',
  iconSize: [18, 18], iconAnchor: [9, 9]
});

function onPositionUpdate(pos) {
  const { latitude, longitude, accuracy } = pos.coords;
  App.state.userLatLng = L.latLng(latitude, longitude);

  if (!App.state.userMarker) {
    App.state.userMarker = L.marker(App.state.userLatLng, { icon: userIcon, zIndexOffset: 1000 })
      .addTo(map).bindPopup(App.i18n.t('geo.youAreHere'));
    App.state.accuracyCircle = L.circle(App.state.userLatLng, {
      radius: accuracy, color: '#1a73e8', weight: 1,
      fillColor: '#1a73e8', fillOpacity: 0.08, interactive: false
    }).addTo(map);
  } else {
    App.state.userMarker.setLatLng(App.state.userLatLng);
    App.state.accuracyCircle.setLatLng(App.state.userLatLng).setRadius(accuracy);
  }

  if (!App.state.firstFix) {
    App.state.firstFix = true;
    if (App.state.selectedItem?.coords) {
      map.fitBounds(L.latLngBounds([App.state.userLatLng, App.state.selectedItem.coords]), { padding: [80, 80], maxZoom: 17 });
    } else {
      map.setView(App.state.userLatLng, 17, { animate: true });
    }
  }

  // Hedef seçiliyken: moda göre anlamlı hareket olunca rotayı tazele.
  const refreshMeters = App.routing.ROUTE_MODES[App.state.routeMode]?.refresh ?? 20;
  if (App.state.selectedItem?.coords &&
      (!App.state.lastRouteOrigin || App.state.lastRouteOrigin.distanceTo(App.state.userLatLng) > refreshMeters)) {
    App.routing.updateRouteLine();
  }
  renderNearby();
}

function onPositionError(err) {
  stopTracking();
  const stats = document.getElementById('dirStats');
  const msg = err.code === 1
    ? App.i18n.t('geo.denied')
    : App.i18n.t('geo.unavailable');
  if (App.state.selectedItem?.coords) {
    stats.textContent = msg;
    document.getElementById('directionsCard').classList.remove('hidden');
  } else {
    alert(msg);
  }
}

function startTracking() {
  if (!('geolocation' in navigator)) {
    alert(App.i18n.t('geo.unsupported'));
    return;
  }
  App.state.firstFix = false;
  App.state.watchId = navigator.geolocation.watchPosition(onPositionUpdate, onPositionError, {
    enableHighAccuracy: true, maximumAge: 3000, timeout: 12000
  });
  locateBtn.classList.add('tracking-active');
  locateBtn.title = App.i18n.t('ui.stopTracking');
  App.routing.syncDirGoButton();
}

function stopTracking() {
  if (App.state.watchId !== null) { navigator.geolocation.clearWatch(App.state.watchId); App.state.watchId = null; }
  if (App.state.userMarker)     { map.removeLayer(App.state.userMarker); App.state.userMarker = null; }
  if (App.state.accuracyCircle) { map.removeLayer(App.state.accuracyCircle); App.state.accuracyCircle = null; }
  App.state.userLatLng = null;
  App.state.lastRouteOrigin = null;
  App.state.routeRequestSeq++;
  App.routing.clearRouteLine();
  locateBtn.classList.remove('tracking-active');
  locateBtn.title = App.i18n.t('ui.locate');
  App.routing.syncDirGoButton();
  document.getElementById('nearbySection').classList.add('hidden');
  if (App.state.selectedItem) App.routing.updateRouteLine();
}

locateBtn.addEventListener('click', () => {
  App.state.watchId === null ? startTracking() : stopTracking();
});

window.addEventListener('resize', () => map.invalidateSize());

App.map = {
  map,
  categoryIcons,
  categoryColors,
  initialView,
  initialZoom,
  safeColor,
  dotIcon,
  normalizeType,
  distanceMeters,
  formatDistance,
  walkingMinutes,
  eventLocationKey,
  eventLocationScore,
  findEventLocation,
  clearTodayEventMarkers,
  renderTodayEventMarkers,
  catColorByName,
  resolveColor,
  selectMarker,
  addMarker,
  focusItem,
  focusLocation,
  renderNearby,
  refreshUserMarkerPopup,
  refreshMarkerPopups,
  startTracking,
  stopTracking
};
