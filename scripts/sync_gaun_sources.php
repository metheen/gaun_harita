<?php
declare(strict_types=1);

/**
 * GAÜN resmi kaynak senkronizasyonu (yalnızca CLI).
 *
 * Resmi GAÜN sayfalarından birim/duyuru/haber/yemek/önemli-link verisi çeker
 * ve private/cache/gaun_sources.json dosyasına normalize ederek yazar.
 *
 * Kullanım:
 *   docker compose run --rm web php scripts/sync_gaun_sources.php [--verbose]
 *
 * Tasarım ilkeleri:
 *   - SSRF koruması: yalnızca izinli host'lara (gaziantep.edu.tr / gantep.edu.tr
 *     ve alt alanları) istek atılır; yönlendirmeler elle takip edilip her adımda
 *     yeniden doğrulanır. IP-literal host'lar reddedilir.
 *   - Dayanıklılık: bir bölüm çekilemezse eski cache'teki bölüm korunur.
 *     Public uç noktalar bu script'i ASLA çalıştırmaz; yalnızca cache okurlar.
 *   - Atomik yazma: tmp dosyaya yaz + rename.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Bu script yalnızca komut satırından çalıştırılabilir.\n");
}

const CACHE_FILE = __DIR__ . '/../private/cache/gaun_sources.json';

/** İzinli host sonekleri (tam eşleşme veya ".sonek" ile biten alt alan). */
const ALLOWED_HOST_SUFFIXES = ['gaziantep.edu.tr', 'gantep.edu.tr'];

const HTTP_CONNECT_TIMEOUT = 3;   // saniye
const HTTP_TIMEOUT         = 12;  // saniye
const HTTP_MAX_REDIRECTS   = 3;
const USER_AGENT           = 'GAUN-KampusHarita-Sync/1.0 (+kampus harita zenginlestirme)';

$VERBOSE = in_array('--verbose', $argv ?? [], true);

function vlog(string $msg): void
{
    global $VERBOSE;
    if ($VERBOSE) {
        fwrite(STDERR, $msg . "\n");
    }
}

// ---------------------------------------------------------------------------
// Güvenli HTTP katmanı
// ---------------------------------------------------------------------------

/** Host izinli listede mi? (IP-literal ve port'lu host'lar reddedilir) */
function host_allowed(string $host): bool
{
    $host = strtolower($host);
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return false;
    }
    foreach (ALLOWED_HOST_SUFFIXES as $suffix) {
        if ($host === $suffix || str_ends_with($host, '.' . $suffix)) {
            return true;
        }
    }
    return false;
}

/** URL şema + host + port doğrulaması. */
function url_allowed(string $url): bool
{
    $p = parse_url($url);
    if (!is_array($p)) {
        return false;
    }
    $scheme = strtolower($p['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) {
        return false;
    }
    if (isset($p['port']) && !in_array($p['port'], [80, 443], true)) {
        return false;
    }
    return host_allowed($p['host'] ?? '');
}

/**
 * İzinli bir URL'yi indirir. Yönlendirmeler elle ve allowlist kontrolüyle
 * takip edilir. Dönüş: ['ok'=>bool, 'body'=>?string, 'error'=>?string].
 */
function fetch_url(string $url): array
{
    for ($hop = 0; $hop <= HTTP_MAX_REDIRECTS; $hop++) {
        if (!url_allowed($url)) {
            return ['ok' => false, 'body' => null, 'error' => "izinsiz URL: {$url}"];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,       // elle takip (allowlist için)
            CURLOPT_CONNECTTIMEOUT => HTTP_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
            CURLOPT_USERAGENT      => USER_AGENT,
            CURLOPT_ENCODING       => '',          // gzip vb. otomatik çöz
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $redir  = curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: null;
        $err    = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'body' => null, 'error' => "curl: {$err}"];
        }
        if ($status >= 300 && $status < 400 && $redir !== null) {
            $url = $redir;                         // bir sonraki tura, tekrar doğrulanır
            continue;
        }
        if ($status !== 200) {
            return ['ok' => false, 'body' => null, 'error' => "HTTP {$status}"];
        }
        return ['ok' => true, 'body' => (string) $body, 'error' => null];
    }
    return ['ok' => false, 'body' => null, 'error' => 'çok fazla yönlendirme'];
}

// ---------------------------------------------------------------------------
// HTML ayrıştırma yardımcıları
// ---------------------------------------------------------------------------

function make_dom(string $html): DOMDocument
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    // Kodlama ipucu: sayfalar UTF-8; meta öneki DOMDocument'i doğru yönlendirir.
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    return $doc;
}

function clean_text(string $s): string
{
    $s = preg_replace('/\s+/u', ' ', $s) ?? '';
    return trim($s);
}

/** Karşılaştırma/anahtar üretimi için Türkçe karakterleri sadeleştirir. */
function fold_text(string $s): string
{
    $s = str_replace(['İ', 'I'], ['i', 'ı'], trim($s));
    $s = mb_strtolower($s, 'UTF-8');
    $s = strtr($s, [
        'ç' => 'c', 'ğ' => 'g', 'ı' => 'i', 'ö' => 'o', 'ş' => 's', 'ü' => 'u',
        'â' => 'a', 'î' => 'i', 'û' => 'u',
    ]);
    $s = preg_replace('/[^a-z0-9]+/u', ' ', $s) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $s) ?? $s);
}

/** Göreli URL'yi taban URL'ye göre mutlaklaştırır (yalnız basit durumlar). */
function absolutize(string $href, string $base): ?string
{
    $href = trim($href);
    if ($href === '' || str_starts_with($href, '#') || stripos($href, 'javascript:') === 0) {
        return null;
    }
    if (preg_match('#^https?://#i', $href)) {
        return $href;
    }
    $bp = parse_url($base);
    if (!isset($bp['scheme'], $bp['host'])) {
        return null;
    }
    $origin = $bp['scheme'] . '://' . $bp['host'];
    if (str_starts_with($href, '//')) {
        return $bp['scheme'] . ':' . $href;
    }
    if (str_starts_with($href, '/')) {
        return $origin . $href;
    }
    $dir = rtrim(dirname($bp['path'] ?? '/'), '/');
    return $origin . $dir . '/' . $href;
}

/** Sayfadaki tüm bağlantıları [metin, mutlak-url] olarak döndürür. */
function extract_links(string $html, string $baseUrl): array
{
    $out = [];
    foreach (make_dom($html)->getElementsByTagName('a') as $a) {
        $abs = absolutize($a->getAttribute('href'), $baseUrl);
        if ($abs === null) {
            continue;
        }
        $text = clean_text($a->textContent);
        if ($text !== '') {
            $out[] = ['text' => $text, 'url' => $abs];
        }
    }
    return $out;
}

/** Manuel doğrulanmış resmi GAÜN kaynakları ve çalışan kanonik adresler. */
function seed_units(): array
{
    $now = gmdate('c');
    $mk = static fn (string $name, string $url, bool $staffSource = true) => [
        'name'         => $name,
        'website'      => $url,
        'source_url'   => $url,
        'fetched_at'   => $now,
        'seeded'       => true,
        'staff_source' => $staffSource,
    ];

    return [
        // Temel yönetim / öğrenci sistemleri
        $mk('GAZİANTEP ÜNİVERSİTESİ ANA SAYFA', 'https://www.gaziantep.edu.tr/', false),
        $mk('OBS ÖĞRENCİ BİLGİ SİSTEMİ', 'https://obs.gaziantep.edu.tr/', false),
        $mk('ÖĞRENCİ İŞLERİ DAİRE BAŞKANLIĞI', 'https://oidb.gaziantep.edu.tr/', false),
        $mk('AKBİS AKADEMİK BİLGİ SİSTEMİ', 'https://akbis.gaziantep.edu.tr/', false),
        $mk('UZAKTAN EĞİTİM UYGULAMA VE ARAŞTIRMA MERKEZİ', 'http://uzem.gaziantep.edu.tr/', true),
        $mk('GAÜN AKADEMİK VE İDARİ PERSONEL BAŞVURU SİSTEMİ', 'https://gaunay.gaziantep.edu.tr/', false),

        // Fakülteler ve önemli bölüm alt siteleri
        $mk('MÜHENDİSLİK FAKÜLTESİ', 'https://eng.gaziantep.edu.tr/'),
        $mk('BİLGİSAYAR MÜHENDİSLİĞİ', 'https://cse.gaziantep.edu.tr/'),
        $mk('ELEKTRİK ELEKTRONİK MÜHENDİSLİĞİ', 'https://eee.gaziantep.edu.tr/'),
        $mk('GIDA MÜHENDİSLİĞİ', 'https://fe.gaziantep.edu.tr/'),
        $mk('ENDÜSTRİ MÜHENDİSLİĞİ', 'https://ie.gaziantep.edu.tr/'),
        $mk('MAKİNA MÜHENDİSLİĞİ', 'https://me.gaziantep.edu.tr/'),
        $mk('İNŞAAT MÜHENDİSLİĞİ', 'https://ce.gaziantep.edu.tr/'),
        $mk('TIP FAKÜLTESİ', 'https://tip.gaziantep.edu.tr/'),
        $mk('DİŞ HEKİMLİĞİ FAKÜLTESİ', 'https://dishekimligi.gaziantep.edu.tr/'),
        $mk('HUKUK FAKÜLTESİ', 'https://hukuk.gaziantep.edu.tr/'),
        $mk('İKTİSADİ VE İDARİ BİLİMLER FAKÜLTESİ', 'https://iibf.gaziantep.edu.tr/'),
        $mk('GAZİANTEP EĞİTİM FAKÜLTESİ', 'https://gef.gaziantep.edu.tr/'),
        $mk('MİMARLIK FAKÜLTESİ', 'https://mimarlik.gaziantep.edu.tr/'),
        $mk('FEN EDEBİYAT FAKÜLTESİ', 'https://fef.gaziantep.edu.tr/'),
        $mk('İLETİŞİM FAKÜLTESİ', 'https://ilef.gaziantep.edu.tr/'),
        $mk('İLAHİYAT FAKÜLTESİ', 'https://ilahiyat.gaziantep.edu.tr/'),
        $mk('HAVACILIK VE UZAY BİLİMLERİ FAKÜLTESİ', 'https://hubf.gaziantep.edu.tr/'),
        $mk('GÜZEL SANATLAR FAKÜLTESİ', 'https://gsf.gaziantep.edu.tr/'),
        $mk('SAĞLIK BİLİMLERİ FAKÜLTESİ', 'https://sbf.gaziantep.edu.tr/'),

        // Enstitüler
        $mk('FEN BİLİMLERİ ENSTİTÜSÜ', 'https://fbe.gaziantep.edu.tr/'),
        $mk('SOSYAL BİLİMLER ENSTİTÜSÜ', 'https://sbe.gaziantep.edu.tr/'),
        $mk('SAĞLIK BİLİMLERİ ENSTİTÜSÜ', 'https://sagbe.gaziantep.edu.tr/'),
        $mk('EĞİTİM BİLİMLERİ ENSTİTÜSÜ', 'https://ebe.gaziantep.edu.tr/'),
        $mk('GÖÇ ENSTİTÜSÜ', 'https://goc.gaziantep.edu.tr/'),

        // Daire başkanlıkları / idari birimler
        $mk('BİLGİ İŞLEM DAİRE BAŞKANLIĞI', 'https://bidb.gaziantep.edu.tr/'),
        $mk('SAĞLIK KÜLTÜR VE SPOR DAİRE BAŞKANLIĞI', 'http://sksdb.gaziantep.edu.tr/'),
        $mk('KÜTÜPHANE VE DOKÜMANTASYON DAİRE BAŞKANLIĞI', 'https://kutuphane.gaziantep.edu.tr/'),
        $mk('PERSONEL DAİRE BAŞKANLIĞI', 'http://pdb.gaziantep.edu.tr/'),
        $mk('İDARİ VE MALİ İŞLER DAİRE BAŞKANLIĞI', 'http://imidb.gaziantep.edu.tr/'),
        $mk('YAPI İŞLERİ VE TEKNİK DAİRE BAŞKANLIĞI', 'http://yapiisleri.gaziantep.edu.tr/'),

        // Merkezler
        $mk('ERASMUS KOORDİNATÖRLÜĞÜ', 'https://erasmus.gaziantep.edu.tr/'),
        $mk('FARABİ DEĞİŞİM PROGRAMI', 'https://farabi.gaziantep.edu.tr/'),
        $mk('TÜRKÇE ÖĞRETİMİ UYGULAMA VE ARAŞTIRMA MERKEZİ', 'http://tomer.gaziantep.edu.tr/'),
    ];
}

// ---------------------------------------------------------------------------
// Bölüm toplayıcılar — her biri bağımsız; hata durumunda null döner.
// ---------------------------------------------------------------------------

/** Ana sayfadan akademik/idari birim adı + web sitesi listesi. */
function collect_units(string $sourceUrl): ?array
{
    $res = fetch_url($sourceUrl);
    if (!$res['ok']) {
        vlog("units: {$res['error']}");
        return null;
    }

    $pattern = '/(FAKÜLTESİ|FAKÜLTE|YÜKSEKOKULU|ENSTİTÜSÜ|KONSERVATUARI|BAŞKANLIĞI|' .
               'MÜDÜRLÜĞÜ|MERKEZİ|KOORDİNATÖRLÜĞÜ|BÖLÜMÜ|OFİSİ|MÜŞAVİRLİĞİ)\b/u';
    $now = gmdate('c');
    $seen = [];
    $units = [];

    foreach (extract_links($res['body'], $sourceUrl) as $link) {
        if (mb_strlen($link['text'], 'UTF-8') > 140 || preg_match('/(habermerkezi|duyuru_detay|etkinlik_detay|\/\?p=)/i', $link['url'])) {
            continue;
        }
        if (!preg_match($pattern, mb_strtoupper($link['text'], 'UTF-8'))) {
            continue;
        }
        $host = strtolower(parse_url($link['url'], PHP_URL_HOST) ?? '');
        if (!host_allowed($host)) {
            continue;
        }
        $key = mb_strtolower($link['text'], 'UTF-8');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $units[] = [
            'name'       => $link['text'],
            'website'    => $link['url'],
            'source_url' => $sourceUrl,
            'fetched_at' => $now,
        ];
    }

    foreach (seed_units() as $seed) {
        $host = strtolower(parse_url($seed['website'], PHP_URL_HOST) ?? '');
        if (!host_allowed($host)) {
            continue;
        }
        $key = mb_strtolower($seed['name'], 'UTF-8');
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $units[] = $seed;
    }

    return $units ?: null;
}

/** Duyuru sayfası HTML'ini ayrıştırır (saf fonksiyon — ağ erişimi yok). */
function parse_announcements_html(string $html, string $sourceUrl): ?array
{
    $now = gmdate('c');
    $seen = [];
    $items = [];
    foreach (extract_links($html, $sourceUrl) as $link) {
        if (!str_contains($link['url'], 'duyuru_detay.php')) {
            continue;
        }
        // "25 HaziranBaşlık..." biçimindeki tarih önekini ayıkla.
        $title = $link['text'];
        $date  = null;
        if (preg_match('/^(\d{1,2}\s+(Ocak|Şubat|Mart|Nisan|Mayıs|Haziran|Temmuz|Ağustos|Eylül|Ekim|Kasım|Aralık))\s*/u', $title, $m)) {
            $date  = $m[1];
            $title = clean_text(mb_substr($title, mb_strlen($m[0], 'UTF-8'), null, 'UTF-8'));
        }
        if ($title === '' || isset($seen[$link['url']])) {
            continue;
        }
        $seen[$link['url']] = true;
        $items[] = [
            'title'      => $title,
            'url'        => $link['url'],
            'date'       => $date,
            'source_url' => $sourceUrl,
            'fetched_at' => $now,
        ];
        if (count($items) >= 20) {
            break;
        }
    }
    return $items ?: null;
}

/** Ana sayfadaki duyurular (duyuru_detay.php bağlantıları). */
function collect_announcements(string $sourceUrl): ?array
{
    $res = fetch_url($sourceUrl);
    if (!$res['ok']) {
        vlog("announcements: {$res['error']}");
        return null;
    }
    return parse_announcements_html($res['body'], $sourceUrl);
}

/** Haber merkezi son başlıkları. */
function collect_news(string $sourceUrl): ?array
{
    $res = fetch_url($sourceUrl);
    if (!$res['ok']) {
        vlog("news: {$res['error']}");
        return null;
    }
    $now = gmdate('c');
    $doc = make_dom($res['body']);
    $seen = [];
    $items = [];

    // WordPress teması: başlıklar h1-h3 içindeki bağlantılarda.
    foreach (['h1', 'h2', 'h3'] as $tag) {
        foreach ($doc->getElementsByTagName($tag) as $h) {
            foreach ($h->getElementsByTagName('a') as $a) {
                $url = absolutize($a->getAttribute('href'), $sourceUrl);
                $title = clean_text($a->textContent);
                if ($url === null || $title === '' || mb_strlen($title, 'UTF-8') < 15) {
                    continue;
                }
                $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
                if (!host_allowed($host) || isset($seen[$url])) {
                    continue;
                }
                $seen[$url] = true;
                $items[] = [
                    'title'      => $title,
                    'url'        => $url,
                    'source_url' => $sourceUrl,
                    'fetched_at' => $now,
                ];
                if (count($items) >= 10) {
                    return $items;
                }
            }
        }
    }
    return $items ?: null;
}

/**
 * Yemekhane sayfasından bugünün menüsü.
 *
 * Sayfa yapısı: aylık tabloda her gün bir <td class="day"> hücresi;
 * gün etiketi <div class="badge...">DD AyAdı</div>, yemekler
 * <a class="a_modalinfo">AD (NNN kcal)</a>. Yapı değişirse null döner
 * (eski cache korunur; uygulama bozulmaz).
 */
/**
 * Yemekhane sayfası HTML'ini ayrıştırır (saf fonksiyon — ağ erişimi yok).
 * `$now` verilmezse gerçek "şu an" (Europe/Istanbul) kullanılır; testlerden
 * deterministik bir tarih enjekte edilebilir (bkz. tests/php/SyncGaunSourcesParserTest.php).
 */
function parse_meal_menu_html(string $html, string $sourceUrl, ?DateTimeImmutable $now = null): ?array
{
    $months = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran',
               'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    // "Bugün" Türkiye saatine göre hesaplanır (sunucu UTC olsa bile gece
    // yarısı penceresinde yanlış günün menüsü seçilmez).
    $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul'));
    $todayLabel = sprintf('%02d %s', (int) $now->format('j'), $months[(int) $now->format('n')]);

    $xp = new DOMXPath(make_dom($html));
    $cells = $xp->query('//td[contains(concat(" ", normalize-space(@class), " "), " day ")]');
    if ($cells === false || $cells->length === 0) {
        vlog('meal: gün hücreleri bulunamadı (sayfa yapısı değişmiş olabilir)');
        return null;
    }

    $items = null; // null = bugünün hücresi bulunamadı; [] = hücre var ama menü yok
    foreach ($cells as $td) {
        // Hücrenin gün etiketi (ilk badge div'i).
        $badge = $xp->query('.//div[contains(@class,"badge")]', $td)->item(0);
        if ($badge === null || clean_text($badge->textContent) !== $todayLabel) {
            continue;
        }
        $items = [];
        foreach ($xp->query('.//a[contains(@class,"a_modalinfo")]', $td) as $a) {
            $text = clean_text($a->textContent);
            if ($text === '') {
                continue;
            }
            // "MERCİMEK ÇORBA (166 kcal)" -> ad + kcal ayrıştır.
            $kcal = null;
            if (preg_match('/\((?:[^()]*?(\d+)\s*kcal)\)\s*$/iu', $text, $m)) {
                $kcal = (int) $m[1];
                $text = clean_text(preg_replace('/\s*\([^()]*\)\s*$/u', '', $text) ?? $text);
            }
            $items[] = ['name' => $text, 'kcal' => $kcal];
        }
        break;
    }

    if ($items === null) {
        vlog("meal: bugünün ({$todayLabel}) hücresi tabloda yok");
        return null;
    }

    $totalKcal = array_sum(array_map(fn ($i) => $i['kcal'] ?? 0, $items));
    return [
        'date'        => $now->format('Y-m-d'),
        'date_label'  => $todayLabel . ' ' . $now->format('Y'),
        'items'       => $items,                 // boş dizi = bugün menü yayınlanmamış
        'total_kcal'  => $totalKcal > 0 ? $totalKcal : null,
        'monthly_url' => $sourceUrl,             // aylık liste aynı sayfada
        'source_url'  => $sourceUrl,
        'fetched_at'  => gmdate('c'),
    ];
}

function collect_meal_menu(string $sourceUrl): ?array
{
    $res = fetch_url($sourceUrl);
    if (!$res['ok']) {
        vlog("meal: {$res['error']}");
        return null;
    }
    return parse_meal_menu_html($res['body'], $sourceUrl);
}

/**
 * Etkinlik takviminden yaklaşan etkinlikleri çeker.
 *
 * Sayfa yapısı: `listing.php` üzerinde her etkinlik bir
 * <div class="event"> bloğu; gün <div class="date">, ay+yıl
 * <div class="month">AyAdı<br>YYYY</div> (br sonrası metin ayrılmaz,
 * "AyAdıYYYY" biçiminde okunur), başlık <a class="link" href="javascript:ac(ID);">.
 * Saat/yer bilgisi yalnızca detay sayfasında (ac() ile açılan popup) serbest
 * metin içinde geçer; en iyi çaba ile "Saat:"/"Yer:" desenleriyle ayrıştırılır.
 * Detay sayfası çekilemezse o etkinlik yalnızca başlık/tarihle eklenir
 * (tüm koleksiyon iptal edilmez). Sayfa yapısı tamamen değişirse null
 * döner (eski cache korunur).
 */
/**
 * Etkinlik listesi sayfası HTML'ini ayrıştırır (saf fonksiyon — ağ erişimi
 * yok, detay sayfası çekmez). `$todayIso` verilmezse gerçek "bugün"
 * (Europe/Istanbul) kullanılır; testlerden deterministik bir tarih enjekte
 * edilebilir. Dönüş: `null` = sayfa yapısı tanınmadı (eski cache korunur);
 * `[]` = yapı doğru ayrıştırıldı, sadece yaklaşan etkinlik yok; aksi halde
 * ham {title,date,date_label,url} listesi (detay zenginleştirmesi
 * OLMADAN — bkz. parse_event_detail_html).
 */
function parse_events_listing_html(string $html, string $sourceUrl, ?string $todayIso = null): ?array
{
    $months = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran',
               'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
    $monthNumbers = array_flip($months); // 'Temmuz' => 7

    $xp = new DOMXPath(make_dom($html));
    $nodes = $xp->query('//div[contains(concat(" ", normalize-space(@class), " "), " event ")]');
    if ($nodes === false || $nodes->length === 0) {
        vlog('events: etkinlik blokları bulunamadı (sayfa yapısı değişmiş olabilir)');
        return null;
    }

    // "Bugün" Türkiye saatine göre; geçmiş etkinlikler bültende gösterilmez.
    $todayIso = $todayIso ?? (new DateTimeImmutable('now', new DateTimeZone('Europe/Istanbul')))->format('Y-m-d');

    $raw = [];
    foreach ($nodes as $node) {
        $day = $xp->query('.//div[contains(@class,"date")]', $node)->item(0);
        $mon = $xp->query('.//div[contains(@class,"month")]', $node)->item(0);
        $a   = $xp->query('.//div[contains(@class,"event-title")]//a', $node)->item(0);
        if ($day === null || $mon === null || $a === null) {
            continue;
        }

        // "Temmuz2026" -> ay adı + yıl (br metne boşluk katmadığı için bitişik).
        if (!preg_match('/^(\D+?)(\d{4})$/u', clean_text($mon->textContent), $mm)) {
            continue;
        }
        $monthNum = $monthNumbers[trim($mm[1])] ?? null;
        $dayNum   = (int) clean_text($day->textContent);
        $year     = (int) $mm[2];
        if ($monthNum === null || $dayNum < 1 || $dayNum > 31) {
            continue;
        }
        $iso = sprintf('%04d-%02d-%02d', $year, $monthNum, $dayNum);
        if ($iso < $todayIso) {
            continue; // geçmiş etkinlik; bültende gösterilmez
        }

        $title = trim(preg_replace("/^'+|'+\$/u", '', clean_text($a->textContent)) ?? '');
        if ($title === '') {
            continue;
        }

        $detailUrl = null;
        if (preg_match('/ac\((\d+)\)/', $a->getAttribute('href'), $idm)) {
            $detailUrl = preg_replace('#listing\.php$#', '', $sourceUrl)
                . 'main.php?po=calendar&op=calendar_only&fm_data[id]=' . $idm[1];
        }

        $raw[] = [
            'title'      => $title,
            'date'       => $iso,
            'date_label' => sprintf('%02d %s %d', $dayNum, $months[$monthNum], $year),
            'url'        => $detailUrl ?? $sourceUrl,
        ];
        if (count($raw) >= 12) {
            break;
        }
    }

    return $raw; // [] geçerli bir sonuçtur: yapı bulundu, yaklaşan etkinlik yok
}

/**
 * Bir etkinliğin detay (popup) sayfası HTML'inden saat/yer/açıklama/görsel
 * ayrıştırır (saf fonksiyon — ağ erişimi yok). `$detailUrl`, göreli görsel
 * yolunu mutlaklaştırmak için kullanılır.
 */
function parse_event_detail_html(string $html, string $detailUrl): array
{
    // Yalnızca asıl açıklama hücresini (.content) hedefle; sayfanın
    // <style> bloğu ve "Ekleyen/Kapat" altbilgisi bu şekilde dışarıda
    // kalır (strip_tags ham body üzerinde çalıştırılırsa stil bloğunun
    // METNİ de sızar — DOM ile taraması bunu önler).
    $dxp = new DOMXPath(make_dom($html));
    $contentNode = $dxp->query('//span[contains(@class,"content")]')->item(0);
    $desc = $contentNode !== null ? clean_text($contentNode->textContent) : '';

    $time = $location = $description = $image = null;
    if ($desc !== '') {
        $description = mb_strlen($desc, 'UTF-8') > 400
            ? mb_substr($desc, 0, 400, 'UTF-8') . '…'
            : $desc;
    }
    if (preg_match('/Saat\s*:?\s*([0-9]{1,2}[.:][0-9]{2})/u', $desc, $tm)) {
        $time = $tm[1];
    }
    if (preg_match('/Yer\s*:?\s*(.+?)(?:\s+Saat\s*:|\s*$)/u', $desc, $lm)) {
        $location = clean_text($lm[1]);
    }
    if ($contentNode !== null) {
        $img = $dxp->query('.//img', $contentNode)->item(0);
        if ($img !== null) {
            $image = absolutize($img->getAttribute('src'), $detailUrl);
        }
    }

    return ['time' => $time, 'location' => $location, 'description' => $description, 'image_url' => $image];
}

function collect_events(string $sourceUrl): ?array
{
    $res = fetch_url($sourceUrl);
    if (!$res['ok']) {
        vlog("events: {$res['error']}");
        return null;
    }

    $raw = parse_events_listing_html($res['body'], $sourceUrl);
    if ($raw === null) {
        return null;
    }
    if (!$raw) {
        return []; // yapı doğru ayrıştırıldı, sadece yaklaşan etkinlik yok
    }

    // Her etkinlik için detay sayfasından saat/yer/açıklama/görsel çekmeyi
    // dener; bir detay erişilemezse o etkinlik başlık/tarihle kalır (koleksiyonun
    // tamamı iptal edilmez).
    $now = gmdate('c');
    $events = [];
    foreach ($raw as $ev) {
        $detail = $ev['url'] !== $sourceUrl ? fetch_url($ev['url']) : ['ok' => false];
        $parsed = ['time' => null, 'location' => null, 'description' => null, 'image_url' => null];

        if ($detail['ok']) {
            $parsed = parse_event_detail_html($detail['body'], $ev['url']);
        } else {
            vlog("events: detay alınamadı [{$ev['title']}]: " . ($detail['error'] ?? 'atlandı'));
        }

        $events[] = [
            'title'       => $ev['title'],
            'date'        => $ev['date'],
            'date_label'  => $ev['date_label'],
            'time'        => $parsed['time'],
            'location'    => $parsed['location'],
            'description' => $parsed['description'],
            'url'         => $ev['url'],
            'image_url'   => $parsed['image_url'],
            'source_url'  => $sourceUrl,
            'fetched_at'  => $now,
        ];
    }

    // Tarihe göre artan sırala (en yakın etkinlik önce).
    usort($events, fn ($a, $b) => $a['date'] <=> $b['date']);

    return $events;
}

/** Öğrenci işleri sayfasından önemli bağlantılar (anahtar kelimeyle). */
function collect_important_links(string $sourceUrl): ?array
{
    $res = fetch_url($sourceUrl);
    if (!$res['ok']) {
        vlog("links: {$res['error']}");
        return null;
    }
    $keywords = ['AKADEMİK TAKVİM', 'DERS PROGRAM', 'KAYIT', 'HARÇ', 'DİPLOMA',
                 'BELGE', 'MEVZUAT', 'YÖNETMELİK', 'BAŞVURU'];
    $now = gmdate('c');
    $seen = [];
    $items = [];
    foreach (extract_links($res['body'], $sourceUrl) as $link) {
        $upper = mb_strtoupper($link['text'], 'UTF-8');
        $hit = false;
        foreach ($keywords as $kw) {
            if (str_contains($upper, $kw)) {
                $hit = true;
                break;
            }
        }
        if (!$hit) {
            continue;
        }
        $host = strtolower(parse_url($link['url'], PHP_URL_HOST) ?? '');
        if (!host_allowed($host) || isset($seen[$link['url']])) {
            continue;
        }
        $seen[$link['url']] = true;
        $items[] = [
            'label'      => $link['text'],
            'url'        => $link['url'],
            'source_url' => $sourceUrl,
            'fetched_at' => $now,
        ];
        if (count($items) >= 12) {
            break;
        }
    }
    return $items ?: null;
}

function looks_like_staff_link(string $text, string $url): bool
{
    $host = strtolower(parse_url($url, PHP_URL_HOST) ?? '');
    foreach (['akbis.', 'obs.', 'mail', 'rehber.', 'habermerkezi.', 'kvkk.'] as $blocked) {
        if (str_starts_with($host, $blocked) || str_contains($host, '.' . $blocked)) {
            return false;
        }
    }
    $hay = fold_text($text . ' ' . parse_url($url, PHP_URL_PATH) . ' ' . parse_url($url, PHP_URL_QUERY));
    foreach ([
        'akademik personel', 'idari personel', 'personelimiz', 'personel',
        'akademik kadro', 'ogretim eleman', 'ogretim uyeleri', 'yonetim kadromuz',
        'yonetim kurulu', 'dekanlik idari', 'akademisyen arama', 'kadromuz',
    ] as $needle) {
        if (str_contains($hay, $needle)) {
            return true;
        }
    }
    return false;
}

function looks_like_department_link(string $text, string $url): bool
{
    $hay = fold_text($text . ' ' . parse_url($url, PHP_URL_HOST));
    return str_contains($hay, 'bolumu')
        || str_contains($hay, 'bolumumuz')
        || str_contains($hay, 'anabilim dali')
        || str_contains($hay, 'muhendisligi')
        || str_contains($hay, 'egitimi')
        || str_contains($hay, 'programi');
}

function useful_staff_context(string $text, string $unitName): ?string
{
    $text = clean_text($text);
    if ($text === '') {
        return null;
    }
    $folded = fold_text($text);
    if (str_contains($folded, 'anasayfa') || str_contains($folded, 'hakkimizda')) {
        return null;
    }
    if (str_contains($folded, 'akademik personel') || str_contains($folded, 'idari personel')) {
        return null;
    }
    if (mb_strlen($text, 'UTF-8') > 120) {
        return null;
    }
    return $text !== $unitName ? $text : null;
}

function normalize_staff_title(string $title): string
{
    $folded = fold_text($title);
    return match (true) {
        str_starts_with($folded, 'prof') => 'Profesör',
        str_starts_with($folded, 'doc') => 'Doçent',
        str_contains($folded, 'doktor ogretim') || str_contains($folded, 'dr ogr') => 'Doktor Öğretim Üyesi',
        str_contains($folded, 'ogretim gorevlisi') => 'Öğretim Görevlisi',
        str_contains($folded, 'arastirma gorevlisi') || str_contains($folded, 'ars gor') => 'Araştırma Görevlisi',
        str_contains($folded, 'daire baskani') => 'Daire Başkanı',
        str_contains($folded, 'sube muduru') => 'Şube Müdürü',
        str_contains($folded, 'fakulte sekreteri') => 'Fakülte Sekreteri',
        str_contains($folded, 'yuksekokul sekreteri') => 'Yüksekokul Sekreteri',
        str_contains($folded, 'enstitu sekreteri') => 'Enstitü Sekreteri',
        str_contains($folded, 'dekan yardimcisi') => 'Dekan Yardımcısı',
        str_contains($folded, 'dekan') => 'Dekan',
        str_contains($folded, 'mudur yardimcisi') => 'Müdür Yardımcısı',
        str_contains($folded, 'mudur') => 'Müdür',
        str_contains($folded, 'bolum baskani') => 'Bölüm Başkanı',
        str_contains($folded, 'uzman') => 'Uzman',
        str_contains($folded, 'avukat') => 'Avukat',
        str_contains($folded, 'sekreter') => 'Sekreter',
        str_contains($folded, 'memur') => 'Memur',
        str_contains($folded, 'teknisyen') => 'Teknisyen',
        default => clean_text($title),
    };
}

function valid_staff_name(string $name): bool
{
    $name = clean_text($name);
    if (mb_strlen($name, 'UTF-8') < 5 || mb_strlen($name, 'UTF-8') > 80) {
        return false;
    }
    if (!preg_match('/\s/u', $name)) {
        return false;
    }
    $folded = fold_text($name);
    foreach ([
        'gaziantep universitesi', 'fakultesi', 'bolumu', 'personel', 'akademik',
        'idari', 'anasayfa', 'duyurular', 'haber', 'tumu', 'ogrenci',
        'dilleri ve edebiyatlari',
    ] as $bad) {
        if (str_contains($folded, $bad)) {
            return false;
        }
    }
    $tokens = explode(' ', $folded);
    if (count($tokens) > 2 && mb_strlen((string) end($tokens), 'UTF-8') <= 1) {
        return false;
    }
    return true;
}

function extract_staff_from_page(string $html, string $pageUrl, array $unit, ?string $context): array
{
    $text = clean_text(make_dom($html)->textContent);
    if ($text === '') {
        return [];
    }

    $titlePattern = '(Prof(?:\.|esör)?(?:\s*Dr\.?)?|Doç(?:\.|ent)?(?:\s*Dr\.?)?|Dr\.?\s*Öğr\.?\s*Üyesi|Doktor\s+Öğretim\s+Üyesi|Öğretim\s+Görevlisi|Araştırma\s+Görevlisi|Arş\.?\s*Gör\.?|Daire\s+Başkanı|Şube\s+Müdürü|Fakülte\s+Sekreteri|Yüksekokul\s+Sekreteri|Enstitü\s+Sekreteri|Dekan\s+Yardımcısı|Dekan|Müdür\s+Yardımcısı|Müdür|Bölüm\s+Başkanı|Sekreter|Memur|Teknisyen|Uzman|Avukat)';
    $namePattern = '((?:[A-ZÇĞİÖŞÜÂÎÛ][A-ZÇĞİÖŞÜÂÎÛ\'.-]{0,30}\s+){1,5}[A-ZÇĞİÖŞÜÂÎÛ][A-ZÇĞİÖŞÜÂÎÛ\'.-]{1,30})';
    if (!preg_match_all('/\b' . $titlePattern . '\s+' . $namePattern . '/u', $text, $matches, PREG_SET_ORDER)) {
        return [];
    }

    $sourceHost = strtolower(parse_url($pageUrl, PHP_URL_HOST) ?? '');
    $context = useful_staff_context((string) $context, (string) $unit['name']);
    $department = $context;
    if ($department !== null) {
        $foldedContext = fold_text($department);
        if (!str_contains($foldedContext, 'bolum')
            && !str_contains($foldedContext, 'anabilim')
            && !str_contains($foldedContext, 'muhendisligi')
            && !str_contains($foldedContext, 'egitimi')
            && !str_contains($foldedContext, 'program')) {
            $department = null;
        }
    }

    $out = [];
    $seen = [];
    foreach ($matches as $m) {
        $title = normalize_staff_title($m[1]);
        $name = clean_text(preg_replace('/\s+E-?\s*$/u', '', $m[2]) ?? $m[2]);
        if (!valid_staff_name($name)) {
            continue;
        }
        $key = fold_text($title . ' ' . $name . ' ' . ($department ?? '') . ' ' . $unit['name']);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = [
            'name'            => $name,
            'title'           => $title,
            'unit_name'       => (string) $unit['name'],
            'department_name' => $department,
            'unit_website'    => (string) $unit['website'],
            'source_url'      => $pageUrl,
            'source_host'     => $sourceHost,
            'verified_at'     => gmdate('c'),
            'verified_by'     => 'official_staff_page',
        ];
    }
    return $out;
}

function discover_staff_pages_for_unit(array $unit): array
{
    $home = (string) ($unit['website'] ?? '');
    if ($home === '') {
        return [];
    }
    $res = fetch_url($home);
    if (!$res['ok']) {
        vlog("staff {$unit['name']}: ana sayfa alınamadı ({$res['error']})");
        return [];
    }

    $pages = [];
    $deptHomes = [];
    $homeHost = strtolower(parse_url($home, PHP_URL_HOST) ?? '');
    foreach (extract_links($res['body'], $home) as $link) {
        $host = strtolower(parse_url($link['url'], PHP_URL_HOST) ?? '');
        if (!host_allowed($host)) {
            continue;
        }
        $sameHostDepartmentPage = $host === $homeHost
            && looks_like_department_link($link['text'], $link['url'])
            && str_contains(fold_text((string) parse_url($link['url'], PHP_URL_QUERY)), 'url');
        if (looks_like_staff_link($link['text'], $link['url']) || $sameHostDepartmentPage) {
            $pages[$link['url']] = useful_staff_context($link['text'], (string) $unit['name']);
        } elseif (looks_like_department_link($link['text'], $link['url'])) {
            $deptHomes[$link['url']] = useful_staff_context($link['text'], (string) $unit['name']);
        }
        if (count($pages) >= 80) {
            break;
        }
    }

    // Çoğu GAÜN birim sitesinde personel sayfaları ana menüde zaten vardır.
    // Bölüm ana sayfalarına dalmak pahalı olduğu için yalnız hiç aday yoksa
    // sınırlı fallback yapılır.
    if (!$pages) {
        $deptCount = 0;
        foreach ($deptHomes as $deptUrl => $deptName) {
            if (++$deptCount > 15) {
                break;
            }
            $dept = fetch_url($deptUrl);
            if (!$dept['ok']) {
                continue;
            }
            foreach (extract_links($dept['body'], $deptUrl) as $link) {
                $host = strtolower(parse_url($link['url'], PHP_URL_HOST) ?? '');
                if (!host_allowed($host) || !looks_like_staff_link($link['text'], $link['url'])) {
                    continue;
                }
                $pages[$link['url']] = $deptName ?: useful_staff_context($link['text'], (string) $unit['name']);
                if (count($pages) >= 25) {
                    break 2;
                }
            }
        }
    }

    return $pages;
}

function collect_staff(array $units): ?array
{
    $records = [];
    $seenRecords = [];
    $seenPages = [];
    $unitCount = 0;

    foreach ($units as $unit) {
        if (!is_array($unit) || !isset($unit['name'], $unit['website'])) {
            continue;
        }
        if (($unit['staff_source'] ?? true) === false) {
            continue;
        }
        if (++$unitCount > 120) {
            break;
        }
        foreach (discover_staff_pages_for_unit($unit) as $pageUrl => $context) {
            if (isset($seenPages[$pageUrl])) {
                continue;
            }
            $seenPages[$pageUrl] = true;
            $res = fetch_url($pageUrl);
            if (!$res['ok']) {
                vlog("staff page {$pageUrl}: {$res['error']}");
                continue;
            }
            foreach (extract_staff_from_page($res['body'], $pageUrl, $unit, $context) as $staff) {
                $affiliation = trim((string) ($staff['department_name'] ?? '')) ?: (string) $staff['unit_name'];
                $key = fold_text($staff['name'] . '|' . $staff['title'] . '|' . $affiliation);
                if (isset($seenRecords[$key])) {
                    continue;
                }
                $seenRecords[$key] = true;
                $records[] = $staff;
                if (count($records) >= 2500) {
                    return $records;
                }
            }
        }
    }

    return $records ?: null;
}

// ---------------------------------------------------------------------------
// Ana akış
// ---------------------------------------------------------------------------

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
$old = [];
if (is_readable(CACHE_FILE)) {
    $old = json_decode((string) file_get_contents(CACHE_FILE), true) ?: [];
}

$plan = [
    'units'           => fn () => collect_units('https://www.gaziantep.edu.tr/'),
    'announcements'   => fn () => collect_announcements('https://www.gaziantep.edu.tr/'),
    'news'            => fn () => collect_news('https://habermerkezi.gaziantep.edu.tr/'),
    'meal_menu'       => fn () => collect_meal_menu('https://www.gaziantep.edu.tr/yemek/'),
    'important_links' => fn () => collect_important_links('https://oidb.gaziantep.edu.tr/'),
    'events'          => fn () => collect_events('https://etkinlik.gaziantep.edu.tr/listing.php'),
];

$cache = ['generated_at' => gmdate('c'), 'sections' => []];
$okCount = 0;

foreach ($plan as $section => $collector) {
    try {
        $data = $collector();
    } catch (Throwable $e) {
        vlog("{$section}: istisna: {$e->getMessage()}");
        $data = null;
    }
    if ($data !== null) {
        $cache[$section] = $data;
        $cache['sections'][$section] = ['status' => 'fresh'];
        $okCount++;
        $n = is_array($data) && array_is_list($data) ? count($data) : 1;
        echo "✓ {$section}: {$n} kayıt\n";
    } elseif (isset($old[$section])) {
        // Kaynak erişilemedi: eski cache bölümünü koru.
        $cache[$section] = $old[$section];
        $cache['sections'][$section] = ['status' => 'stale (eski cache korundu)'];
        echo "! {$section}: kaynak erişilemedi, eski cache korundu\n";
    } else {
        $cache['sections'][$section] = ['status' => 'unavailable'];
        echo "✗ {$section}: veri yok\n";
    }
}

$staffUnits = $cache['units'] ?? $old['units'] ?? [];
try {
    $staffData = is_array($staffUnits) ? collect_staff($staffUnits) : null;
} catch (Throwable $e) {
    vlog("staff: istisna: {$e->getMessage()}");
    $staffData = null;
}
if ($staffData !== null) {
    $cache['staff'] = $staffData;
    $cache['sections']['staff'] = ['status' => 'fresh'];
    $okCount++;
    echo "✓ staff: " . count($staffData) . " kayıt\n";
} elseif (isset($old['staff'])) {
    $cache['staff'] = $old['staff'];
    $cache['sections']['staff'] = ['status' => 'stale (eski cache korundu)'];
    echo "! staff: kaynak erişilemedi, eski cache korundu\n";
} else {
    $cache['sections']['staff'] = ['status' => 'unavailable'];
    echo "✗ staff: veri yok\n";
}

// Atomik yazma
$dir = dirname(CACHE_FILE);
if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
    fwrite(STDERR, "HATA: cache dizini oluşturulamadı: {$dir}\n");
    exit(1);
}
$tmp = CACHE_FILE . '.tmp';
if (file_put_contents($tmp, json_encode($cache, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) === false
    || !rename($tmp, CACHE_FILE)) {
    fwrite(STDERR, "HATA: cache yazılamadı: " . CACHE_FILE . "\n");
    exit(1);
}
@chmod(CACHE_FILE, 0644);

echo "Cache yazıldı: " . CACHE_FILE . "\n";
exit($okCount > 0 || $old !== [] ? 0 : 1);
}
