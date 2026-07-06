// =============================================================================
// app.js — Bootstrap ve modüller-arası bağlantı noktası. Bu dosya EN SON
// yüklenir: veri fetch'leri (get_locations.php, get_campus_bulletin.php),
// paylaşılabilir konum linki (?loc=), mobil çekmece, dil/tema ilk kurulumu ve
// servis çalışanı kaydı burada yapılır. Diğer modüller App.* üzerinden
// birbirine bağlanır; bu dosya en son çalıştığı için tüm modüller App
// nesnesine eklenmiş olur.
// =============================================================================
window.App = window.App || {};
App.state = App.state || {};
Object.assign(App.state, {
  historySilent: false,
  bulletinToday: null
});

// ---------------------------------------------------------------------------
// Çekmece (mobil) aç/kapa
// ---------------------------------------------------------------------------
const sidebarEl = document.getElementById('sidebar');
const backdrop = document.getElementById('backdrop');

function openSidebar() {
  sidebarEl.classList.remove('translate-x-full');
  backdrop.classList.remove('hidden');
}
function closeDrawersMobile() {
  sidebarEl.classList.add('translate-x-full');
  backdrop.classList.add('hidden');
}
document.getElementById('panelToggle').addEventListener('click', openSidebar);
document.getElementById('sidebarClose').addEventListener('click', closeDrawersMobile);
backdrop.addEventListener('click', closeDrawersMobile);

// ---------------------------------------------------------------------------
// Paylaşılabilir konum bağlantısı (#loc=ID)
// ---------------------------------------------------------------------------
// URL modeli: /?loc=<id> (okurken slug da kabul edilir).
// popstate kaynaklı odaklanmalarda geçmişe yeniden yazılmaz.

function updateLocUrl(item) {
  if (App.state.historySilent) return;
  const key = String(item.id ?? App.search.slugify(item.name));
  const url = new URL(location.href);
  const current = url.searchParams.get('loc');
  url.searchParams.set('loc', key);
  url.hash = ''; // eski #loc biçimini temizle
  // Aynı konuma tekrar odaklanma geçmişi şişirmesin; yeni konum geçmişe girsin
  // (geri/ileri tuşları konumlar arasında gezinir).
  if (current === key) history.replaceState(null, '', url);
  else history.pushState(null, '', url);
}

// Sayfa açılışında ve geri/ileri gezinmede URL'deki konuma odaklan.
function applyLocFromUrl() {
  const params = new URLSearchParams(location.search);
  let raw = params.get('loc');
  if (!raw && location.hash.startsWith('#loc=')) {
    raw = decodeURIComponent(location.hash.slice(5)); // eski format desteği
  }
  const item = App.search.findByLoc(raw);
  if (!item) return false; // geçersiz/eksik loc: normal harita, hata yok
  App.state.historySilent = true;
  try { App.map.focusItem(item); } finally { App.state.historySilent = false; }
  return true;
}
window.addEventListener('popstate', applyLocFromUrl);

// "Linki kopyala": clipboard varsa kullan, yoksa sade prompt fallback.
document.getElementById('dirShare').addEventListener('click', async (e) => {
  const btn = e.currentTarget;
  const iconEl = btn.querySelector('.material-symbols-outlined');
  try {
    if (!navigator.clipboard) throw new Error('clipboard yok');
    await navigator.clipboard.writeText(location.href);
    // "Kopyalandı" geri bildirimi: ikon ✓ olur, ipucu metni değişir.
    iconEl.textContent = 'check';
    btn.title = App.i18n.t('route.copied');
    setTimeout(() => { iconEl.textContent = 'share'; btn.title = App.i18n.t('route.copyLink'); }, 1500);
  } catch (_) {
    prompt(App.i18n.t('route.copyPrompt'), location.href);
  }
});

// Dışarı tıklayınca arama sonuçlarını/asistanı kapat (iki popup da bu dosyada
// koordine edilir çünkü hem search.js hem assistant.js elemanlarına bakar).
document.addEventListener('click', (e) => {
  if (!e.target.closest('#search') && !e.target.closest('#searchResults') && !e.target.closest('#searchClear')) {
    App.search.closeSearchResults();
  }
  if (!e.target.closest('#assistantBox') && !e.target.closest('#assistantToggle')) {
    App.assistant.closeAssistantPopup();
  }
});

// ---------------------------------------------------------------------------
// Bugün Kampüste bülteni — yalnız yerel cache endpoint'i (dış istek yok).
// Endpoint erişilemezse buton hiç gösterilmez; harita etkilenmez.
// ---------------------------------------------------------------------------
const bulletinBtn   = document.getElementById('bulletinBtn');
const bulletinPanel = document.getElementById('bulletinPanel');
const bulletinTabs  = document.getElementById('bulletinTabs');
const bulletinList  = document.getElementById('bulletinList');

const BULLETIN_TABS = [
  ['announcements',   'bulletin.announcements'],
  ['news',            'bulletin.news'],
  ['events',          'bulletin.events'],
  ['important_links', 'bulletin.important_links'],
];
App.state.bulletinData = null;
App.state.bulletinActiveTab = 'announcements';

function renderBulletinList() {
  const rows = App.state.bulletinData?.[App.state.bulletinActiveTab] ?? [];
  if (!rows.length) {
    bulletinList.innerHTML = `<div class="bulletin-empty">${App.escapeHtml(App.i18n.t('bulletin.empty'))}</div>`;
    return;
  }
  const isEvents = App.state.bulletinActiveTab === 'events';
  // Tüm metinler escape edilir; URL'ler sunucuda GAÜN host'larıyla sınırlandı.
  bulletinList.innerHTML = rows.map(r => {
    const text = r.title ?? r.label ?? '';
    // Etkinliklerde okunaklı tarih + saat + yer gösterilir; diğer sekmelerde ham tarih.
    const meta = isEvents
      ? [r.date_label || r.date, r.time, r.location].filter(Boolean).join(' • ')
      : (r.date || '');
    return `<a class="bulletin-item" href="${App.escapeHtml(r.url)}" target="_blank" rel="noopener">
      <div class="bi-title">${App.escapeHtml(text)}</div>` +
      (meta ? `<div class="bi-date">${App.escapeHtml(meta)}</div>` : '') +
    `</a>`;
  }).join('');
}

function renderBulletinTabs() {
  bulletinTabs.innerHTML = '';
  BULLETIN_TABS.forEach(([key, labelKey]) => {
    if (!(key in (App.state.bulletinData ?? {}))) return; // ör. events cache'te yoksa sekme yok
    const b = document.createElement('button');
    b.className = 'bulletin-tab shrink-0 whitespace-nowrap' + (key === App.state.bulletinActiveTab ? ' active' : '');
    b.textContent = App.i18n.t(labelKey);
    b.addEventListener('click', () => {
      App.state.bulletinActiveTab = key;
      bulletinTabs.querySelectorAll('.bulletin-tab').forEach(el => el.classList.remove('active'));
      b.classList.add('active');
      renderBulletinList();
    });
    bulletinTabs.appendChild(b);
  });
}

fetch('get_campus_bulletin.php')
  .then(res => res.json())
  .then(data => {
    App.state.bulletinData = data;
    App.state.bulletinToday = data?.today || null;
    renderBulletinTabs();
    renderBulletinList();
    App.map.renderTodayEventMarkers();
    bulletinBtn.classList.remove('hidden');
  })
  .catch(() => { /* bülten yoksa buton gizli kalır */ });

bulletinBtn.addEventListener('click', () => bulletinPanel.classList.toggle('hidden'));
document.getElementById('bulletinClose').addEventListener('click', () => bulletinPanel.classList.add('hidden'));

App.bulletin = {
  refreshLocale() { renderBulletinTabs(); renderBulletinList(); }
};

// ---------------------------------------------------------------------------
// Dil / tema butonları + ilk kurulum
// ---------------------------------------------------------------------------
document.getElementById('langToggle').addEventListener('click', () => {
  App.i18n.setLanguage(App.state.lang === 'tr' ? 'en' : 'tr');
});
document.getElementById('themeToggle').addEventListener('click', () => {
  App.i18n.applyTheme(App.state.theme === 'dark' ? 'light' : 'dark');
});
App.i18n.applyTheme(App.state.theme);
App.i18n.applyLocale();

// ---------------------------------------------------------------------------
// Veri yükleme
// ---------------------------------------------------------------------------
fetch('get_locations.php')
  .then(res => res.json())
  .then(data => {
    App.state.campusData = data;
    App.search.indexData(data);
    App.search.renderExplorer(data);
    App.search.renderQuickIntents();
    App.search.renderChips(data);
    App.map.renderTodayEventMarkers();

    // Paylaşılan bağlantı (?loc=id|slug, eski #loc dahil) varsa ona,
    // yoksa varsayılan Rektörlük işaretçisine odaklan.
    if (!applyLocFromUrl()) {
      const idari = data.find(d => d.name === 'İdari Binalar');
      const rektorluk = idari?.children?.find(c => c.name === 'Rektörlük Binası');
      if (rektorluk?.coords) { App.map.addMarker(rektorluk); App.routing.showDirections(rektorluk, false); }
    }
  })
  .catch(err => console.error('JSON Hatası:', err));

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(err => console.warn('Service worker kaydedilemedi:', err));
  });
}

App.ui = {
  openSidebar,
  closeDrawersMobile,
  updateLocUrl,
  applyLocFromUrl
};
