// =============================================================================
// popup.js — Leaflet marker popup içeriği ve güvenlik yardımcıları.
// escapeHtml / safeUrl / safeImageUrl burada tanımlanır ve App kök nesnesine
// (App.escapeHtml, App.safeUrl, App.safeImageUrl) asılır çünkü hemen hemen her
// modül (search, map, assistant) kullanıcıdan/DB'den gelen metni HTML'e
// basmadan önce bunlardan geçirir — kaybolmaması gereken güvenlik davranışı.
// =============================================================================
window.App = window.App || {};
App.state = App.state || {};

function safeUrl(url) {
  const s = String(url ?? '').trim();
  return /^https?:\/\//i.test(s) ? s : '';
}

function safeImageUrl(url) {
  const s = String(url ?? '').trim();
  if (/^https?:\/\//i.test(s)) return s;
  const path = s.replace(/^\/+/, '');
  return /^uploads\/locations\/[A-Za-z0-9._-]+\.(jpe?g|png|webp)$/i.test(path) ? path : '';
}

function escapeHtml(str) {
  return String(str ?? '').replace(/[&<>"']/g, c => (
    { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]
  ));
}

function plainText(str) {
  return String(str ?? '')
    .replace(/<[^>]*>/g, ' ')
    .replace(/\b(Web Sayfası|Web Sitesi|Yol Tarifi Al|Google Haritalar'?da)\b/giu, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

function displayDescriptionText(item) {
  if (App.state.lang === 'en') {
    const english = item.description_en_text || plainText(item.description_en);
    if (english) return english;
  }
  return item.description_text || plainText(item.description);
}

// ---------------------------------------------------------------------------
// Popup içeriği
// ---------------------------------------------------------------------------
function buildPopup(item) {
  const t = App.i18n.t;
  const displayLocationName = App.i18n.displayLocationName;
  const displayTaxonomy = App.i18n.displayTaxonomy;

  let html = `<div class="popup-card">`;
  const img = safeImageUrl(item.image_url);
  const itemLabel = displayLocationName(item);
  if (img) html += `<img class="popup-img" src="${escapeHtml(img)}" alt="${escapeHtml(itemLabel)}" loading="lazy" onerror="this.style.display='none'">`;
  html += `<div class="popup-title">${escapeHtml(itemLabel)}</div>`;
  if (item.type) html += `<div class="popup-type">${escapeHtml(displayTaxonomy(item.type))}</div>`;
  if (item.rating) {
    const r = Math.max(0, Math.min(5, Math.round(item.rating)));
    html += `<div class="popup-rating">${'★'.repeat(r)}${'☆'.repeat(5 - r)}<span>${escapeHtml(String(item.rating))}</span></div>`;
  }
  const rows = [];
  if (item.address)       rows.push(`📍 ${escapeHtml(item.address)}`);
  if (item.phone)         rows.push(`📞 ${escapeHtml(item.phone)}`);
  if (item.opening_hours) rows.push(`🕒 ${escapeHtml(item.opening_hours)}`);
  if (item.capacity)      rows.push(`👥 ${escapeHtml(t('popup.capacity', { value: item.capacity }))}`);
  if (item.rooms)         rows.push(`🚪 ${escapeHtml(t('popup.rooms', { value: item.rooms }))}`);
  if (item.facilities)    rows.push(`🏢 ${escapeHtml(item.facilities)}`);
  if (item.amenities)     rows.push(`✨ ${escapeHtml(item.amenities)}`);
  if (item.manager_name)  rows.push(`👤 ${escapeHtml(item.manager_name)}`);
  if (rows.length) html += `<div class="popup-info">${rows.join('<br>')}</div>`;

  // Bugünkü yemek menüsü (yalnız yemekhane konumlarında sunucu ekler).
  // Tüm metinler escape edilir; menü yoksa sade fallback gösterilir.
  if (item.dining) {
    const d = item.dining;
    let body;
    if (Array.isArray(d.items) && d.items.length) {
      body = '<ul>' + d.items.map(it =>
        `<li><span>${escapeHtml(it.name)}</span>` +
        (it.kcal ? `<span class="kcal">${escapeHtml(String(it.kcal))} kcal</span>` : '') +
        `</li>`
      ).join('') + '</ul>';
    } else {
      body = `<div class="empty">${escapeHtml(t('popup.menuUnavailable'))}</div>`;
    }
    const srcUrl = safeUrl(d.source_url);
    const monthlyUrl = safeUrl(d.monthly_url);
    const total = d.calories ? escapeHtml(t('popup.totalCalories', { value: d.calories })) : '';
    html += `<div class="popup-menu">
      <div class="popup-menu-title"><span>🍽️ ${escapeHtml(t('popup.todayMenu'))}</span>` +
      (d.date_label ? `<span class="date">${escapeHtml(d.date_label)}</span>` : '') +
      `</div>${body}
      <div class="menu-foot">
        <span>${escapeHtml(t('popup.source'))}: ${srcUrl ? `<a href="${escapeHtml(srcUrl)}" target="_blank" rel="noopener">GAÜN</a>` : 'GAÜN'}${total}</span>` +
      (monthlyUrl ? `<a href="${escapeHtml(monthlyUrl)}" target="_blank" rel="noopener">${escapeHtml(t('popup.monthlyList'))}</a>` : '') +
      `</div>
    </div>`;
  }

  const kids = item.children || [];
  if (kids.length) {
    const links = kids.map(c =>
      `<a href="#" class="popup-dept" data-loc="${escapeHtml(String(c.id ?? c.name))}">${escapeHtml(displayLocationName(c))}</a>`
    ).join('');
    html += `<div class="popup-depts"><b>${escapeHtml(t('popup.departments'))}</b>${links}</div>`;
  }
  const desc = displayDescriptionText(item);
  if (desc) html += `<div class="popup-desc">${escapeHtml(desc)}</div>`;
  const web = safeUrl(item.link);
  if (web || item.coords) {
    html += `<div class="popup-actions">`;
    if (web)   html += `<a class="web" href="${escapeHtml(web)}" target="_blank" rel="noopener">🌐 ${escapeHtml(t('popup.website'))}</a>`;
    if (item.coords) html += `<a class="route popup-route-map" href="#" data-loc="${escapeHtml(String(item.id ?? item.name))}">🧭 ${escapeHtml(t('popup.routeOnMap'))}</a>`;
    html += `</div>`;
  }
  html += `</div>`;
  return html;
}

// Popup içi bölüm linkleri (tek delege dinleyici). Bu callback yalnızca
// kullanıcı tıkladığında çalışır (sayfa tamamen yüklendikten çok sonra),
// bu yüzden App.map / App.analytics / App.routing henüz burada tanımlı
// olmasa bile (script sırasına göre daha sonra yükleniyor olsalar da) sorun
// olmaz.
document.addEventListener('click', (e) => {
  const link = e.target.closest('.popup-dept');
  if (link) { e.preventDefault(); App.map.focusLocation(link.dataset.loc); }
  const route = e.target.closest('.popup-route-map');
  if (route) {
    e.preventDefault();
    App.map.focusLocation(route.dataset.loc);
    App.analytics.trackRouteRequest(App.state.selectedItem);
    if (App.state.watchId === null) App.map.startTracking();
    else App.routing.updateRouteLine();
  }
});

App.popup = { buildPopup };
App.escapeHtml = escapeHtml;
App.safeUrl = safeUrl;
App.safeImageUrl = safeImageUrl;
App.plainText = plainText;
App.displayDescriptionText = displayDescriptionText;
