// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * Canlı rota (OSRM) uçtan uca testi.
 *
 * ÖN KOŞUL: bu dosya, uygulamanın VE osrm-car/osrm-foot servislerinin
 * ZATEN AYAKTA olduğunu varsayar — hiçbir servis başlatma/health-check
 * yapmaz (bkz. README "Testler" > "Canlı rota (OSRM) testleri"):
 *   docker compose up -d db web osrm-car osrm-foot
 * (osrm-car/osrm-foot veri hazır değilse crash-loop eder — README "Kendi
 * barındırılan OSRM" bölümündeki `osrm-download`/`osrm-process` adımları
 * bir kez çalıştırılmış olmalı.)
 *
 * Yerel OSRM URL'leri yapılandırılmışsa "gerçek OSRM" testleri özellikle OSRM
 * BAŞARISINI gösteren metni arar (`t('route.result')` -> "... rotası ...").
 * Servis kapalıysa veya erişilemezse testler kırmızı olur. Public OSRM demo
 * modunda ise bu grup açıklamalı skip edilir; fallback testleri yine çalışır.
 */

const KNOWN_LOCATION_SEARCH_TERM = 'Bilgi İşlem';
// Kampüsün içinde, gerçek yol ağında ~700m'lik kısa/deterministik bir mesafe
// sağlayan bilinen bir nokta (bu oturumda curl/Playwright ile doğrulandı).
const MOCK_USER_LOCATION = { latitude: 37.03535, longitude: 37.31356 };

async function waitForAppReady(page) {
  await page.waitForSelector('#quickIntents .quick-chip', { timeout: 10_000 });
}

/** Arama üzerinden bilinen bir konumu seçip yön kartını açar. */
async function selectKnownLocation(page) {
  await waitForAppReady(page);
  await page.locator('#search').fill(KNOWN_LOCATION_SEARCH_TERM);
  const firstResult = page.locator('#searchResults .search-item').first();
  await expect(firstResult).toBeVisible({ timeout: 10_000 });
  await firstResult.click();
  await expect(page.locator('#directionsCard')).toBeVisible();
}

/** "Konumumu Kullan" butonuna basar (canlı takibi başlatır -> rota isteği tetiklenir). */
async function useMyLocation(page) {
  await page.locator('#dirGo').click();
}

// Gerçek rota çizgisi (L.geoJSON) VE düz-çizgi fallback'i (L.polyline) SVG
// path olarak render olur; kullanıcı konumu doğruluk dairesi de bir path'tir
// ama uygulama onu `interactive: false` ile çiziyor (bkz. map.js), bu yüzden
// ".leaflet-interactive" filtresi rota çizgisini güvenilir şekilde ayırt eder.
const ROUTE_LINE_SELECTOR = '.leaflet-overlay-pane path.leaflet-interactive';

async function requireLocalOsrmConfig(page, modes = ['walk', 'drive']) {
  const routing = await page.evaluate(() => window.GAUN_CONFIG?.routing || {});
  const baseUrls = routing.baseUrls || {};
  const required = {
    walk: ':5002',
    drive: ':5001',
  };
  const missing = modes.filter(mode => !String(baseUrls[mode] || '').includes(required[mode]));
  test.skip(
    missing.length > 0,
    `Yerel OSRM URL'leri yapılandırılmamış (${missing.join(', ')}). ` +
    'Self-hosted rota testi için OSRM_WALK_BASE_URL=http://localhost:5002 ve ' +
    'OSRM_DRIVE_BASE_URL=http://localhost:5001 ayarlayın.'
  );
}

test.describe('Canlı rota — gerçek OSRM (osrm-car / osrm-foot)', () => {
  test.beforeEach(async ({ context }) => {
    await context.grantPermissions(['geolocation']);
    await context.setGeolocation(MOCK_USER_LOCATION);
  });

  test('Yürüme modunda gerçek osrm-foot rotası çiziliyor ve ETA metni gösteriliyor', async ({ page }) => {
    await page.goto('/');
    await requireLocalOsrmConfig(page, ['walk']);
    await selectKnownLocation(page);
    // Yürüme, varsayılan aktif moddur; yine de açıkça doğrula.
    await expect(page.locator('.route-mode[data-mode="walk"]')).toHaveClass(/active/);

    const osrmResponsePromise = page.waitForResponse(
      (res) => res.url().includes(':5002/route/v1/foot/'),
      { timeout: 10_000 }
    );
    await useMyLocation(page);

    const osrmResponse = await osrmResponsePromise;
    expect(
      osrmResponse.ok(),
      `osrm-foot (localhost:5002) isteği başarısız: HTTP ${osrmResponse.status()}. ` +
      'Servis ayakta mı? docker compose up -d osrm-foot'
    ).toBeTruthy();

    const stats = page.locator('#dirStats');
    await expect(stats).not.toHaveText('', { timeout: 10_000 });
    await expect(stats).toContainText('rotası');
    await expect(stats).not.toContainText('Rota servisine ulaşılamadı');

    // Gerçek rota çizgisi (L.geoJSON) haritada render olmalı.
    await expect(page.locator(ROUTE_LINE_SELECTOR).first()).toBeVisible();
  });

  test('Araç modunda gerçek osrm-car rotası çiziliyor ve ETA metni gösteriliyor', async ({ page }) => {
    await page.goto('/');
    await requireLocalOsrmConfig(page, ['drive']);
    await selectKnownLocation(page);
    await page.locator('.route-mode[data-mode="drive"]').click();
    await expect(page.locator('.route-mode[data-mode="drive"]')).toHaveClass(/active/);

    const osrmResponsePromise = page.waitForResponse(
      (res) => res.url().includes(':5001/route/v1/driving/'),
      { timeout: 10_000 }
    );
    await useMyLocation(page);

    const osrmResponse = await osrmResponsePromise;
    expect(
      osrmResponse.ok(),
      `osrm-car (localhost:5001) isteği başarısız: HTTP ${osrmResponse.status()}. ` +
      'Servis ayakta mı? docker compose up -d osrm-car'
    ).toBeTruthy();

    const stats = page.locator('#dirStats');
    await expect(stats).not.toHaveText('', { timeout: 10_000 });
    await expect(stats).toContainText('rotası');
    await expect(stats).not.toContainText('Rota servisine ulaşılamadı');

    await expect(page.locator(ROUTE_LINE_SELECTOR).first()).toBeVisible();
  });

  test('Yürüme ve araç rotaları farklı OSRM profillerinden geliyor (gerçek ayrım)', async ({ page }) => {
    // Genel public OSRM demosu profili yok sayıp tek tip (araç) rota
    // döndürür (bkz. README "Kendi barındırılan OSRM" — bu projenin
    // self-hosted osrm-car/osrm-foot'u eklemesinin asıl nedeni). Bu test,
    // iki modun GERÇEKTEN farklı sunuculara/profillere gittiğini kanıtlar.
    await page.goto('/');
    await requireLocalOsrmConfig(page);
    await selectKnownLocation(page);

    const footPromise = page.waitForResponse((res) => res.url().includes(':5002/route/v1/foot/'), { timeout: 10_000 });
    await useMyLocation(page);
    const footResponse = await footPromise;
    expect(footResponse.ok()).toBeTruthy();

    // Not: dinleyici, tetikleyici tıklamadan ÖNCE kurulmalı — moda geçiş
    // isteği hemen (senkrona yakın) tetiklediğinden, sıralama tersine
    // çevrilirse istek dinleyici kurulmadan önce gidip yarış koşulu oluşur.
    const carPromise = page.waitForResponse((res) => res.url().includes(':5001/route/v1/driving/'), { timeout: 10_000 });
    await page.locator('.route-mode[data-mode="drive"]').click();
    const carResponse = await carPromise;
    expect(carResponse.ok()).toBeTruthy();

    // Farklı port/profile -> farklı OSRM servisine gittiğinin kanıtı.
    expect(footResponse.url()).toContain(':5002/');
    expect(carResponse.url()).toContain(':5001/');
  });
});

test.describe('Düz çizgi (kuş uçuşu) fallback — OSRM erişilemezken', () => {
  test.beforeEach(async ({ context }) => {
    await context.grantPermissions(['geolocation']);
    await context.setGeolocation(MOCK_USER_LOCATION);
  });

  test('OSRM isteği başarısız olunca kuş uçuşu düz çizgi + net hata mesajı gösteriliyor', async ({ page }) => {
    // OSRM'e giden İSTEĞİ BİLEREK engelliyoruz — osrm-car/osrm-foot'un o an
    // gerçekten çalışıp çalışmadığından bağımsız, deterministik bir fallback
    // senaryosu (mevcut düz-çizgi davranışının regresyonunu yakalamak için).
    await page.route('**/route/v1/**', (route) => route.abort('connectionfailed'));

    await page.goto('/');
    await selectKnownLocation(page);
    await useMyLocation(page);

    const stats = page.locator('#dirStats');
    await expect(stats).toContainText('Rota servisine ulaşılamadı', { timeout: 10_000 });
    await expect(stats).toContainText('kuş uçuşu');
    await expect(stats).not.toHaveText('');

    // Fallback çizgisi kesiklidir (dashArray: '10, 8' — bkz. routing.js
    // drawFallbackLine), gerçek rota çizgisinden (düz çizgi) bu şekilde
    // ayırt edilir.
    const fallbackLine = page.locator(ROUTE_LINE_SELECTOR).first();
    await expect(fallbackLine).toBeVisible();
    const dashArray = await fallbackLine.getAttribute('stroke-dasharray');
    expect(dashArray, 'fallback çizgisi kesikli (dashArray) olmalı').not.toBeNull();
  });

  test('yavaş/zaman aşımına uğrayan OSRM isteği de fallback\'e düşürüyor', async ({ page }) => {
    test.setTimeout(25_000);
    // Gerçek zaman aşımı süresini (window.GAUN_CONFIG.routing.timeoutMs,
    // varsayılan 4000ms) ve artık sırayla denenen yedek OSRM adaylarını
    // doğruluyoruz: ilk aday zaman aşımına uğrarsa uygulama sonraki adayı
    // dener, hepsi sessiz kalırsa kesik fallback'e düşer.
    await page.route('**/route/v1/**', async (route) => {
      // Sunucu hiç yanıt vermiyormuş gibi isteği askıda bırak; uygulamanın
      // kendi timeout'u (AbortController) devreye girip fallback'e düşecek.
      await new Promise(() => {}); // asla resolve olmaz
    });

    await page.goto('/');
    await selectKnownLocation(page);
    await useMyLocation(page);

    const stats = page.locator('#dirStats');
    // Varsayılan 4000ms timeout, yerel + genel OSRM adayları ve biraz pay.
    await expect(stats).toContainText('Rota servisine ulaşılamadı', { timeout: 16_000 });
    await expect(stats).toContainText('zaman aşımı');
  });
});
