// =============================================================================
// assistant.js — Kampüs Asistanı: soru-cevap ile uygun konumu/servisi önerir.
// Arama popup'uyla karşılıklı dışlayan davranışı App.search.enterOverlayMode/
// exitOverlayModeIfClear üzerinden koordine eder (bkz. search.js).
// =============================================================================
window.App = window.App || {};
App.state = App.state || {};
Object.assign(App.state, {
  assistantContext: '',
  assistantPending: null
});

const assistantForm = document.getElementById('assistantForm');
const assistantInput = document.getElementById('assistantInput');
const assistantMessages = document.getElementById('assistantMessages');
const assistantOptions = document.getElementById('assistantOptions');
const assistantBox = document.getElementById('assistantBox');
const assistantToggleBtn = document.getElementById('assistantToggle');

const assistantScenarios = [
  {
    id: 'dining',
    label: 'yemekhane',
    keywords: ['yemek', 'yemekhane', 'food', 'dining', 'cafeteria', 'lunch', 'dinner', 'eat', 'hungry', 'aciktim', 'acim', 'aclik', 'karnim ac', 'bir seyler yemek', 'ne yesem', 'nerede yesem', 'atistirmalik', 'menu', 'menuye', 'oglen', 'ogle yemegi', 'aksam yemegi'],
    terms: ['yemekhane', 'yemek', 'dining', 'food'],
    exclude: ['kafe', 'cafe', 'restaurant', 'restoran'],
    question(text) {
      if (/(menu|menü|liste|kalori|bugun yemekte ne var)/.test(text)) return null;
      if (/(ogrenci|personel|kafe|cafe|kahve|cay|atistirmalik|restaurant|restoran|hepsi|tum secenek|tum yemek)/.test(text)) return null;
      return {
        text: App.i18n.t('assistant.foodQuestion'),
        options: [
          { label: App.i18n.t('assistant.studentDining'), value: 'öğrenci yemekhane student dining cafeteria' },
          { label: App.i18n.t('assistant.staffDining'), value: 'personel yemekhane staff dining cafeteria' },
          { label: App.i18n.t('assistant.cafeSnack'), value: 'kafe cafe kahve cay coffee snack atistirmalik' },
          { label: App.i18n.t('assistant.showAll'), value: 'hepsi tum yemek kafe yemekhane cafe restoran all food dining' }
        ]
      };
    }
  },
  {
    id: 'student-office',
    label: 'öğrenci işleri',
    keywords: ['ogrenci isleri', 'student affairs', 'student certificate', 'registrar', 'transcript', 'ogrenci belgesi', 'transkript', 'diploma', 'kayit', 'harc', 'ders kaydi', 'belge al', 'obs'],
    terms: ['öğrenci işleri', 'ogrenci isleri', 'student affairs', 'registrar', 'öğrenci daire', 'ogrenci daire', 'oidb', 'belge', 'transkript', 'transcript', 'diploma', 'kayıt', 'kayit'],
    ignoreTokens: ['ogrenci']
  },
  {
    id: 'library',
    label: 'kütüphane',
    keywords: ['kutuphane', 'library', 'book', 'study', 'kitap', 'ders calis', 'calismak', 'sessiz alan'],
    terms: ['kütüphane', 'kutuphane', 'library']
  },
  {
    id: 'health',
    label: 'sağlık',
    keywords: ['hastane', 'hospital', 'health', 'doctor', 'emergency', 'saglik', 'hasta', 'acil', 'doktor', 'mediko', 'tip'],
    terms: ['hastane', 'hospital', 'sağlık', 'saglik', 'health', 'mediko', 'tıp', 'tip']
  },
  {
    id: 'faculty',
    label: 'fakülte/bölüm',
    keywords: ['fakulte', 'faculty', 'department', 'classroom', 'dean', 'fakultesi', 'bolum', 'bolumu', 'derslik', 'dekanlik', 'hoca', 'sinif'],
    terms: ['fakülte', 'fakulte', 'faculty', 'bölüm', 'bolum', 'department', 'dekanlık', 'dekanlik'],
    question(text) {
      const words = new Set(text.split(/[^a-z0-9]+/).filter(w => w.length >= 5));
      const hasSpecific = App.search.searchList.some(e =>
        App.i18n.foldTr(e.name).split(/[^a-z0-9]+/).some(w => w.length >= 5 && words.has(w))
      );
      return hasSpecific ? null : {
        text: App.i18n.t('assistant.facultyQuestion'),
        free: true
      };
    }
  },
  {
    id: 'bank',
    label: 'ATM/banka',
    keywords: ['atm', 'bank', 'banka', 'para cek', 'kart', 'cash', 'ziraat', 'halkbank', 'halk bankasi'],
    terms: ['atm', 'banka', 'bank', 'ziraat', 'halkbank', 'halk bankası']
  },
  {
    id: 'sport',
    label: 'spor',
    keywords: ['spor', 'sports', 'fitness', 'pool', 'gym', 'yuzme', 'yuzmek', 'salon', 'kosu'],
    terms: ['spor', 'sports', 'fitness', 'yüzme', 'yuzme']
  },
  {
    id: 'cafe',
    label: 'kafe',
    keywords: ['kafe', 'cafe', 'coffee', 'snack', 'kahve', 'cay', 'oturmak', 'atistirmalik'],
    terms: ['kafe', 'cafe', 'kahve', 'coffee', 'çay', 'cay']
  },
  {
    id: 'admin',
    label: 'idari birim',
    keywords: ['rektorluk', 'rectorate', 'administration', 'idari', 'personel', 'yazi isleri', 'basvuru merkezi', 'kariyer'],
    terms: ['rektörlük', 'rektorluk', 'rectorate', 'idari', 'administration', 'başvuru', 'basvuru', 'kariyer']
  }
];

// GAÜN resmi web ekosistemindeki başlıca öğrenci/personel işlevleri.
// Bu katalog sadece öneri üretir; public sayfa dış siteye veri çekmez.
const assistantServices = [
  {
    id: 'academic-calendar',
    label: 'Akademik Takvim',
    url: 'https://oidb.gaziantep.edu.tr/page.php?url=akademik-takvim-4',
    keywords: ['akademik takvim', 'takvim', 'ders baslangic', 'sinav tarihi', 'final', 'vize', 'yaz okulu', 'kayit yenileme tarihi']
  },
  {
    id: 'obs',
    label: 'OBS Öğrenci Bilgi Sistemi',
    url: 'https://obs.gaziantep.edu.tr/',
    keywords: ['obs', 'ogrenci bilgi sistemi', 'notlar', 'not gor', 'ders kaydi', 'ders secimi', 'transkript gor', 'mezun ogrenci girisi']
  },
  {
    id: 'e-registration',
    label: 'Öğrenci E-Kayıt',
    url: 'https://obs.gaziantep.edu.tr/',
    keywords: ['e kayit', 'ekayit', 'e-kayit', 'universite kaydi', 'yeni kayit', 'ogrenci kaydi']
  },
  {
    id: 'bologna',
    label: 'Bologna / Ders İçeriği',
    url: 'https://obs.gaziantep.edu.tr/oibs/bologna/',
    keywords: ['bologna', 'ders icerigi', 'ders plani', 'akts', 'ects', 'mufredat', 'program bilgi paketi', 'ders bilgi paketi']
  },
  {
    id: 'akbis',
    label: 'AKBİS Akademik Bilgi Sistemi',
    url: 'https://akbis.gaziantep.edu.tr/',
    keywords: ['akbis', 'avesis', 'akademik bilgi', 'akademisyen cv', 'hoca cv', 'yayinlar', 'akademik yayin', 'ozgecmis', 'cv']
  },
  {
    id: 'distance-learning',
    label: 'UZEM Uzaktan Eğitim',
    url: 'http://uzem.gaziantep.edu.tr/',
    keywords: ['uzem', 'uzaktan egitim', 'online ders', 'canli ders', 'ders kaydi', 'uzaktan egitim merkezi']
  },
  {
    id: 'gaunay',
    label: 'GAUNAY Başvuru / İlan Sistemi',
    url: 'https://gaunay.gaziantep.edu.tr/',
    keywords: ['gaunay', 'personel basvuru', 'akademik ilan', 'idari ilan', 'is basvurusu', 'basvuru ilan']
  },
  {
    id: 'student-affairs',
    label: 'Öğrenci İşleri ve Formlar',
    url: 'https://oidb.gaziantep.edu.tr/',
    keywords: ['ogrenci isleri', 'ogrenci belgesi', 'transkript', 'diploma', 'ikinci nusha', 'yatay gecis', 'dikey gecis', 'cap', 'cift anadal', 'yandal', 'ilisik kesme', 'harc', 'odeme iadesi', 'form']
  },
  {
    id: 'meal-menu',
    label: 'Bugünkü Yemek Listesi',
    url: 'https://www.gaziantep.edu.tr/yemek/',
    keywords: ['yemek listesi', 'menu', 'menü', 'bugun yemekte ne var', 'yemekhane menu', 'kalori']
  },
  {
    id: 'library',
    label: 'GAÜN Kütüphane',
    url: 'https://kutuphane.gaziantep.edu.tr/',
    keywords: ['kutuphane', 'kitap', 'odunc', 'veri tabani', 'ekual', 'makale', 'kaynak', 'tez', 'calisma salonu']
  },
  {
    id: 'disabled-unit',
    label: 'Engelsiz Yaşam Birimi',
    url: 'https://eob.gaziantep.edu.tr/',
    keywords: ['engelsiz', 'engelli ogrenci', 'erisilebilir', 'erisim', 'rampa', 'ozel gereksinim', 'danisman', 'engel']
  },
  {
    id: 'erasmus',
    label: 'Erasmus+ / Uluslararası İlişkiler',
    url: 'https://erasmus.gaziantep.edu.tr/',
    keywords: ['erasmus', 'staj hareketliligi', 'ogrenim hareketliligi', 'learning agreement', 'ola', 'ols', 'vize', 'exchange', 'mobility']
  },
  {
    id: 'farabi',
    label: 'Farabi Değişim Programı',
    url: 'https://farabi.gaziantep.edu.tr/',
    keywords: ['farabi', 'degisim programi', 'universiteler arasi degisim']
  },
  {
    id: 'international-students',
    label: 'Uluslararası Öğrenci Ofisi',
    url: 'https://iso.gaziantep.edu.tr/',
    keywords: ['uluslararasi ogrenci', 'international student', 'yabanci ogrenci', 'burs', 'scholarship', 'admission']
  },
  {
    id: 'career',
    label: 'Kariyer Planlama ve Girişimcilik',
    url: 'https://kariyer.gaziantep.edu.tr/',
    keywords: ['kariyer', 'staj', 'is ilani', 'yetenek kapisi', 'girisimcilik', 'mezun', 'cv']
  },
  {
    id: 'student-clubs',
    label: 'Öğrenci Toplulukları / SKS',
    url: 'http://sksdb.gaziantep.edu.tr/',
    keywords: ['topluluk', 'kulup', 'kulüp', 'ogrenci topluluklari', 'sks', 'saglik kultur spor', 'kultur spor', 'etkinlik basvurusu', 'yurt', 'yemekhane']
  },
  {
    id: 'events',
    label: 'Etkinlik Takvimi',
    url: 'https://etkinlik.gaziantep.edu.tr/',
    keywords: ['etkinlik', 'konferans', 'seminer', 'kongre', 'bugun kampuste', 'takvim etkinlik']
  },
  {
    id: 'news',
    label: 'Haber Merkezi',
    url: 'https://habermerkezi.gaziantep.edu.tr/',
    keywords: ['haber', 'duyuru', 'ilan', 'guncel', 'haber merkezi']
  },
  {
    id: 'it',
    label: 'Bilgi İşlem Destek',
    url: 'https://bidb.gaziantep.edu.tr/',
    keywords: ['bilgi islem', 'internet', 'wifi', 'wi-fi', 'sifre', 'mail', 'eposta', 'e posta', 'vpn', 'ariza', 'talep', 'bilgisayar bozuk']
  },
  {
    id: 'personnel-office',
    label: 'Personel Daire Başkanlığı',
    url: 'http://pdb.gaziantep.edu.tr/',
    keywords: ['personel daire', 'personel isleri', 'atama', 'ozluk', 'idari personel', 'akademik personel islemleri']
  },
  {
    id: 'admin-finance',
    label: 'İdari ve Mali İşler',
    url: 'http://imidb.gaziantep.edu.tr/',
    keywords: ['idari mali', 'satinalma', 'ihale', 'mali isler', 'tasit', 'malzeme', 'idari isler']
  },
  {
    id: 'construction-office',
    label: 'Yapı İşleri ve Teknik Daire',
    url: 'http://yapiisleri.gaziantep.edu.tr/',
    keywords: ['yapi isleri', 'teknik daire', 'bakim onarim', 'insaat', 'kampus altyapi', 'ariza teknik']
  },
  {
    id: 'tomer',
    label: 'TÖMER Türkçe Öğretimi',
    url: 'http://tomer.gaziantep.edu.tr/',
    keywords: ['tomer', 'türkçe öğretimi', 'turkce ogretimi', 'yabancilar icin turkce', 'dil kursu']
  },
  {
    id: 'eduroam',
    label: 'Eduroam Ayarları',
    url: 'https://eduroam.gaziantep.edu.tr/',
    keywords: ['eduroam', 'wifi sifre', 'kablosuz', 'internet baglanti', 'kampus wifi']
  },
  {
    id: 'student-mail',
    label: 'Öğrenci Web-Mail',
    url: 'https://mail2.gaziantep.edu.tr/',
    keywords: ['ogrenci mail', 'web mail', 'webmail', 'mail2', 'eposta', 'e posta']
  },
  {
    id: 'e-directory',
    label: 'E-Rehber',
    url: 'https://rehber.gaziantep.edu.tr/',
    keywords: ['rehber', 'telefon', 'dahili', 'personel ara', 'hoca telefonu', 'iletisim']
  },
  {
    id: 'gimer',
    label: 'GİMER Başvuru',
    url: 'https://kys.gantep.edu.tr/',
    keywords: ['gimer', 'basvuru', 'sikayet', 'onerim var', 'talep', 'bilgi edinme']
  }
];

function assistantShow() {
  assistantMessages.classList.remove('hidden');
}

function assistantClearOptions() {
  assistantOptions.classList.add('hidden');
  assistantOptions.innerHTML = '';
}

function assistantAddMessage(text, who = 'bot', item = null, services = []) {
  assistantShow();
  const msg = document.createElement('div');
  msg.className = `assistant-msg ${who}`;
  msg.innerHTML = App.escapeHtml(text);
  if (item) {
    const key = item.id ?? item.name;
    msg.innerHTML += `<div class="assistant-suggestion">
      <span>${App.escapeHtml(App.i18n.displayTaxonomy(item.type || App.i18n.t('assistant.campusLocation')))}</span>
      <button type="button" data-loc="${App.escapeHtml(String(key))}">${App.escapeHtml(App.i18n.t('assistant.openMap'))}</button>
    </div>`;
  }
  if (services.length) {
    msg.innerHTML += `<div class="assistant-links">` + services.map(service => {
      const url = App.safeUrl(service.url);
      if (!url) return '';
      return `<a class="assistant-link" href="${App.escapeHtml(url)}" target="_blank" rel="noopener">
        <span>${App.escapeHtml(service.label)}</span>
        <span class="material-symbols-outlined">open_in_new</span>
      </a>`;
    }).join('') + `</div>`;
  }
  assistantMessages.appendChild(msg);
  assistantMessages.scrollTop = assistantMessages.scrollHeight;
}

function assistantAddLocationList(text, matches) {
  assistantShow();
  const msg = document.createElement('div');
  msg.className = 'assistant-msg bot';
  msg.innerHTML = App.escapeHtml(text);
  msg.innerHTML += `<div class="assistant-location-list">` + matches.map(match => {
    const item = match.e.item;
    const key = item.id ?? item.name;
    return `<button type="button" class="assistant-location-choice" data-loc="${App.escapeHtml(String(key))}">
      <span>${App.escapeHtml(App.i18n.displayLocationName(item))}</span>
      <span class="material-symbols-outlined">location_on</span>
    </button>`;
  }).join('') + `</div>`;
  assistantMessages.appendChild(msg);
  assistantMessages.scrollTop = assistantMessages.scrollHeight;
}

function assistantAsk(question) {
  assistantAddMessage(question.text);
  assistantClearOptions();
  if (question.options?.length) {
    assistantOptions.innerHTML = question.options.map(opt =>
      `<button type="button" class="assistant-option" data-value="${App.escapeHtml(opt.value)}">${App.escapeHtml(opt.label)}</button>`
    ).join('');
    assistantOptions.classList.remove('hidden');
  }
  App.state.assistantPending = question;
}

function assistantDetectScenario(text) {
  let best = null;
  let bestScore = 0;
  assistantScenarios.forEach(s => {
    const score = s.keywords.reduce((sum, k) => sum + (text.includes(App.i18n.foldTr(k)) ? 1 : 0), 0);
    if (score > bestScore) { best = s; bestScore = score; }
  });
  return best;
}

function assistantServiceScore(service, text) {
  return service.keywords.reduce((sum, keyword) => {
    const folded = App.i18n.foldTr(keyword);
    if (!folded) return sum;
    if (text === folded) return sum + 8;
    if (text.includes(folded)) return sum + (folded.includes(' ') ? 5 : 2);
    return sum;
  }, 0);
}

function assistantFindServices(text) {
  return assistantServices
    .map(service => ({ service, score: assistantServiceScore(service, text) }))
    .filter(x => x.score > 0)
    .sort((a, b) => b.score - a.score || a.service.label.localeCompare(b.service.label, 'tr'))
    .slice(0, 3)
    .map(x => x.service);
}

function assistantHasFoodIntent(text) {
  return /(yemek|yemekhane|dining|cafeteria|food|lunch|dinner|eat|hungry|acim|aciktim|aclik|karnim ac|nerede yesem|ne yesem|oglen|ogle|aksam|menu|kahve|cay|kafe|cafe|atistirmalik|kantin|restoran|restaurant|pastane|balikci|kumru)/.test(text);
}

function assistantFoodKind(entry) {
  const name = App.i18n.foldTr([entry.name, entry.item?.name_en].filter(Boolean).join(' '));
  const type = App.i18n.foldTr(entry.type);
  const cat = App.i18n.foldTr(entry.cat);
  const own = `${name} ${type} ${cat}`;
  const isDorm = /(kyk|yurt|dorm|dormitory)/.test(own);
  const isDining = Boolean(entry.item.dining)
    || /(^|\s)yemekhaneler(\s|$)|dining halls?/.test(cat)
    || /(yemekhane|yemekhanesi|dining hall|cafeteria)/.test(name);
  const isCafe = /(kafe|cafe|kahve|coffee|kantin|pastane|patisserie|restoran|restaurant|balikci|fish restaurant|kumru)/.test(own);

  // Yurt kaydındaki amenities "yemekhane" içeriyor diye gerçek yemekhane
  // sayılmaz; yalnız kendi adı/kategorisi/türü yemek yeri olanlar adaydır.
  if (isDorm && !isDining && !isCafe) return '';
  if (isDining && /(personel|staff)/.test(name)) return 'staff-dining';
  if (isDining && /(ogrenci|student)/.test(name)) return 'student-dining';
  if (isDining) return 'dining';
  if (isCafe) return 'cafe';
  return '';
}

function assistantFoodScore(entry, text) {
  const kind = assistantFoodKind(entry);
  if (!kind) return 0;

  const name = App.i18n.foldTr([entry.name, entry.item?.name_en].filter(Boolean).join(' '));
  const type = App.i18n.foldTr(entry.type);
  const cat = App.i18n.foldTr(entry.cat);
  const own = `${name} ${type} ${cat}`;
  const wantsStudent = /(ogrenci|student)/.test(text);
  const wantsStaff = /(personel|staff)/.test(text);
  const wantsCafe = /(kafe|cafe|kahve|coffee|cay|atistirmalik|kantin|pastane|restoran|restaurant|balikci|kumru)/.test(text);
  const wantsMenu = /(menu|menuye|menü|kalori|bugun yemekte ne var)/.test(text);

  let score = 20;
  if (kind.includes('dining')) score += 60;
  if (entry.item.dining) score += 45;
  if (kind === 'student-dining') score += 30;
  if (kind === 'staff-dining') score += 25;
  if (kind === 'cafe') score += 35;

  if (wantsStudent) {
    if (kind === 'student-dining') score += 120;
    else if (kind === 'staff-dining') score -= 80;
    else if (kind === 'cafe') score -= 25;
  }
  if (wantsStaff) {
    if (kind === 'staff-dining') score += 120;
    else if (kind === 'student-dining') score -= 80;
    else if (kind === 'cafe') score -= 25;
  }
  if (wantsCafe) {
    if (kind === 'cafe') score += 110;
    else score -= 35;
  }
  if (wantsMenu) {
    if (kind.includes('dining')) score += 55;
    if (kind === 'cafe') score -= 40;
  }

  ['yemekhane', 'yemekhanesi', 'dining', 'cafeteria', 'food', 'kafe', 'cafe', 'restaurant', 'restoran'].forEach(term => {
    if (text.includes(term) && own.includes(term)) score += 14;
  });

  return Math.max(0, score);
}

function assistantFindFoodTop(text, limit = 6) {
  return App.search.searchList
    .map(e => ({ e, score: assistantFoodScore(e, text) }))
    .filter(x => x.score > 0)
    .sort((a, b) => b.score - a.score || a.e.name.localeCompare(b.e.name, 'tr'))
    .slice(0, limit);
}

function assistantScoreEntry(entry, text, scenario = null) {
  const name = App.i18n.foldTr([entry.name, entry.item?.name_en].filter(Boolean).join(' '));
  const type = App.i18n.foldTr(entry.type);
  const cat = App.i18n.foldTr(entry.cat);
  const hay = entry.searchFold || App.i18n.foldTr(entry.searchText);
  const scenarioTerms = (scenario?.terms ?? []).map(App.i18n.foldTr);
  const ignored = new Set(['icin', 'nere', 'nasil', 'gitmek', 'istiyorum', 'lazim', ...(scenario?.ignoreTokens ?? [])]);
  const tokens = text.split(/[^a-z0-9]+/).filter(t => t.length >= 3 && !ignored.has(t));
  const terms = [...new Set([...scenarioTerms, ...tokens])];
  const excludes = (scenario?.exclude ?? []).map(App.i18n.foldTr);
  if (excludes.some(t => hay.includes(t))) return 0;

  let score = 0;
  terms.forEach(term => {
    if (name === term) score += 40;
    if (name.includes(term)) score += 18;
    if (type.includes(term)) score += 8;
    if (cat.includes(term)) score += 6;
    if (hay.includes(term)) score += 2;
  });
  if (scenario?.id === 'dining' && entry.item.dining) score += 20;
  if (scenario?.id === 'food-all' && entry.item.dining) score += 14;
  if (scenario?.id === 'food-all' && /(kafe|cafe|restaurant|restoran|kantin|kahve|yemekhane)/.test(name + ' ' + type + ' ' + hay)) score += 10;
  if (scenario?.id === 'student-office' && /(ogrenci isleri|ogrenci daire|oidb|belge|transkript|diploma|kayit)/.test(name + ' ' + hay)) score += 12;
  if (scenario?.id === 'dining' && assistantHasFoodIntent(text) && !assistantFoodKind(entry)) score = 0;
  if (scenario?.id === 'food-all' && !assistantFoodKind(entry)) score = 0;
  return score;
}

function assistantFindTop(text, scenario = null, limit = 5) {
  return App.search.searchList
    .map(e => ({ e, score: assistantScoreEntry(e, text, scenario) }))
    .filter(x => x.score > 0)
    .sort((a, b) => b.score - a.score || a.e.name.localeCompare(b.e.name, 'tr'))
    .slice(0, limit);
}

function assistantFindBest(text, scenario = null) {
  return assistantFindTop(text, scenario, 1)[0] ?? null;
}

function assistantStaffScore(staff, item, text) {
  const staffName = App.i18n.foldTr(staff.name);
  const staffTitle = App.i18n.foldTr(staff.title);
  const unit = App.i18n.foldTr(staff.unit);
  const department = App.i18n.foldTr(staff.department);
  const locationName = App.i18n.foldTr([item.name, item.name_en].filter(Boolean).join(' '));
  const ignored = new Set(['hoca', 'hocam', 'nerede', 'nereye', 'konum', 'gitmek', 'istiyorum', 'yol', 'tarifi', 'al', 'bul', 'kim', 'kimdir']);
  const tokens = text.split(/[^a-z0-9]+/).filter(t => t.length >= 3 && !ignored.has(t));
  if (!tokens.length) return 0;

  let score = 0;
  if (text.length >= 5 && (staffName.includes(text) || text.includes(staffName))) score += 100;

  const nameHits = tokens.filter(t => staffName.includes(t));
  if (nameHits.length >= 2) score += 75;
  else if (nameHits.length === 1 && nameHits[0].length >= 5) score += 35;

  const titleHits = tokens.filter(t => staffTitle.includes(t));
  const placeHits = tokens.filter(t => unit.includes(t) || department.includes(t) || locationName.includes(t));
  if (titleHits.length && placeHits.length) score += 60 + titleHits.length * 5 + placeHits.length * 5;
  else if (titleHits.length >= 2 && /(baskani|muduru|dekan|sekreter|profesor|docent|ogretim)/.test(text)) score += 30;

  if (/(hoca|ogretim|prof|doc|dr|baskani|muduru|dekan|personel)/.test(text)) score += 8;
  return score >= 45 ? score : 0;
}

function assistantFindStaff(text, limit = 4) {
  return App.search.searchList
    .flatMap(e => (e.item.staff || []).map(staff => ({
      e,
      staff,
      score: assistantStaffScore(staff, e.item, text)
    })))
    .filter(x => x.score > 0)
    .sort((a, b) => b.score - a.score || a.staff.name.localeCompare(b.staff.name, 'tr'))
    .slice(0, limit);
}

function assistantRecommendStaff(match) {
  const item = match.e.item;
  const staff = match.staff;
  const place = staff.department || staff.unit || App.i18n.displayLocationName(item);
  const services = staff.source_url ? [{ label: App.i18n.t('assistant.staffSource'), url: staff.source_url }] : [];
  assistantAddMessage(App.i18n.t('assistant.staffFound', {
    staff: staff.name,
    title: staff.title,
    place,
    location: App.i18n.displayLocationName(item)
  }), 'bot', item, services);
  App.map.focusItem(item, 18);
  if (App.state.watchId === null) {
    assistantAddMessage(App.i18n.t('assistant.startRouteHint'));
  }
}

function assistantRecommend(match, scenario, services = []) {
  const item = match.e.item;
  const confidence = match.score >= 18 ? App.i18n.t('assistant.confident') : App.i18n.t('assistant.maybe');
  assistantAddMessage(`${confidence}: ${App.i18n.displayLocationName(item)}`, 'bot', item, services);
  App.map.focusItem(item, 18);
  if (App.state.watchId === null) {
    assistantAddMessage(App.i18n.t('assistant.startRouteHint'));
  }
}

function assistantHandle(rawText, silentValue = '') {
  const visibleText = String(rawText ?? '').trim();
  const foldedVisible = App.i18n.foldTr(visibleText);
  const valueText = silentValue ? `${visibleText} ${silentValue}` : visibleText;
  if (!visibleText) return;
  if (!App.search.searchList.length) {
    assistantAddMessage(App.i18n.t('assistant.loading'));
    return;
  }

  assistantAddMessage(visibleText, 'user');
  assistantClearOptions();
  App.state.assistantContext = `${App.state.assistantContext} ${valueText}`.trim();
  const foldedContext = App.i18n.foldTr(App.state.assistantContext);

  if (App.state.assistantPending?.free && foldedVisible.length < 3) {
    assistantAsk(App.state.assistantPending);
    return;
  }
  App.state.assistantPending = null;

  const scenario = assistantDetectScenario(foldedContext);
  const serviceMatches = assistantFindServices(foldedContext);
  const staffMatch = assistantFindStaff(foldedContext, 1)[0] ?? null;
  if (staffMatch) {
    assistantRecommendStaff(staffMatch);
    App.state.assistantContext = '';
    return;
  }

  const question = scenario?.question?.(foldedContext);
  if (question && !silentValue) {
    assistantAsk(question);
    return;
  }

  const wantsFoodOptions = /(hepsi|tum yemek|tum secenek|yemek secenekleri|nerede yesem|ne yesem)/.test(foldedContext)
    && /(yemek|yemekhane|kafe|cafe|acim|aciktim|karnim ac|atistirmalik)/.test(foldedContext);
  if (wantsFoodOptions) {
    const foodMatches = assistantFindFoodTop(foldedContext, 6);
    if (foodMatches.length) {
      assistantAddLocationList(App.i18n.t('assistant.foodOptions'), foodMatches);
      App.state.assistantContext = '';
      return;
    }
  }

  if (assistantHasFoodIntent(foldedContext) && scenario?.id === 'dining') {
    const foodMatch = assistantFindFoodTop(foldedContext, 1)[0] ?? null;
    if (foodMatch) {
      const foodServices = /(menu|menü|kalori|liste|bugun yemekte ne var)/.test(foldedContext) ? serviceMatches : [];
      assistantRecommend(foodMatch, scenario, foodServices);
      App.state.assistantContext = '';
      return;
    }
  }

  const match = assistantFindBest(foldedContext, scenario);
  if (!match) {
    if (serviceMatches.length) {
      assistantAddMessage(App.i18n.t('assistant.serviceLinks'), 'bot', null, serviceMatches);
      App.state.assistantContext = '';
      return;
    }
    assistantAddMessage(App.i18n.t('assistant.notFound'));
    App.state.assistantPending = { text: App.i18n.t('assistant.examples'), free: true };
    return;
  }
  assistantRecommend(match, scenario, serviceMatches);
  App.state.assistantContext = '';
}

assistantForm.addEventListener('submit', (e) => {
  e.preventDefault();
  const text = assistantInput.value;
  assistantInput.value = '';
  assistantHandle(text);
});

assistantOptions.addEventListener('click', (e) => {
  const btn = e.target.closest('.assistant-option');
  if (!btn) return;
  assistantHandle(btn.textContent, btn.dataset.value || btn.textContent);
});

assistantMessages.addEventListener('click', (e) => {
  const btn = e.target.closest('.assistant-suggestion button');
  if (btn) App.map.focusLocation(btn.dataset.loc);
  const choice = e.target.closest('.assistant-location-choice');
  if (choice) App.map.focusLocation(choice.dataset.loc);
});

// ---------------------------------------------------------------------------
// Asistan popup aç/kapa (arama popup'uyla karşılıklı dışlar; bkz. search.js)
// ---------------------------------------------------------------------------
function closeAssistantPopup() {
  assistantBox.classList.add('hidden');
  App.search.exitOverlayModeIfClear();
}
function openAssistantPopup() {
  App.search.closeSearchResults();
  App.search.enterOverlayMode();
  assistantBox.classList.remove('hidden');
  assistantInput.focus();
}
assistantToggleBtn.addEventListener('click', () => {
  assistantBox.classList.contains('hidden') ? openAssistantPopup() : closeAssistantPopup();
});

App.assistant = {
  assistantHandle,
  closeAssistantPopup,
  openAssistantPopup
};
