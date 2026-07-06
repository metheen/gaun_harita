// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * Duman testi (smoke test): uygulamanın en temel yolunun çalıştığını
 * doğrular. Gerçek Docker yığınına (web + db + OSRM) karşı çalışır — bkz.
 * README "Testler" bölümü için ön koşullar. Derinlemesine davranış testi
 * değildir; amaç "site tamamen bozuldu mu?" sorusuna hızlı cevap vermektir.
 */

// Arama/Keşfet paneli get_locations.php'nin asenkron fetch'i bitene kadar
// boştur (searchList/campusData henüz dolmamış olabilir). Bu bekleme
// olmadan arama testleri yarış koşuluna girip yanlışlıkla "0 sonuç"
// görür — bu, uygulamanın değil testin kendisinin doğru sıralamaması olur.
async function waitForAppReady(page) {
  await page.waitForSelector('#quickIntents .quick-chip', { timeout: 10_000 });
}

async function mockLocations(page, data) {
  await page.route('**/get_locations.php', route => route.fulfill({
    status: 200,
    contentType: 'application/json; charset=utf-8',
    body: JSON.stringify(data),
  }));
}

test.beforeEach(async ({ page }) => {
  await page.route('**/analytics.php', route => route.fulfill({ status: 204, body: '' }));
});

test.describe('Ana sayfa ve harita', () => {
  test('ana sayfa açılıyor ve harita container görünüyor', async ({ page }) => {
    const response = await page.goto('/');
    expect(response?.ok()).toBeTruthy();
    await expect(page.locator('#map')).toBeVisible();
    // Leaflet en az bir tile yükleyip haritayı gerçekten render etmeli
    // (pane container'ları normalde 0x0 boyutludur, tile'lar asıl görseli
    // taşır — bu yüzden pane değil, yüklenmiş tile'ı doğruluyoruz).
    await expect(page.locator('.leaflet-tile-loaded').first()).toBeVisible({ timeout: 10_000 });
  });

  test('arama kutusu bir sorgu için sonuç listesi döner', async ({ page }) => {
    await page.goto('/');
    await waitForAppReady(page);
    await page.locator('#search').fill('kütüphane');
    const results = page.locator('#searchResults .search-item');
    await expect(results.first()).toBeVisible({ timeout: 10_000 });
    expect(await results.count()).toBeGreaterThan(0);
  });

  test('çoğul kategori aramaları tekil kayıtları bulur', async ({ page }) => {
    await mockLocations(page, [
      {
        id: 920001,
        name: 'Akademik Binalar',
        type: 'Kategori',
        color: 'blue',
        children: [
          {
            id: 920002,
            name: 'Test Mühendislik Fakültesi',
            type: 'Fakülte',
            coords: [37.0367, 37.3129],
          },
          {
            id: 920003,
            name: 'Test Bilgisayar Mühendisliği Bölümü',
            type: 'Bölüm',
            coords: [37.0368, 37.313],
          },
        ],
      },
    ]);

    await page.goto('/');
    await waitForAppReady(page);

    await page.locator('#search').fill('Fakülteler');
    await expect(page.locator('#searchResults .search-item').first()).toContainText('Test Mühendislik Fakültesi');
    await expect(page.locator('#searchResults')).not.toContainText('Sonuç bulunamadı');

    await page.locator('#search').fill('Bölümler');
    await expect(page.locator('#searchResults .search-item').first()).toContainText('Test Bilgisayar Mühendisliği Bölümü');
    await expect(page.locator('#searchResults')).not.toContainText('Sonuç bulunamadı');
  });

  test('arama sonucuna tıklayınca konum kartı açılır', async ({ page }) => {
    await page.goto('/');
    await waitForAppReady(page);
    await page.locator('#search').fill('kütüphane');
    const firstResult = page.locator('#searchResults .search-item').first();
    await expect(firstResult).toBeVisible({ timeout: 10_000 });
    await firstResult.click();
    await expect(page.locator('#directionsCard')).toBeVisible();
    await expect(page.locator('#dirName')).not.toHaveText('');
  });
});

test.describe('Dil (TR/EN) geçişi', () => {
  test('TR->EN geçişinde başlık ve arama yer tutucusu İngilizce olur', async ({ page }) => {
    await page.goto('/');
    await expect(page.locator('#search')).toHaveAttribute('placeholder', 'Bina, fakülte veya yer arayın...');

    await page.locator('#langToggle').click();

    await expect(page).toHaveTitle('GAUN Campus Map - Wayfinding');
    await expect(page.locator('#search')).toHaveAttribute('placeholder', 'Search for a building, faculty, or place...');
    await expect(page.locator('#langToggleLabel')).toHaveText('EN');
  });

  test('EN->TR geri geçişi orijinal Türkçe metni geri getirir', async ({ page }) => {
    await page.goto('/');
    await page.locator('#langToggle').click(); // TR -> EN
    await page.locator('#langToggle').click(); // EN -> TR
    await expect(page).toHaveTitle('GAÜN Kampüs Haritası - Yol Bulma');
    await expect(page.locator('#langToggleLabel')).toHaveText('TR');
  });

  test('EN modda veritabanı İngilizce adıyla arama, yön kartı ve popup açıklaması çalışır', async ({ page }) => {
    await mockLocations(page, [
      {
        id: 910001,
        name: 'Akademik Binalar',
        type: 'Kategori',
        color: 'blue',
        children: [
          {
            id: 910002,
            name: 'Özel Test Binası',
            name_en: 'Special Test Building',
            type: 'Bina',
            coords: [37.0367, 37.3129],
            description: 'Türkçe test açıklaması',
            description_text: 'Türkçe test açıklaması',
            description_en: 'English test description',
            description_en_text: 'English test description',
          },
        ],
      },
    ]);

    await page.goto('/');
    await waitForAppReady(page);
    await page.locator('#langToggle').click();
    await expect(page.locator('#langToggleLabel')).toHaveText('EN');

    await page.locator('#search').fill('Special Test Building');
    const firstResult = page.locator('#searchResults .search-item').first();
    await expect(firstResult).toBeVisible({ timeout: 10_000 });
    await expect(firstResult).toContainText('Special Test Building');

    await firstResult.click();
    await expect(page.locator('#directionsCard')).toBeVisible();
    await expect(page.locator('#dirName')).toHaveText('Special Test Building');
    await expect(page.locator('.leaflet-popup-content')).toContainText('English test description');
    await expect(page.locator('.leaflet-popup-content')).not.toContainText('Türkçe test açıklaması');
  });
});

test.describe('Kampüs asistanı', () => {
  async function resetAssistant(page) {
    await page.evaluate(() => {
      document.querySelector('#assistantMessages').innerHTML = '';
      document.querySelector('#assistantOptions').innerHTML = '';
      document.querySelector('#assistantOptions').classList.add('hidden');
      window.App.state.assistantContext = '';
      window.App.state.assistantPending = null;
    });
  }

  async function askAssistant(page, text) {
    await page.locator('#assistantInput').fill(text);
    await page.locator('#assistantForm button[type="submit"]').click();
  }

  test('yemek niyetlerinde KYK/yurt yerine doğru yemek yerini önerir', async ({ page }) => {
    await page.goto('/');
    await waitForAppReady(page);
    await page.locator('#assistantToggle').click();

    await askAssistant(page, 'öğrenci yemekhanesi');
    await expect(page.locator('#dirName')).toHaveText('Merkez Öğrenci Yemekhanesi', { timeout: 10_000 });
    await expect(page.locator('#assistantMessages')).not.toContainText('KYK');
    await expect(page.locator('#assistantMessages')).not.toContainText('Yurdu');

    await resetAssistant(page);
    await askAssistant(page, 'personel yemekhanesi');
    await expect(page.locator('#dirName')).toHaveText('Merkez Personel Yemekhanesi', { timeout: 10_000 });
    await expect(page.locator('#assistantMessages')).not.toContainText('Gıda Mühendisliği');

    await resetAssistant(page);
    await askAssistant(page, 'açım');
    await expect(page.locator('#assistantOptions .assistant-option')).toContainText([
      'Öğrenci Yemekhanesi',
      'Personel Yemekhanesi',
      'Kafe / Atıştırmalık',
      'Hepsini göster',
    ]);
    await page.locator('#assistantOptions .assistant-option', { hasText: 'Öğrenci Yemekhanesi' }).click();
    await expect(page.locator('#dirName')).toHaveText('Merkez Öğrenci Yemekhanesi', { timeout: 10_000 });
  });

  test('asistanın açtığı konum popupından canlı rota başlatılır', async ({ page, context }) => {
    await context.grantPermissions(['geolocation']);
    await context.setGeolocation({ latitude: 37.03535, longitude: 37.31356 });
    await page.route('**/route/v1/**', route => route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        code: 'Ok',
        routes: [{
          distance: 64,
          duration: 60,
          geometry: {
            type: 'LineString',
            coordinates: [
              [37.31356, 37.03535],
              [37.3132519, 37.035369],
            ],
          },
        }],
      }),
    }));

    const pageErrors = [];
    page.on('pageerror', error => pageErrors.push(error.message));

    await page.goto('/');
    await waitForAppReady(page);
    await page.locator('#assistantToggle').click();
    await page.locator('#assistantInput').fill('öğrenci işleri nereye');
    await page.locator('#assistantForm button[type="submit"]').click();

    await expect(page.locator('.leaflet-popup-content .popup-route-map')).toBeVisible({ timeout: 10_000 });
    await page.locator('.leaflet-popup-content .popup-route-map').click();

    await expect(page.locator('#dirStats')).toContainText('Yürüme rotası', { timeout: 10_000 });
    expect(pageErrors).toEqual([]);
  });
});

test.describe('Karanlık mod', () => {
  test('karanlık modda temel metinler okunabilir kontrastta render olur', async ({ page }) => {
    await page.goto('/');
    await page.locator('#themeToggle').click();

    await expect(page.locator('html')).toHaveClass(/dark/);

    // Karanlık mod CSS'i (index.html <style>) kenar çubuğu başlığı gibi
    // temel metin öğelerine "color: #fff !important" uygular. Beyaza yakın
    // olmayan bir renk kalırsa (ör. koyu metin koyu zemin üstünde) bu test
    // kırmızı okunabilirlik regresyonunu yakalar.
    await page.locator('#panelToggle').click(); // çekmeceyi gerçekten aç
    const sidebarHeading = page.locator('#sidebar h1');
    await expect(sidebarHeading).toBeVisible();
    const color = await sidebarHeading.evaluate((el) => getComputedStyle(el).color);
    expect(color).toBe('rgb(255, 255, 255)');

    const bodyBg = await page.locator('body').evaluate((el) => getComputedStyle(el).backgroundColor);
    expect(bodyBg).not.toBe('rgb(255, 255, 255)');
  });
});

test.describe('Mobil düzen (375px telefon)', () => {
  test.use({ viewport: { width: 375, height: 812 } });

  test('arama kutusu tam genişlik satırına iner ve sonuçlar okunur genişlikte açılır', async ({ page }) => {
    // Regresyon koruması: dar ekranda üst bardaki 4 sabit buton arama
    // kutusunu ~120px'e sıkıştırıyor, arama sonuçları da aynı genişlikte
    // açılıp konum adları tek harfe kadar kesiliyordu (bkz. index.html
    // "Mobil üst bar" media query'si). Arama alanı en az ~300px olmalı.
    await page.goto('/');
    await page.waitForSelector('#quickIntents .quick-chip', { timeout: 10_000 });

    const searchWidth = await page.locator('#search').evaluate(
      (el) => el.getBoundingClientRect().width
    );
    expect(searchWidth, 'mobilde arama kutusu tam genişlik satırında olmalı').toBeGreaterThan(300);

    await page.locator('#search').fill('Kütüphane');
    const firstResult = page.locator('#searchResults .search-item').first();
    await expect(firstResult).toBeVisible({ timeout: 10_000 });

    const resultsWidth = await page.locator('#searchResults').evaluate(
      (el) => el.getBoundingClientRect().width
    );
    expect(resultsWidth, 'arama sonuçları dropdown\'ı da tam genişlik olmalı').toBeGreaterThan(300);

    // Yatay taşma hiçbir zaman olmamalı (gövde yatay scroll üretmez).
    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - window.innerWidth
    );
    expect(overflow, 'sayfa gövdesi yatay taşmamalı').toBeLessThanOrEqual(0);
  });
});
