// =============================================================================
// search.js — Arama indeksi, arama kutusu, hızlı ihtiyaç kısayolları, Keşfet
// paneli (kategori ağacı) ve filtre çipleri. Arama/asistan popup'ları
// birbirini kapattığı için enterOverlayMode/exitOverlayModeIfClear/
// closeSearchResults burada tutulur; assistant.js bunları App.search üzerinden
// çağırır.
// =============================================================================
window.App = window.App || {};
App.state = App.state || {};
Object.assign(App.state, {
  campusData: null,
  activeCats: new Set(),
  activeQuickIntent: null
});

// ---------------------------------------------------------------------------
// İngilizce arama eşanlamlıları
// ---------------------------------------------------------------------------
const SEARCH_ALIAS_RULES = [
  ['akademik', 'academic'],
  ['idari', 'administrative administration'],
  ['bina', 'building'],
  ['binalar', 'buildings'],
  ['merkez', 'center centre'],
  ['merkezler', 'centers centres'],
  ['enstitu', 'institute'],
  ['enstituler', 'institutes'],
  ['yuksekokul', 'school college'],
  ['meslek yuksekokul', 'vocational school college'],
  ['fakulte', 'faculty'],
  ['bolum', 'department'],
  ['kafe', 'cafe coffee'],
  ['hastane', 'hospital health medical'],
  ['saglik', 'health medical'],
  ['tip', 'medicine medical'],
  ['dis hekimligi', 'dentistry dental'],
  ['yemekhane', 'dining hall cafeteria food lunch'],
  ['yemek', 'food dining lunch'],
  ['yurt', 'dorm dormitory residence'],
  ['kutuphane', 'library study books'],
  ['spor', 'sports gym fitness'],
  ['konser', 'concert venue music'],
  ['arge', 'research development r&d'],
  ['arastirma', 'research'],
  ['uluslararasi', 'international'],
  ['degisim', 'exchange mobility'],
  ['program', 'program programme'],
  ['konservatuvar', 'conservatory music'],
  ['yabanci dil', 'foreign languages english'],
  ['etik kurul', 'ethics committee'],
  ['rektorluk', 'rectorate rector administration'],
  ['ogrenci isleri', 'student affairs registrar student office'],
  ['ogrenci', 'student'],
  ['muhendislik', 'engineering'],
  ['bilgisayar', 'computer computing'],
  ['elektrik', 'electrical electric'],
  ['elektronik', 'electronics electronic'],
  ['makine', 'mechanical machinery'],
  ['insaat', 'civil construction'],
  ['gida', 'food'],
  ['endustri', 'industrial industry'],
  ['tekstil', 'textile'],
  ['fizik', 'physics'],
  ['kimya', 'chemistry chemical'],
  ['mimarlik', 'architecture'],
  ['guzel sanat', 'fine arts'],
  ['hukuk', 'law'],
  ['iktisadi', 'economics economic'],
  ['isletme', 'business management'],
  ['egitim', 'education'],
  ['ilahiyat', 'theology'],
  ['fen edebiyat', 'science literature arts sciences'],
  ['turizm', 'tourism'],
  ['ziraat', 'agriculture agricultural bank'],
  ['veteriner', 'veterinary'],
  ['eczacilik', 'pharmacy'],
  ['iletisim', 'communication communications'],
  ['tomer', 'turkish language center'],
  ['erasmus', 'erasmus international exchange'],
  ['farabi', 'farabi exchange'],
  ['otobus', 'bus stop shuttle'],
  ['durak', 'bus stop station'],
  ['carsi', 'bazaar market shops'],
  ['atm', 'atm bank'],
  ['banka', 'bank']
];

const SEARCH_QUERY_STOP_WORDS = new Set([
  'ara',
  'bul',
  'goster',
  'gosterebilir',
  'git',
  'gidecegim',
  'hangi',
  'harita',
  'kisim',
  'kismi',
  'nerde',
  'nerede',
  'neresi',
  'nereye',
  'rota',
  'taraf',
  've',
  'ya',
  'yer',
  'yol'
]);

function englishSearchAliases(...values) {
  const aliases = new Set();
  values.filter(Boolean).forEach(value => {
    const raw = String(value);
    const folded = App.i18n.foldTr(raw);
    const taxonomy = App.i18n.TAXONOMY_LABELS_EN[App.i18n.normalizeNfc(raw)];
    if (taxonomy) aliases.add(taxonomy);
    const location = App.i18n.locationNameEn(raw);
    if (location) aliases.add(location);
    SEARCH_ALIAS_RULES.forEach(([needle, alias]) => {
      if (folded.includes(needle)) aliases.add(alias);
    });
  });
  return [...aliases].join(' ');
}

function splitSearchTerms(value) {
  return App.i18n.foldTr(value)
    .split(/[^a-z0-9&]+/g)
    .map(t => t.trim())
    .filter(t => t.length > 1 && !SEARCH_QUERY_STOP_WORDS.has(t));
}

function singularSearchForms(term) {
  const folded = App.i18n.foldTr(term).trim();
  const forms = new Set(folded ? [folded] : []);
  const endings = [
    'lerden',
    'lardan',
    'lerin',
    'larin',
    'lerle',
    'larla',
    'leri',
    'lari',
    'ler',
    'lar',
    'nin',
    'nun',
    'sin',
    'sun',
    'in',
    'un',
    'si',
    'su'
  ];

  endings.forEach(ending => {
    if (folded.length > ending.length + 2 && folded.endsWith(ending)) {
      forms.add(folded.slice(0, -ending.length));
    }
  });

  if (folded.length > 5 && /[iu]$/.test(folded)) {
    forms.add(folded.slice(0, -1));
  }

  return [...forms].filter(Boolean);
}

function addQueryAliasTerms(value, out) {
  const folded = App.i18n.foldTr(value);
  SEARCH_ALIAS_RULES.forEach(([needle, alias]) => {
    const foldedNeedle = App.i18n.foldTr(needle).trim();
    if (!foldedNeedle || !folded.includes(foldedNeedle)) return;
    out.add(foldedNeedle);
    splitSearchTerms(alias).forEach(term => out.add(term));
    const aliasPhrase = App.i18n.foldTr(alias).replace(/\s+/g, ' ').trim();
    if (aliasPhrase.includes(' ')) out.add(aliasPhrase);
  });
}

function expandSearchTerm(term) {
  const terms = new Set();
  singularSearchForms(term).forEach(form => {
    terms.add(form);
    addQueryAliasTerms(form, terms);
  });
  return [...terms].filter(t => t.length > 1);
}

function prepareSearchQuery(q) {
  const folded = App.i18n.foldTr(q).replace(/\s+/g, ' ').trim();
  const phraseVariants = new Set(folded ? [folded] : []);
  singularSearchForms(folded).forEach(form => phraseVariants.add(form));
  addQueryAliasTerms(folded, phraseVariants);

  const tokenVariants = splitSearchTerms(folded)
    .map(expandSearchTerm)
    .filter(variants => variants.length);

  return {
    folded,
    phraseVariants: [...phraseVariants],
    tokenVariants
  };
}

// ---------------------------------------------------------------------------
// İndeksleme, arama listesi
// ---------------------------------------------------------------------------
const locationIndex = {};
const slugIndex = {};   // slug -> konum (paylaşılabilir okunaklı linkler için)
const searchList = [];

// "Merkez Öğrenci Yemekhanesi" -> "merkez-ogrenci-yemekhanesi"
function slugify(name) {
  const map = { 'ç':'c','ğ':'g','ı':'i','i':'i','ö':'o','ş':'s','ü':'u' };
  return String(name ?? '')
    .toLocaleLowerCase('tr-TR')
    .replace(/[çğıöşü]/g, ch => map[ch])
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

function indexData(list, catName) {
  list.forEach(item => {
    const cat = catName ?? item.name; // kök öğeler = kategori
    item._cat = cat;
    if (catName == null && item.color) App.map.catColorByName[item.name] = item.color;
    if (item.coords) {
      const slug = slugify(item.name);
      // Çakışan slug'lar güvenli tarafta bırakılır (ilk kayıt kazanır).
      if (slug && !(slug in slugIndex)) slugIndex[slug] = item;
    }
    if (item.coords) {
      locationIndex[item.id ?? item.name] = item;
      const searchText = [
        item.name,
        item.name_en,
        item.type,
        cat,
        englishSearchAliases(item.name, item.name_en, item.type, cat),
        item.address,
        item.description_text || App.plainText(item.description),
        item.description_en_text || App.plainText(item.description_en),
        item.facilities,
        item.amenities,
        item.opening_hours,
        ...(item.staff || []).flatMap(s => [
          s.name,
          s.title,
          s.unit,
          s.department
        ])
      ].filter(Boolean).join(' ');
      searchList.push({
        item,
        name: item.name,
        type: item.type,
        cat,
        searchText,
        searchFold: App.i18n.foldTr(searchText)
      });
    }
    if (item.children) indexData(item.children, cat);
  });
}

function findByLoc(raw) {
  const val = String(raw ?? '').trim();
  if (!val || val.length > 120) return null;
  return locationIndex[val] ?? slugIndex[slugify(val)] ?? null;
}

// ---------------------------------------------------------------------------
// Keşfet paneli: açılır kategori bölümleri (lejant + gezinme birleşik)
// ---------------------------------------------------------------------------
const explorerSections = {}; // kategori adı -> bölüm elemanı (çip filtresi için)

// Bir düğümün altındaki koordinatlı tüm konumları düzleştirir.
function flatLocations(node) {
  const out = [];
  (function walk(n) {
    (n.children || []).forEach(ch => {
      if (ch.coords) out.push(ch);
      walk(ch);
    });
  })(node);
  return out;
}

function renderExplorer(data) {
  const wrap = document.getElementById('explorer');
  wrap.innerHTML = '';
  Object.keys(explorerSections).forEach(key => { delete explorerSections[key]; });
  data.forEach(cat => {
    const color = App.map.resolveColor(cat, cat.name);
    const icon = App.map.categoryIcons[cat.name] || App.map.categoryIcons['Diğer'];
    const catLabel = App.i18n.displayTaxonomy(cat.name);
    const locs = flatLocations(cat);
    if (cat.coords) locs.unshift(cat);
    if (!locs.length) return;

    const section = document.createElement('div');
    section.innerHTML = `
      <button class="exp-head w-full flex items-center gap-md px-sm py-md rounded-2xl hover:bg-surface-container-low transition-colors active:scale-[0.99]">
        <span class="w-3.5 h-3.5 rounded-full ring-2 ring-white shadow-sm shrink-0" style="background:${color}"></span>
        <span class="flex-1 text-left font-semibold text-[13.5px] text-on-surface truncate">${icon} ${App.escapeHtml(catLabel)}</span>
        <span class="text-[11px] font-bold text-outline bg-surface-container px-2 py-0.5 rounded-full tabular-nums">${locs.length}</span>
        <span class="material-symbols-outlined text-outline text-[20px] exp-chev">expand_more</span>
      </button>
      <div class="exp-body hidden pl-lg pr-sm pb-sm space-y-0.5">
        ${locs.map(l => {
          const key = l.id ?? l.name;
          return `<button class="exp-item w-full text-left flex items-center gap-sm px-sm py-[7px] rounded-xl hover:bg-surface-container-low transition-colors" data-loc="${App.escapeHtml(String(key))}">
            <span class="w-1.5 h-1.5 rounded-full shrink-0" style="background:${App.map.resolveColor(l, cat.name)}"></span>
            <span class="flex-1 truncate text-[13px] font-medium text-on-surface">${App.escapeHtml(App.i18n.displayLocationName(l))}</span>
            <span class="text-[10px] text-outline shrink-0">${App.escapeHtml(App.i18n.displayTaxonomy(l.type || ''))}</span>
          </button>`;
        }).join('')}
      </div>`;

    const body = section.querySelector('.exp-body');
    const chev = section.querySelector('.exp-chev');
    section.querySelector('.exp-head').addEventListener('click', () => {
      body.classList.toggle('hidden');
      chev.style.transform = body.classList.contains('hidden') ? '' : 'rotate(180deg)';
    });
    body.addEventListener('click', (e) => {
      const btn = e.target.closest('.exp-item');
      if (btn) App.map.focusLocation(btn.dataset.loc);
    });

    wrap.appendChild(section);
    explorerSections[cat.name] = section;
  });
}

// ---------------------------------------------------------------------------
// Filtre çipleri (kök kategorilerden üretilir)
// ---------------------------------------------------------------------------
function renderChips(data) {
  const wrap = document.getElementById('filterChips');
  wrap.innerHTML = '';
  data.forEach(cat => {
    const color = App.map.resolveColor(cat, cat.name);
    const btn = document.createElement('button');
    btn.className = 'flex items-center gap-sm h-9 px-md glass-effect rounded-full border border-on-surface/5 shadow-md shadow-black/5 hover:bg-white transition-all whitespace-nowrap active:scale-95';
    btn.dataset.cat = cat.name;
    btn.innerHTML = `<span class="w-2.5 h-2.5 rounded-full ring-1 ring-black/5" style="background:${color}"></span>
                     <span class="chip-name text-[13px] font-semibold text-on-surface/80">${App.escapeHtml(App.i18n.displayTaxonomy(cat.name))}</span>`;
    if (App.state.activeCats.has(cat.name)) btn.classList.add('chip-active');
    btn.addEventListener('click', () => {
      if (App.state.activeCats.has(cat.name)) { App.state.activeCats.delete(cat.name); btn.classList.remove('chip-active'); }
      else { App.state.activeCats.add(cat.name); btn.classList.add('chip-active'); }
      applyFilter();
    });
    wrap.appendChild(btn);
  });
}

// Aktif çiplere göre Keşfet bölümlerini daralt.
function applyFilter() {
  const show = App.state.activeCats.size === 0;
  for (const [name, el] of Object.entries(explorerSections)) {
    el.style.display = (show || App.state.activeCats.has(name)) ? '' : 'none';
  }
}

// ---------------------------------------------------------------------------
// Hızlı ihtiyaç kısayolları: tek dokunuşla ilgili konumları listele
// ---------------------------------------------------------------------------
const quickIntentWrap = document.getElementById('quickIntents');

const quickIntents = [
  { id: 'dining', icon: 'restaurant', terms: ['yemekhane', 'yemek', 'dining', 'food', 'cafeteria'], exclude: ['kafe', 'cafe', 'restaurant', 'restoran'] },
  { id: 'library', icon: 'local_library', terms: ['kütüphane', 'kutuphane', 'library'] },
  { id: 'student', icon: 'badge', terms: ['öğrenci işleri', 'ogrenci isleri', 'student affairs', 'registrar', 'öğrenci daire', 'ogrenci daire', 'oidb'] },
  { id: 'faculty', icon: 'school', terms: ['fakülte', 'fakulte', 'fakültesi', 'fakultesi', 'faculty', 'department'] },
  { id: 'health', icon: 'local_hospital', terms: ['hastane', 'sağlık', 'saglik', 'health', 'hospital', 'mediko', 'tıp', 'tip'] },
  { id: 'sport', icon: 'fitness_center', terms: ['spor', 'sports', 'fitness', 'yüzme', 'yuzme'] },
  { id: 'cafe', icon: 'local_cafe', terms: ['kafe', 'cafe', 'kahve', 'coffee', 'çay', 'cay'] },
  { id: 'bank', icon: 'account_balance', terms: ['atm', 'banka', 'bank', 'ziraat', 'halkbank', 'halk bankası'] },
  { id: 'transport', icon: 'directions_bus', terms: ['durak', 'stop', 'bus stop', 'otobüs', 'otobus', 'servis'] },
];

function quickIntentLabel(intent) {
  return App.i18n.t(`quick.${intent.id}`);
}

function quickTerms(intent, key = 'terms') {
  return (intent[key] ?? []).map(App.i18n.foldTr).filter(Boolean);
}

function quickIntentScore(entry, intent) {
  const name = App.i18n.foldTr([entry.name, entry.item?.name_en].filter(Boolean).join(' '));
  const type = App.i18n.foldTr(entry.type);
  const cat = App.i18n.foldTr(entry.cat);
  const hay = entry.searchFold || App.i18n.foldTr(entry.searchText);
  const excludes = quickTerms(intent, 'exclude');
  if (excludes.some(t => hay.includes(t))) return 0;

  let score = 0;
  quickTerms(intent).forEach(term => {
    if (name.includes(term)) score += 10;
    if (type.includes(term)) score += 5;
    if (cat.includes(term)) score += 4;
    if (hay.includes(term)) score += 1;
  });
  if (intent.id === 'dining' && entry.item.dining) score += 12;
  return score;
}

function setActiveQuickIntent(id) {
  App.state.activeQuickIntent = id;
  quickIntentWrap.querySelectorAll('.quick-chip').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.intent === id);
  });
}

function clearQuickIntent() {
  setActiveQuickIntent(null);
}

function showQuickIntent(intent, track = true) {
  setActiveQuickIntent(intent.id);
  const label = quickIntentLabel(intent);
  searchInput.value = label;
  if (track) App.analytics.scheduleSearchAnalytics(label);
  const matches = searchList
    .map(e => ({ e, score: quickIntentScore(e, intent) }))
    .filter(x => x.score > 0)
    .sort((a, b) => b.score - a.score || a.e.name.localeCompare(b.e.name, 'tr'))
    .slice(0, 10)
    .map(x => x.e);
  showSearchMatches(matches, App.i18n.t('search.quickEmpty'));
}

function renderQuickIntents() {
  quickIntentWrap.innerHTML = quickIntents.map(intent => `
    <button type="button" class="quick-chip" data-intent="${App.escapeHtml(intent.id)}" title="${App.escapeHtml(quickIntentLabel(intent))}">
      <span class="material-symbols-outlined">${App.escapeHtml(intent.icon)}</span>
      <span>${App.escapeHtml(quickIntentLabel(intent))}</span>
    </button>
  `).join('');

  setActiveQuickIntent(App.state.activeQuickIntent);
  if (quickIntentWrap.dataset.bound === '1') return;
  quickIntentWrap.dataset.bound = '1';
  quickIntentWrap.addEventListener('click', (e) => {
    const btn = e.target.closest('.quick-chip');
    if (!btn) return;
    const intent = quickIntents.find(i => i.id === btn.dataset.intent);
    if (intent) showQuickIntent(intent);
  });
}

// ---------------------------------------------------------------------------
// Arama + Asistan popup: birbirini kapatır ve altındaki hızlı ihtiyaç/filtre
// çip'lerini gizleyerek üst üste binmeyi (görsel "kayma") önler.
// ---------------------------------------------------------------------------
const searchInput = document.getElementById('search');
const searchClearBtn = document.getElementById('searchClear');
const searchResults = document.getElementById('searchResults');
const searchResultsInner = searchResults.querySelector('div');
const filterChipsWrap = document.getElementById('filterChips');

// Popup açıkken hızlı ihtiyaç/filtre çip satırlarını gizler; her iki popup da
// kapandığında geri getirir (böylece arama sonucu/asistan üstlerine binmez).
function enterOverlayMode() {
  quickIntentWrap.classList.add('hidden');
  filterChipsWrap.classList.add('hidden');
}
function exitOverlayModeIfClear() {
  const assistantBox = document.getElementById('assistantBox');
  if (searchResults.classList.contains('hidden') && assistantBox.classList.contains('hidden')) {
    quickIntentWrap.classList.remove('hidden');
    filterChipsWrap.classList.remove('hidden');
  }
}

function closeSearchResults() {
  searchResults.classList.add('hidden');
  exitOverlayModeIfClear();
}

function showSearchMatches(matches, emptyText = App.i18n.t('search.noResults')) {
  App.assistant.closeAssistantPopup();
  enterOverlayMode();
  searchResults.classList.remove('hidden');
  if (!matches.length) {
    searchResultsInner.innerHTML = `<div class="p-md text-center text-outline text-sm">${App.escapeHtml(emptyText)}</div>`;
    return;
  }
  searchResultsInner.innerHTML = matches.map(e => {
    const iconKey = App.map.normalizeType(e.type);
    const icon = App.map.categoryIcons[iconKey] || App.map.categoryIcons[e.cat] || App.map.categoryIcons['Diğer'];
    const color = App.map.resolveColor(e.item, e.cat);
    const key = e.item.id ?? e.item.name;
    return `<div class="search-item px-md py-sm hover:bg-primary-container/15 cursor-pointer flex items-center gap-md border-b border-outline-variant/15 last:border-0 transition-colors" data-loc="${App.escapeHtml(String(key))}">
      <div class="w-10 h-10 rounded-xl flex items-center justify-center text-lg shrink-0" style="background:${color}1e">${icon}</div>
      <div class="flex-1 min-w-0">
        <p class="font-semibold text-[13.5px] text-on-surface truncate">${App.escapeHtml(App.i18n.displayLocationName(e.item))}</p>
        <p class="text-[11.5px] text-outline truncate">${App.escapeHtml(App.i18n.displayTaxonomy(e.cat))}${e.type ? ' • ' + App.escapeHtml(App.i18n.displayTaxonomy(e.type)) : ''}</p>
      </div>
      <span class="material-symbols-outlined text-outline/50 text-[20px]">north_east</span>
    </div>`;
  }).join('');
}

function searchTermScore(term, fields) {
  if (!term || term.length < 2) return 0;
  let best = 0;
  fields.forEach(({ value, weight }) => {
    if (!value.includes(term)) return;
    const exactBoost = value === term ? 12 : 0;
    const prefixBoost = value.startsWith(term) ? 6 : 0;
    best = Math.max(best, weight + exactBoost + prefixBoost);
  });
  return best;
}

function searchMatchScore(entry, prepared) {
  const hay = entry.searchFold || App.i18n.foldTr(entry.searchText);
  const name = App.i18n.foldTr([entry.name, entry.item?.name_en].filter(Boolean).join(' '));
  const type = App.i18n.foldTr(entry.type);
  const cat = App.i18n.foldTr(entry.cat);
  const fields = [
    { value: name, weight: 36 },
    { value: type, weight: 28 },
    { value: cat, weight: 22 },
    { value: hay, weight: 8 }
  ];

  let score = hay.includes(prepared.folded) ? 120 : 0;
  prepared.phraseVariants.forEach(term => {
    if (term !== prepared.folded) score += searchTermScore(term, fields);
  });

  let tokenHits = 0;
  prepared.tokenVariants.forEach(variants => {
    const best = variants.reduce((max, term) => Math.max(max, searchTermScore(term, fields)), 0);
    if (best > 0) {
      tokenHits += 1;
      score += best;
    }
  });

  const requiredHits = prepared.tokenVariants.length <= 2
    ? prepared.tokenVariants.length
    : Math.ceil(prepared.tokenVariants.length * 0.75);
  if (prepared.tokenVariants.length && tokenHits < requiredHits && score < 120) return 0;
  return score;
}

function runSearch(q) {
  const prepared = prepareSearchQuery(q);
  if (!prepared.folded) { App.analytics.scheduleSearchAnalytics(''); closeSearchResults(); return; }
  App.analytics.scheduleSearchAnalytics(q);
  const pool = App.state.activeCats.size ? searchList.filter(e => App.state.activeCats.has(e.cat)) : searchList;
  const matches = pool
    .map(e => ({ e, score: searchMatchScore(e, prepared) }))
    .filter(x => x.score > 0)
    .sort((a, b) => b.score - a.score || a.e.name.localeCompare(b.e.name, 'tr'))
    .slice(0, 8)
    .map(x => x.e);
  showSearchMatches(matches);
}

searchInput.addEventListener('input', (e) => {
  clearQuickIntent();
  searchClearBtn.classList.toggle('hidden', !e.target.value);
  runSearch(e.target.value);
});
searchInput.addEventListener('focus', (e) => { if (e.target.value) runSearch(e.target.value); });
searchClearBtn.addEventListener('click', () => {
  searchInput.value = '';
  searchClearBtn.classList.add('hidden');
  clearQuickIntent();
  closeSearchResults();
  searchInput.focus();
});
searchResultsInner.addEventListener('click', (e) => {
  const row = e.target.closest('.search-item');
  if (!row) return;
  App.map.focusLocation(row.dataset.loc);
  closeSearchResults();
});

App.search = {
  locationIndex,
  slugIndex,
  searchList,
  slugify,
  indexData,
  findByLoc,
  renderExplorer,
  renderChips,
  applyFilter,
  quickIntents,
  quickIntentLabel,
  renderQuickIntents,
  showQuickIntent,
  enterOverlayMode,
  exitOverlayModeIfClear,
  closeSearchResults
};
