// =============================================================================
// i18n.js — Dil (TR/EN), tema ve metin çevirisi.
// Diğer modüller App.i18n.t / App.i18n.displayLocationName / App.i18n.foldTr
// gibi fonksiyonlarla bu modüle bağlanır. Paylaşılan mutable durum (aktif dil/
// tema) App.state üzerinde tutulur; başka modüller kendi global değişkenlerini
// tekrar tanımlamak yerine buradan okur/yazar.
// =============================================================================
window.App = window.App || {};
App.state = App.state || {};
App.state.lang = document.documentElement.lang === 'en' ? 'en' : 'tr';
App.state.theme = document.documentElement.classList.contains('dark') ? 'dark' : 'light';

// API'den fetch+JSON.parse ile gelen bazı Türkçe metinler (özellikle "İ"
// geçenler) NFD (ayrıştırılmış: I + ayrı nokta işareti) biçiminde gelebiliyor,
// oysa bu dosyadaki sözlük anahtarları NFC (birleşik) yazılıyor. NFC/NFD baytça
// farklı olduğundan tam-eşleşme sözlük aramaları (displayTaxonomy,
// locationNameEn) sessizce başarısız oluyordu. Tüm karşılaştırmaları NFC'ye
// sabitleyerek kaynak ne olursa olsun (API, CSV import, admin girişi) tutarlı
// eşleşme garanti edilir.
function normalizeNfc(s) {
  return String(s ?? '').normalize('NFC');
}

function trLower(s) {
  return String(s ?? '').toLocaleLowerCase('tr-TR');
}

function foldTr(s) {
  return trLower(s)
    .replace(/[ç]/g, 'c')
    .replace(/[ğ]/g, 'g')
    .replace(/[ı]/g, 'i')
    .replace(/[ö]/g, 'o')
    .replace(/[ş]/g, 's')
    .replace(/[ü]/g, 'u')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '');
}

function lowerUi(text) {
  return App.state.lang === 'tr' ? text.toLocaleLowerCase('tr-TR') : text.toLocaleLowerCase('en-US');
}

const I18N = {
  tr: {
    'app.title': 'GAÜN Kampüs Haritası - Yol Bulma',
    'ui.menu': 'Menü',
    'ui.searchPlaceholder': 'Bina, fakülte veya yer arayın...',
    'ui.clearSearch': 'Aramayı temizle',
    'ui.assistant': 'Kampüs Asistanı',
    'ui.assistantPlaceholder': 'Kampüs asistanına sor: öğrenci belgesi alacağım...',
    'ui.ask': 'Sor',
    'ui.reset': 'İşaretçileri temizle',
    'ui.locate': 'Konumum',
    'ui.stopTracking': 'Canlı takibi durdur',
    'ui.todayCampus': 'Bugün Kampüste',
    'ui.bulletinSource': 'Kaynak: GAÜN resmi siteleri • önbellekten sunulur',
    'ui.nearby': 'Yakınımdakiler',
    'ui.explore': 'Keşfet',
    'ui.sidebarKicker': 'Kampüs Rehberi',
    'ui.sidebarInfo': 'Renkler kategorileri gösterir. Kategoriye dokunup konum seçin; arama ve üstteki filtrelerle daraltın.',
    'ui.feedback': 'Görüş ve Öneri',
    'ui.feedbackHint': 'Geri bildiriminizi e-posta ile iletin',
    'ui.feedbackSubject': 'GAÜN Kampüs Haritası — Görüş ve Öneri',
    'ui.langTitle': 'English',
    'ui.themeDark': 'Koyu tema',
    'ui.themeLight': 'Açık tema',
    'search.noResults': 'Sonuç bulunamadı',
    'search.quickEmpty': 'Bu kısayolda uygun yer bulunamadı',
    'route.type': 'Rota türü',
    'route.walk': 'Yürüme',
    'route.drive': 'Araç',
    'route.useLocation': 'Konumumu Kullan',
    'route.live': 'Canlı Rota Açık',
    'route.needsLocation': '{mode} rotası için konumunuzu açın; rota ve tahmini süre bu haritada gösterilir.',
    'route.calculating': '{mode} rotası hesaplanıyor...',
    'route.approx': 'Yaklaşık {eta} (rota servisi yoksa kuş uçuşu)',
    'route.serviceUnavailable': 'Rota servisine ulaşılamadı{reason}; yaklaşık {eta} kuş uçuşu gösteriliyor.',
    'route.result': '{mode} rotası {eta}',
    'route.invalidResponse': 'geçersiz yanıt',
    'route.timeout': 'zaman aşımı',
    'route.networkError': 'ağ hatası',
    'route.campusLocation': 'Kampüs konumu',
    'route.copyLink': 'Linki kopyala',
    'route.copied': 'Kopyalandı',
    'route.copyPrompt': 'Bağlantıyı kopyalayın:',
    'geo.youAreHere': 'Buradasınız',
    'geo.unsupported': 'Tarayıcınız konum servislerini desteklemiyor.',
    'geo.denied': 'Konum izni verilmedi. Harita üzerinde canlı rota için konum izni gerekir.',
    'geo.unavailable': 'Konumunuza şu an erişilemiyor. Harita üzerinde rota çizmek için tekrar deneyin.',
    'popup.capacity': 'Kapasite: {value}',
    'popup.rooms': '{value} oda',
    'popup.todayMenu': 'Bugünkü Menü',
    'popup.menuUnavailable': 'Menü bilgisi şu an yok.',
    'popup.totalCalories': ' • Toplam {value} kcal',
    'popup.source': 'Kaynak',
    'popup.monthlyList': 'Aylık liste →',
    'popup.departments': 'Bölümler / Birimler',
    'popup.website': 'Web Sitesi',
    'popup.routeOnMap': 'Haritada Rota',
    'bulletin.empty': 'Şu an gösterilecek kayıt yok.',
    'bulletin.announcements': 'Duyurular',
    'bulletin.news': 'Haberler',
    'bulletin.events': 'Etkinlikler',
    'bulletin.important_links': 'Bağlantılar',
    'events.today': 'Bugünkü Etkinlik',
    'events.todayPlural': 'Bugünkü Etkinlikler',
    'events.more': '+{count} etkinlik daha',
    'quick.dining': 'Yemekhane',
    'quick.library': 'Kütüphane',
    'quick.student': 'Öğrenci İşleri',
    'quick.faculty': 'Fakülteler',
    'quick.health': 'Sağlık',
    'quick.sport': 'Spor',
    'quick.cafe': 'Kafe',
    'quick.bank': 'ATM/Banka',
    'quick.transport': 'Durak',
    'assistant.openMap': 'Haritada aç',
    'assistant.campusLocation': 'Kampüs konumu',
    'assistant.loading': 'Konum verileri henüz yükleniyor, birkaç saniye sonra tekrar deneyin.',
    'assistant.startRouteHint': 'Yön kartındaki “Konumumu Kullan” düğmesiyle canlı rotayı başlatabilirsiniz.',
    'assistant.staffFound': '{staff} ({title}) için {place} kaydını buldum. {location} konumunu açıyorum.',
    'assistant.staffSource': 'Resmi personel kaynağı',
    'assistant.confident': 'Aradığınız yer burası gibi görünüyor',
    'assistant.maybe': 'En yakın eşleşme bu olabilir',
    'assistant.foodOptions': 'Bunlar uygun yemek ve kafe seçenekleri. Birini seçince haritada açarım:',
    'assistant.serviceLinks': 'Bu işlem için en uygun resmi GAÜN bağlantıları:',
    'assistant.notFound': 'Buna uygun kayıtlı bir konum bulamadım. Bir bina, fakülte, bölüm veya işlem adıyla biraz daha net yazabilir misiniz?',
    'assistant.examples': 'Örn: öğrenci belgesi, yemek, kütüphane, mühendislik, hastane',
    'assistant.foodQuestion': 'Yemek için nasıl bir yer arıyorsun?',
    'assistant.studentDining': 'Öğrenci Yemekhanesi',
    'assistant.staffDining': 'Personel Yemekhanesi',
    'assistant.cafeSnack': 'Kafe / Atıştırmalık',
    'assistant.showAll': 'Hepsini göster',
    'assistant.facultyQuestion': 'Hangi fakülte, bölüm veya bina adı? Örn: mühendislik, bilgisayar, tıp'
  },
  en: {
    'app.title': 'GAUN Campus Map - Wayfinding',
    'ui.menu': 'Menu',
    'ui.searchPlaceholder': 'Search for a building, faculty, or place...',
    'ui.clearSearch': 'Clear search',
    'ui.assistant': 'Campus Assistant',
    'ui.assistantPlaceholder': 'Ask the campus assistant: I need a student certificate...',
    'ui.ask': 'Ask',
    'ui.reset': 'Clear markers',
    'ui.locate': 'My location',
    'ui.stopTracking': 'Stop live tracking',
    'ui.todayCampus': 'Today on Campus',
    'ui.bulletinSource': 'Source: official GAUN websites • served from cache',
    'ui.nearby': 'Nearby',
    'ui.explore': 'Explore',
    'ui.sidebarKicker': 'Campus Guide',
    'ui.sidebarInfo': 'Colors show categories. Tap a category and choose a location; narrow results with search and filters above.',
    'ui.feedback': 'Feedback & Suggestions',
    'ui.feedbackHint': 'Send us your feedback by email',
    'ui.feedbackSubject': 'GAUN Campus Map — Feedback & Suggestions',
    'ui.langTitle': 'Türkçe',
    'ui.themeDark': 'Dark mode',
    'ui.themeLight': 'Light mode',
    'search.noResults': 'No results found',
    'search.quickEmpty': 'No matching place for this shortcut',
    'route.type': 'Route type',
    'route.walk': 'Walking',
    'route.drive': 'Driving',
    'route.useLocation': 'Use My Location',
    'route.live': 'Live Route On',
    'route.needsLocation': 'Turn on your location for the {mode} route; the route and ETA will appear on this map.',
    'route.calculating': 'Calculating {mode} route...',
    'route.approx': 'Approx. {eta} (straight-line fallback if routing is unavailable)',
    'route.serviceUnavailable': 'Routing service is unavailable{reason}; showing approx. {eta} as a straight-line fallback.',
    'route.result': '{mode} route {eta}',
    'route.invalidResponse': 'invalid response',
    'route.timeout': 'timeout',
    'route.networkError': 'network error',
    'route.campusLocation': 'Campus location',
    'route.copyLink': 'Copy link',
    'route.copied': 'Copied',
    'route.copyPrompt': 'Copy this link:',
    'geo.youAreHere': 'You are here',
    'geo.unsupported': 'Your browser does not support location services.',
    'geo.denied': 'Location permission was denied. Live routing on the map requires location access.',
    'geo.unavailable': 'Your location is currently unavailable. Try again to draw a route on the map.',
    'popup.capacity': 'Capacity: {value}',
    'popup.rooms': '{value} rooms',
    'popup.todayMenu': "Today's Menu",
    'popup.menuUnavailable': 'Menu information is not available right now.',
    'popup.totalCalories': ' • Total {value} kcal',
    'popup.source': 'Source',
    'popup.monthlyList': 'Monthly list →',
    'popup.departments': 'Departments / Units',
    'popup.website': 'Website',
    'popup.routeOnMap': 'Route on Map',
    'bulletin.empty': 'No items to show right now.',
    'bulletin.announcements': 'Announcements',
    'bulletin.news': 'News',
    'bulletin.events': 'Events',
    'bulletin.important_links': 'Links',
    'events.today': "Today's Event",
    'events.todayPlural': "Today's Events",
    'events.more': '+{count} more events',
    'quick.dining': 'Dining',
    'quick.library': 'Library',
    'quick.student': 'Student Affairs',
    'quick.faculty': 'Faculties',
    'quick.health': 'Health',
    'quick.sport': 'Sports',
    'quick.cafe': 'Cafe',
    'quick.bank': 'ATM/Bank',
    'quick.transport': 'Stops',
    'assistant.openMap': 'Open on map',
    'assistant.campusLocation': 'Campus location',
    'assistant.loading': 'Location data is still loading. Please try again in a few seconds.',
    'assistant.startRouteHint': 'Use the “Use My Location” button on the route card to start live routing.',
    'assistant.staffFound': 'I found {place} for {staff} ({title}). Opening {location} on the map.',
    'assistant.staffSource': 'Official staff source',
    'assistant.confident': 'This looks like the place you need',
    'assistant.maybe': 'This may be the closest match',
    'assistant.foodOptions': 'Here are suitable dining and cafe options. Choose one and I will open it on the map:',
    'assistant.serviceLinks': 'The most relevant official GAUN links for this request:',
    'assistant.notFound': 'I could not find a matching saved location. Can you be more specific with a building, faculty, department, or service name?',
    'assistant.examples': 'Example: student certificate, food, library, engineering, hospital',
    'assistant.foodQuestion': 'What kind of place are you looking for?',
    'assistant.studentDining': 'Student Dining Hall',
    'assistant.staffDining': 'Staff Dining Hall',
    'assistant.cafeSnack': 'Cafe / Snack',
    'assistant.showAll': 'Show all',
    'assistant.facultyQuestion': 'Which faculty, department, or building? Example: engineering, computer, medicine'
  }
};

function t(key, vars = {}) {
  const template = I18N[App.state.lang]?.[key] ?? I18N.tr[key] ?? key;
  return template.replace(/\{(\w+)\}/g, (_, name) => String(vars[name] ?? ''));
}

const TAXONOMY_LABELS_EN = {
  'Akademik Binalar': 'Academic Buildings',
  'İdari Binalar': 'Administrative Buildings',
  'Merkezler': 'Centers',
  'Enstitüler': 'Institutes',
  'Yüksekokullar': 'Schools',
  'Meslek Yüksekokulları': 'Vocational Schools',
  'Kafeler': 'Cafes',
  'Hastaneler': 'Hospitals',
  'Yemekhaneler': 'Dining Halls',
  'Yurtlar': 'Dormitories',
  'Kütüphaneler': 'Libraries',
  'Spor Binaları': 'Sports Facilities',
  'Konser Alanları': 'Concert Venues',
  'ARGE Binaları': 'R&D Buildings',
  'Uluslararası Ofisler': 'International Offices',
  'Değişim Programları': 'Exchange Programs',
  'Konservatuvar': 'Conservatory',
  'Yabancı Diller': 'Foreign Languages',
  'Etik Kurullar': 'Ethics Committees',
  'Çarşı': 'Bazaar',
  'Kategori': 'Category',
  'Bina': 'Building',
  'Bölüm': 'Department',
  'Fakülte': 'Faculty',
  'İdari Bina': 'Administrative Building',
  'Merkez': 'Center',
  'Enstitü': 'Institute',
  'Yüksekokul': 'School',
  'Meslek Yüksekokulu': 'Vocational School',
  'Kafe': 'Cafe',
  'Hastane': 'Hospital',
  'Kütüphane': 'Library',
  'Spor Binası': 'Sports Facility',
  'Konser Alanı': 'Concert Venue',
  'Değişim Programı': 'Exchange Program',
  'Kyk Kız Öğrenci Yurdu': "KYK Women's Dormitory",
  'Diğer': 'Other'
};

const LOCATION_LABELS_EN = {
  'Rektörlük Binası': 'Rectorate Building',
  'Sosyal Bilimler Enstitüsü': 'Institute of Social Sciences',
  'Sağlık Bilimleri Enstitüsü': 'Institute of Health Sciences',
  'Fen Bilimleri Enstitüsü': 'Institute of Natural Sciences',
  'Eğitim Bilimleri Enstitüsü': 'Institute of Educational Sciences',
  'Göç Enstitüsü': 'Institute of Migration',
  'Mühendislik Fakültesi': 'Faculty of Engineering',
  'Tıp Fakültesi': 'Faculty of Medicine',
  'Diş Hekimliği Fakültesi': 'Faculty of Dentistry',
  'Hukuk Fakültesi': 'Faculty of Law',
  'Mimarlık Fakültesi': 'Faculty of Architecture',
  'Güzel Sanatlar Fakültesi': 'Faculty of Fine Arts',
  'Fen Edebiyat Fakültesi': 'Faculty of Arts and Sciences',
  'Fen-Edebiyat Fakültesi': 'Faculty of Arts and Sciences',
  'İktisadi ve İdari Bilimler Fakültesi': 'Faculty of Economics and Administrative Sciences',
  'İktisadi İdari Bilimler Fakültesi': 'Faculty of Economics and Administrative Sciences',
  'Eğitim Fakültesi': 'Faculty of Education',
  'İlahiyat Fakültesi': 'Faculty of Theology',
  'İletişim Fakültesi': 'Faculty of Communication',
  'Turizm Fakültesi': 'Faculty of Tourism',
  'Ziraat Fakültesi': 'Faculty of Agriculture',
  'Veteriner Fakültesi': 'Faculty of Veterinary Medicine',
  'Eczacılık Fakültesi': 'Faculty of Pharmacy',
  'Sağlık Bilimleri Fakültesi': 'Faculty of Health Sciences',
  'Spor Bilimleri Fakültesi': 'Faculty of Sport Sciences',
  'Havacılık ve Uzay Bilimleri Fakültesi': 'Faculty of Aeronautics and Astronautics',
  'Türk Musikisi Devlet Konservatuvarı': 'Turkish Music State Conservatory',
  'Yabancı Diller Yüksekokulu': 'School of Foreign Languages',
  'Beden Eğitimi ve Spor Yüksekokulu': 'School of Physical Education and Sports',
  'Uygulamalı Bilimler Yüksekokulu': 'School of Applied Sciences',
  'Turizm ve Otelcilik Meslek Yüksekokulu': 'Tourism and Hotel Management Vocational School',
  'Teknik Bilimler Meslek Yüksekokulu': 'Vocational School of Technical Sciences',
  'Sosyal Bilimler Meslek Yüksekokulu': 'Vocational School of Social Sciences',
  'Sağlık Hizmetleri Meslek Yüksekokulu': 'Vocational School of Health Services',
  'Naci Topçuoğlu Meslek Yüksekokulu': 'Naci Topcuoglu Vocational School',
  'Oğuzeli Meslek Yüksekokulu': 'Oguzeli Vocational School',
  'Araban Meslek Yüksekokulu': 'Araban Vocational School',
  'İslahiye Meslek Yüksekokulu': 'Islahiye Vocational School',
  'Nizip Meslek Yüksekokulu': 'Nizip Vocational School',
  'Yavuzeli Meslek Yüksekokulu': 'Yavuzeli Vocational School',
  'Nurdağı Meslek Yüksekokulu': 'Nurdagi Vocational School',
  'Merkez Kütüphane': 'Central Library',
  'Merkez Öğrenci Yemekhanesi': 'Central Student Dining Hall',
  'Personel Yemekhanesi': 'Staff Dining Hall',
  'Öğrenci İşleri Daire Başkanlığı': 'Department of Student Affairs',
  'Genel Sekreterlik': 'General Secretariat',
  'Personel Daire Başkanlığı': 'Personnel Department',
  'Bilgi İşlem Daire Başkanlığı': 'Information Technology Department',
  'Kütüphane ve Dokümantasyon Daire Başkanlığı': 'Library and Documentation Department',
  'Sağlık Kültür ve Spor Daire Başkanlığı': 'Health, Culture and Sports Department',
  'Yapı İşleri ve Teknik Daire Başkanlığı': 'Construction and Technical Works Department',
  'İdari ve Mali İşler Daire Başkanlığı': 'Administrative and Financial Affairs Department',
  'Strateji Geliştirme Daire Başkanlığı': 'Strategy Development Department',
  'Hukuk Müşavirliği': 'Legal Counsel',
  'Basın Yayın ve Halkla İlişkiler Müdürlüğü': 'Press, Publication and Public Relations Office',
  'Çarşı Merkezi': 'Bazaar Center',
  'Yayınevi': 'Publishing House',
  'Kırtasiye': 'Stationery',
  'Uzaktan Eğitim Merkezi': 'Distance Education Center',

  // Tekil/bileşik bölüm-anabilim dalı adları (kurumsal ek almadıkları için
  // yukarıdaki ek-tabanlı eşleştirme onları yakalayamıyordu).
  'Arkeoloji': 'Archaeology',
  'Biyoloji': 'Biology',
  'Coğrafya': 'Geography',
  'Doğu Dilleri ve Edebiyatları': 'Eastern Languages and Literatures',
  'Felsefe': 'Philosophy',
  'Kimya': 'Chemistry',
  'Matematik': 'Mathematics',
  'Psikoloji': 'Psychology',
  'Sosyoloji': 'Sociology',
  'Tarih': 'History',
  'Türk Dili ve Edebiyatı': 'Turkish Language and Literature',
  'İktisat': 'Economics',
  'İşletme': 'Business Administration',
  'Uluslararası Ticaret ve Lojistik': 'International Trade and Logistics',
  'Siyaset Bilimi ve Kamu Yönetimi': 'Political Science and Public Administration',
  'Maliye': 'Public Finance',
  'Özel Eğitim': 'Special Education',
  'Şehir ve Bölge Planlama': 'City and Regional Planning',
  'İç Mimarlık': 'Interior Architecture',
  'Endüstriyel Tasarım': 'Industrial Design',
  'Resim': 'Painting',
  'Film Tasarımı ve Yönetimi': 'Film Design and Direction',
  'Tekstil ve Moda Tasarımı': 'Textile and Fashion Design',
  'Seramik ve Cam': 'Ceramics and Glass',
  'Grafik Sanatlar': 'Graphic Arts',
  'Gösteri ve Sahne Sanatları': 'Performing and Stage Arts',
  'Fotoğraf ve Video': 'Photography and Video',
  'Türkçe ve Sosyal Bilgiler Eğitimi': 'Turkish and Social Studies Education',
  'Kamu Hukuku': 'Public Law',
  'Özel Hukuk': 'Private Law',
  'Felsefe ve Din Bilimleri': 'Philosophy and Religious Sciences',
  'Temel İslam Bilimleri': 'Basic Islamic Sciences',
  'İslam Tarihi ve Sanatları': 'Islamic History and Arts',
  'Havacılık ve Uzay Mühendisliği': 'Aerospace Engineering',
  'Havacılık Yönetimi': 'Aviation Management',
  'Pilotaj': 'Flight Training',
  'Gastronomi ve Mutfak Sanatları': 'Gastronomy and Culinary Arts',

  // Merkez/tesis adları (kurum kısaltmaları korunur, açıklayıcı kısım çevrilir).
  'Merkezi Kütüphane': 'Central Library',
  'Klinik Araştırmalar': 'Clinical Research',
  'Açık Anfi': 'Open-Air Amphitheater',
  'Atatürk Kültür Sahnesi': 'Atatürk Culture Stage',
  'Demokrasi Meydanı': 'Democracy Square',
  'Teknofest Atölye': 'Teknofest Workshop',
  'ORET Garaj': 'ORET Garage',
  'Mimarlık ve Şehircilik Uygulama ve Araştırma Merkezi (GÜMŞAM)':
    'Center for Architecture and Urban Planning Application and Research (GÜMŞAM)',
  'Bilimsel Araştırma Projeleri Koordinasyon Birimi (BAP)':
    'Scientific Research Projects Coordination Unit (BAP)',

  // Suriye kampüsleri: açıklayıcı kısım çevrilir, yer adı ve "(Suriye)" korunur.
  'Azez İslami İlimler (Suriye)': 'Azez Islamic Sciences (Syria)',
  'Afrin Eğitim (Suriye)': 'Afrin Education (Syria)',
  'El-Bab İktisadi Ve İdari Bilimler (Suriye)': 'El-Bab Economics and Administrative Sciences (Syria)',
  'Cerablus (Suriye)': 'Jarabulus (Syria)',

  'Temel Eğitim': 'Basic Education',
  'Geleneksel Türk Sanatları Bölümü- Halı Tasarımı Lisans Programı':
    'Traditional Turkish Arts Department – Carpet Design Undergraduate Program',
  'Turizm ve Gastronomi Yönetimi Programları': 'Tourism and Gastronomy Management Programs',
  'Teknopark': 'Technopark',
  'Farabi Değişim Programı': 'Farabi Exchange Program',

  // Admin veri kalitesi backfill'i: DB'de kısa/özel ad olarak tutulan, ek
  // kurallarıyla güvenli çevrilemeyen kayıtlar. Özel marka/kısaltmalarda ad
  // korunur, açıklayıcı tür İngilizceleştirilir.
  'Nizip': 'Nizip Vocational School',
  'Oğuzeli': 'Oguzeli Vocational School',
  'Naci Topçuoğlu': 'Naci Topcuoglu Vocational School',
  'Nurdağı': 'Nurdagi Vocational School',
  'İslahiye': 'Islahiye Vocational School',
  'Araban': 'Araban Vocational School',
  'İktisadi Bilimler Fakültesi': 'Faculty of Economics',
  'İslahiye İktisadi Ve İdari Bilimler Fakültesi':
    'Islahiye Faculty of Economics and Administrative Sciences',
  'Nizip Eğitim Fakültesi': 'Nizip Faculty of Education',
  'Kütüphane Daire Başkanlığı': 'Library Department',
  'İç Hizmetler ve Güvenlik Müdürlüğü': 'Internal Services and Security Office',
  'Kreş ve Anaokulu Müdürlüğü': 'Nursery and Kindergarten Office',
  'Matbaa ve Basım İşleri Müdürlüğü': 'Printing and Publishing Works Office',
  'Uluğ Bey Yüksek Teknoloji Uygulama ve Araştırma Merkezi':
    'Ulugh Beg High Technology Application and Research Center',
  'Sürekli Eğitim Merkezi (GÜSEM)': 'Continuing Education Center (GUSEM)',
  'Türkçe Öğretimi Araştırma ve Uygulama Merkezi (TÖMER)':
    'Turkish Language Teaching Research and Application Center (TOMER)',
  'Fitoterapi ve Tıbbi Aromatik Bitkiler Uygulama ve Araştırma Merkezi':
    'Phytotherapy and Medicinal Aromatic Plants Application and Research Center',
  'Şahinbey Araştırma ve Uygulama Hastanesi Merkez Laboratuvarı':
    'Sahinbey Research and Application Hospital Central Laboratory',
  'Şahinbey Araştırma ve Uygulama Hastanesi': 'Sahinbey Research and Application Hospital',
  'Şahinbey Onkoloji Hastanesi': 'Sahinbey Oncology Hospital',
  'Diş Hekimliği Fakültesi Hastanesi': 'Faculty of Dentistry Hospital',
  'Şahinbey KYK Öğrenci Yurdu': 'Sahinbey KYK Student Dormitory',
  'Ümmü Gülsüm KYK Öğrenci Yurdu': 'Ummu Gulsum KYK Student Dormitory',
  'GAÜN Sporium İşletme Müdürlüğü': 'GAUN Sporium Operations Office',
  'Atatürk Kültür Sahnesi': 'Ataturk Culture Stage',
  'Hukuk Fakültesi Kütüphanesi': 'Faculty of Law Library',
  'İlahiyat fakültesi Fuat Sezgin Kütüphanesi': 'Faculty of Theology Fuat Sezgin Library',
  'Kadın ve Aile Çalışmaları Uygulama  ve Araştırma Merkezi':
    'Women and Family Studies Application and Research Center',
  'Öğrenci Pazarı-Takı Bujiteri': 'Student Market - Accessories and Jewelry',
  'ÖSYM Başvuru Merkezi': 'OSYM Application Center',
  'Mimarlık ve Şehircilik Uygulama ve Araştırma Merkezi (GÜMŞAM)':
    'Center for Architecture and Urban Planning Application and Research (GUMSAM)',
  'Kariyer Planlama Ve Girişimcilik Uygulama Ve Araştırma Merkezi':
    'Career Planning and Entrepreneurship Application and Research Center',
  'Enerji Yönetimi Uygulama ve Araştırma Merkezi':
    'Energy Management Application and Research Center',
  'Gaziantep Üniversitesi Kahraman Eruslu Böbrek Nakli Hastanesi':
    'Gaziantep University Kahraman Eruslu Kidney Transplant Hospital',
  'Türkçe ve Sosyal Bilimler Eğitimi': 'Turkish and Social Sciences Education',
  'Matematik ve Fen Bilimleri Eğitimi': 'Mathematics and Science Education',
  'Yabancı Diller Eğitimi': 'Foreign Languages Education',
  'Film Tasarımı ve Yönetimi Bölümü Öğrencilerinden Büyük Başarı':
    'Major Achievement by Film Design and Direction Department Students',
  'Hemşirelik Bölümü Öğrencileri Futbol Turnuva Etkinliği (hem City Şampiyonluğu)':
    'Nursing Department Students Football Tournament Event (Hem City Championship)',
  'NTMYO Test-Lab': 'NTMYO Test Lab',
  'Elektrik Cafe': 'Elektrik Cafe',
  'ERASMUS+': 'ERASMUS+',
  'Cafe Tee': 'Cafe Tee',
  'Makarona Makarna&Pizza': 'Makarona Pasta & Pizza',
  'Buhara Kır Kafe': 'Buhara Country Cafe',
  'FabLab': 'FabLab',
  'GABAM': 'GABAM',
  'GAUNDAM': 'GAUNDAM',
  'Toplumsal Duyarlılık Projeleri Merkezi Proje': 'Social Responsibility Projects Center',
  'Anadolu Cafe': 'Anadolu Cafe',
  'Tıp Cafe': 'Medicine Cafe',
  'Resital Cafe': 'Resital Cafe',
  'Fen Cafe': 'Science Cafe',
  'Cemiyet Kitap-Kahve': 'Cemiyet Book & Coffee',
  'Gaziantep Gençlik Merkezi Genç Ofis': 'Gaziantep Youth Center Youth Office',
  'Minas Coffee': 'Minas Coffee',
  'Tostçu Mahmut': 'Tostcu Mahmut',
  'Bay Bayan Kuaför': "Men's and Women's Hairdresser",
  'Balıkçı': 'Fish Restaurant',
  'Simitçi Dünyası': 'Simitci Dunyasi',
  'Sweet House- Tatlıcı': 'Sweet House Dessert Shop',
  'Market': 'Market',
  'Telekom': 'Telecom',
  'Diyanet Genç Ofis': 'Diyanet Youth Office',
  'Halk Bankası': 'Halkbank',
  'Kitap Kafe': 'Book Cafe',
  'Restaurant (Kumrucu)': 'Restaurant (Kumru Sandwich)',
  'Optik': 'Optician',
  'Restaurant': 'Restaurant',
  'Civil Cafe': 'Civil Cafe',
  'Cafed Cafe': 'Cafed Cafe',
  'Önder Pastanesi': 'Onder Patisserie',
  'Fakültemiz Kamu Yönetimi Bölümü Akreditasyon Çalışmaları Kapsamında Değerlendirme Ziyareti Gerçekleştirildi':
    'Public Administration Department Accreditation Evaluation Visit',
  'Fakültemiz Kamu Yönetimi Bölümü Akreditasyon Çalışmaları Kapsamında...':
    'Public Administration Department Accreditation Activities',
  'Bölüm Başkanı Giriş': 'Department Head Login',
  'Bölüm Kalite Temsilcisi': 'Department Quality Representative',
  'Hemşirelik Bölümü Anasayfa': 'Nursing Department Homepage',
  'Ebelik Bölümü Anasayfa': 'Midwifery Department Homepage',
  'Beslenme ve Diyetetik Bölümü Anasayfa': 'Nutrition and Dietetics Department Homepage',
  'Fizyoterapi ve Rehabilitasyon Bölümü Anasayfa': 'Physiotherapy and Rehabilitation Department Homepage',
  'Fakülte ve Bölüm Web Sorumluları': 'Faculty and Department Web Administrators',
  'Hemşirelik Bölümü Tanıtımı Etkinliği': 'Nursing Department Promotion Event',
  'Bölüm Hakkında': 'About the Department',
  'Bölüm Başkanı': 'Department Head',
  'Turizm İşletmeciliği Bölüm Bilgileri': 'Tourism Management Department Information',
  'Bölüm Başkanlıkları': 'Department Chairs',
  'Bölüm ve Anabilim Dalı Başkanları': 'Department and Division Chairs',
  'İşletme Bölümü Lisans Programı Kataloğu': 'Business Administration Undergraduate Program Catalog',
  'İktisat Bölümü Lisans Programı Kataloğu': 'Economics Undergraduate Program Catalog',
  'Uluslararası Ticaret ve Lojistik Bölümü Lisans Programı Kataloğu':
    'International Trade and Logistics Undergraduate Program Catalog'
};

const LOCATION_CORE_LABELS_EN = {
  'Sosyal Bilimler': 'Social Sciences',
  'Sağlık Bilimleri': 'Health Sciences',
  'Fen Bilimleri': 'Natural Sciences',
  'Eğitim Bilimleri': 'Educational Sciences',
  'Göç': 'Migration',
  'Mühendislik': 'Engineering',
  'Fizik Mühendisliği': 'Physics Engineering',
  'Bilgisayar Mühendisliği': 'Computer Engineering',
  'Elektrik Elektronik Mühendisliği': 'Electrical and Electronics Engineering',
  'Elektrik-Elektronik Mühendisliği': 'Electrical and Electronics Engineering',
  'Makine Mühendisliği': 'Mechanical Engineering',
  'İnşaat Mühendisliği': 'Civil Engineering',
  'Gıda Mühendisliği': 'Food Engineering',
  'Endüstri Mühendisliği': 'Industrial Engineering',
  'Tekstil Mühendisliği': 'Textile Engineering',
  'Tıp': 'Medicine',
  'Diş Hekimliği': 'Dentistry',
  'Hukuk': 'Law',
  'Mimarlık': 'Architecture',
  'Güzel Sanatlar': 'Fine Arts',
  'Fen Edebiyat': 'Arts and Sciences',
  'İktisadi ve İdari Bilimler': 'Economics and Administrative Sciences',
  'Eğitim': 'Education',
  'İlahiyat': 'Theology',
  'İletişim': 'Communication',
  'Turizm': 'Tourism',
  'Ziraat': 'Agriculture',
  'Veteriner': 'Veterinary Medicine',
  'Eczacılık': 'Pharmacy',
  'Sağlık Hizmetleri': 'Health Services',
  'Teknik Bilimler': 'Technical Sciences',
  'Uygulamalı Bilimler': 'Applied Sciences',
  'Yabancı Diller': 'Foreign Languages',
  'Spor Bilimleri': 'Sport Sciences',
  'Beden Eğitimi ve Spor': 'Physical Education and Sports',
  'Turizm ve Otelcilik': 'Tourism and Hotel Management',
  'Uzaktan Eğitim': 'Distance Education',
  'Sürekli Eğitim': 'Continuing Education',
  'Araştırma ve Uygulama': 'Research and Application',
  'Türkçe Öğretimi': 'Turkish Language Teaching',
  'Kariyer Planlama': 'Career Planning',
  'Uluslararası İlişkiler': 'International Relations',
  'Öğrenci İşleri': 'Student Affairs'
};

const LOCATION_PHRASES_EN = [
  ['Gaziantep Üniversitesi', 'Gaziantep University'],
  ['Merkez Öğrenci', 'Central Student'],
  ['Personel', 'Staff'],
  ['Öğrenci', 'Student'],
  ['Araştırma ve Uygulama', 'Research and Application'],
  ['Uzaktan Eğitim', 'Distance Education'],
  ['Sürekli Eğitim', 'Continuing Education'],
  ['Kariyer Planlama', 'Career Planning'],
  ['Uluslararası İlişkiler', 'International Relations'],
  ['Türkçe Öğretimi', 'Turkish Language Teaching'],
  ['Sağlık Bilimleri', 'Health Sciences'],
  ['Sosyal Bilimler', 'Social Sciences'],
  ['Fen Bilimleri', 'Natural Sciences'],
  ['Eğitim Bilimleri', 'Educational Sciences'],
  ['Yabancı Diller', 'Foreign Languages'],
  ['Spor Bilimleri', 'Sport Sciences'],
  ['Teknik Bilimler', 'Technical Sciences']
];

// Sözlük anahtarlarını (ve ifade listesindeki arama terimlerini) NFC'ye sabitle.
// Kaynak dosyada elle yazılmış olsalar da, aynı normalizasyon garantisini tek
// bir yerde uygulamak (girdi normalizasyonuyla eşleşecek şekilde) daha sağlam.
[TAXONOMY_LABELS_EN, LOCATION_LABELS_EN, LOCATION_CORE_LABELS_EN].forEach(dict => {
  Object.keys(dict).forEach(key => {
    const nfcKey = normalizeNfc(key);
    if (nfcKey !== key) { dict[nfcKey] = dict[key]; delete dict[key]; }
  });
});
LOCATION_PHRASES_EN.forEach(pair => { pair[0] = normalizeNfc(pair[0]); });

function replaceAllLiteral(text, search, replacement) {
  return String(text).split(search).join(replacement);
}

function locationNameEn(name) {
  const text = normalizeNfc(name).trim();
  if (!text) return '';
  // Tam eşleşmeler önce: kategori adları ve zaten hazır bileşik çeviriler
  // (ör. "Bilgisayar Mühendisliği") burada bulunur; eskiden yalnızca ek
  // birleştirme için kullanıldıklarından hiç ulaşılamıyorlardı.
  if (LOCATION_LABELS_EN[text]) return LOCATION_LABELS_EN[text];
  if (TAXONOMY_LABELS_EN[text]) return TAXONOMY_LABELS_EN[text];
  if (LOCATION_CORE_LABELS_EN[text]) return LOCATION_CORE_LABELS_EN[text];

  for (const [tr, en] of Object.entries(LOCATION_CORE_LABELS_EN)) {
    if (text === `${tr} Enstitüsü`) return `Institute of ${en}`;
    if (text === `${tr} Fakültesi`) return `Faculty of ${en}`;
    if (text === `${tr} Bölümü`) return `${en} Department`;
    if (text === `${tr} Ana Bilim Dalı`) return `${en} Department`;
    if (text === `${tr} Yüksekokulu`) return `${en} School`;
    if (text === `${tr} Meslek Yüksekokulu`) return `${en} Vocational School`;
    if (text === `${tr} Merkezi`) return `${en} Center`;
    if (text === `${tr} Başkanlığı`) return `${en} Department`;
  }

  let translated = text;
  LOCATION_PHRASES_EN.forEach(([tr, en]) => {
    translated = replaceAllLiteral(translated, tr, en);
  });

  const suffixes = [
    [' Meslek Yüksekokulu', ' Vocational School'],
    [' Yüksekokulu', ' School'],
    [' Fakültesi', ' Faculty'],
    [' Enstitüsü', ' Institute'],
    [' Mühendisliği', ' Engineering'],
    [' Bölümü', ' Department'],
    [' Ana Bilim Dalı', ' Department'],
    [' Daire Başkanlığı', ' Department'],
    [' Başkanlığı', ' Department'],
    [' Müdürlüğü', ' Office'],
    [' Merkezi', ' Center'],
    [' Hastanesi', ' Hospital'],
    [' Kütüphanesi', ' Library'],
    [' Yemekhanesi', ' Dining Hall'],
    [' Binası', ' Building']
  ];
  suffixes.forEach(([tr, en]) => {
    if (translated.endsWith(tr)) {
      translated = `${translated.slice(0, -tr.length)}${en}`;
    }
  });

  return translated !== text ? translated : '';
}

function displayLocationName(value) {
  const item = value && typeof value === 'object' ? value : null;
  const text = String(item?.name ?? value ?? '');
  if (App.state.lang !== 'en') return text;
  const dbName = normalizeNfc(item?.name_en).trim();
  return dbName || locationNameEn(text) || text;
}

function displayTaxonomy(value) {
  const text = String(value ?? '');
  return App.state.lang === 'en' ? (TAXONOMY_LABELS_EN[normalizeNfc(text)] ?? text) : text;
}

function setText(id, key) {
  const el = document.getElementById(id);
  if (el) el.textContent = t(key);
}

function setTitle(id, key) {
  const el = document.getElementById(id);
  if (el) el.title = t(key);
}

function setPlaceholder(id, key) {
  const el = document.getElementById(id);
  if (el) el.placeholder = t(key);
}

function applyTheme(theme) {
  App.state.theme = theme === 'dark' ? 'dark' : 'light';
  document.documentElement.classList.toggle('dark', App.state.theme === 'dark');
  document.documentElement.classList.toggle('light', App.state.theme !== 'dark');
  localStorage.setItem('gaun_theme', App.state.theme);

  const meta = document.querySelector('meta[name="theme-color"]');
  if (meta) meta.content = App.state.theme === 'dark' ? '#121611' : '#3d6922';

  const icon = document.getElementById('themeToggleIcon');
  if (icon) icon.textContent = App.state.theme === 'dark' ? 'light_mode' : 'dark_mode';
  setTitle('themeToggle', App.state.theme === 'dark' ? 'ui.themeLight' : 'ui.themeDark');
}

// applyLocale(): tüm sabit UI metinlerini günceller, sonra her modülün kendi
// "aktif dile göre yeniden çiz" kancasını (varsa) çağırır. Modüller birbirini
// doğrudan import etmediği için bağlantı App nesnesi üzerinden kurulur; bu
// fonksiyon yalnızca kullanıcı arayüzü etkileşiminden (dil/tema butonu, veri
// yüklendikten sonra) sonra çağrıldığından, o ana kadar App.routing/App.map/
// App.search/App.bulletin gibi modüller zaten yüklenmiş olur.
function applyLocale() {
  document.documentElement.lang = App.state.lang;
  localStorage.setItem('gaun_lang', App.state.lang);
  document.title = t('app.title');

  setTitle('panelToggle', 'ui.menu');
  setTitle('searchClear', 'ui.clearSearch');
  setTitle('assistantToggle', 'ui.assistant');
  setTitle('resetBtn', 'ui.reset');
  setTitle('locateBtn', App.state.watchId === null ? 'ui.locate' : 'ui.stopTracking');
  setTitle('bulletinBtn', 'ui.todayCampus');
  setTitle('dirShare', 'route.copyLink');
  setTitle('langToggle', 'ui.langTitle');
  setTitle('themeToggle', App.state.theme === 'dark' ? 'ui.themeLight' : 'ui.themeDark');

  setPlaceholder('search', 'ui.searchPlaceholder');
  setPlaceholder('assistantInput', 'ui.assistantPlaceholder');
  setText('bulletinTitleText', 'ui.todayCampus');
  setText('bulletinSourceText', 'ui.bulletinSource');
  setText('routeWalkLabel', 'route.walk');
  setText('routeDriveLabel', 'route.drive');
  setText('sidebarKicker', 'ui.sidebarKicker');
  setText('nearbyTitleText', 'ui.nearby');
  setText('explorerTitleText', 'ui.explore');
  setText('sidebarInfoText', 'ui.sidebarInfo');
  setText('feedbackTitleText', 'ui.feedback');
  setText('feedbackHintText', 'ui.feedbackHint');

  // Görüş ve öneri mailto linki: adres Ayarlar > İletişim E-posta'dan
  // (GAUN_CONFIG.settings.contactEmail) gelir, boşsa kurumsal varsayılan;
  // konu satırı aktif dile göre kurulur.
  const feedbackLink = document.getElementById('feedbackLink');
  if (feedbackLink) {
    const configEmail = window.GAUN_CONFIG?.settings?.contactEmail;
    const email = (typeof configEmail === 'string' && configEmail.includes('@'))
      ? configEmail
      : 'Harita@gantep.edu.tr';
    feedbackLink.href = `mailto:${email}?subject=${encodeURIComponent(t('ui.feedbackSubject'))}`;
    feedbackLink.title = email;
  }

  const routeModeGroup = document.getElementById('routeModeGroup');
  if (routeModeGroup) routeModeGroup.setAttribute('aria-label', t('route.type'));

  const langLabel = document.getElementById('langToggleLabel');
  if (langLabel) langLabel.textContent = App.state.lang.toUpperCase();

  App.routing?.syncDirGoButton?.();
  if (App.state.selectedItem?.coords) App.routing?.showDirections?.(App.state.selectedItem, false);
  if (App.state.campusData) {
    App.search?.renderExplorer?.(App.state.campusData);
    App.search?.renderChips?.(App.state.campusData);
    App.search?.applyFilter?.();
  }
  const quickIntentWrap = document.getElementById('quickIntents');
  if (quickIntentWrap?.children.length) {
    const activeIntent = App.search?.quickIntents?.find(i => i.id === App.state.activeQuickIntent);
    App.search?.renderQuickIntents?.();
    if (activeIntent) {
      document.getElementById('search').value = App.search.quickIntentLabel(activeIntent);
      App.search.showQuickIntent(activeIntent, false);
    }
  }
  if (App.state.bulletinData) App.bulletin?.refreshLocale?.();
  if (App.state.bulletinData) App.map?.renderTodayEventMarkers?.();
  if (App.state.userLatLng) App.map?.renderNearby?.();
  App.map?.refreshUserMarkerPopup?.();
  App.map?.refreshMarkerPopups?.();
}

function setLanguage(lang) {
  App.state.lang = lang === 'en' ? 'en' : 'tr';
  applyLocale();
}

function quickIntentLabelForKey(key) {
  return t(`quick.${key}`);
}

App.i18n = {
  I18N,
  t,
  applyTheme,
  applyLocale,
  setLanguage,
  displayLocationName,
  displayTaxonomy,
  locationNameEn,
  normalizeNfc,
  trLower,
  foldTr,
  lowerUi,
  setText,
  setTitle,
  setPlaceholder,
  TAXONOMY_LABELS_EN,
  LOCATION_LABELS_EN,
  LOCATION_CORE_LABELS_EN,
  LOCATION_PHRASES_EN,
  quickIntentLabelForKey
};
