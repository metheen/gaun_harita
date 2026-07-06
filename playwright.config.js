// @ts-check
const { defineConfig } = require('@playwright/test');

// Uygulama zaten `docker compose up -d web` ile localhost:8080'de çalışıyor
// olmalı (bkz. README "Testler"). Bu dosya bir dev-server başlatmaz —
// projenin PHP/MariaDB/OSRM yığını Docker dışında Playwright tarafından
// yönetilemeyecek kadar ağır (bkz. tests/e2e/README testleri hakkında not).
module.exports = defineConfig({
  testDir: './tests/e2e',
  timeout: 15_000,
  fullyParallel: true,
  retries: 0,
  reporter: [['list']],
  // admin.spec.js için tek seferlik test-only admin hesabı kurar/temizler
  // (bkz. global-setup.js, global-teardown.js) — gerçek "admin" hesabına
  // dokunmaz.
  globalSetup: require.resolve('./tests/e2e/global-setup.js'),
  globalTeardown: require.resolve('./tests/e2e/global-teardown.js'),
  use: {
    baseURL: process.env.GAUN_BASE_URL || 'http://localhost:8080',
    // Test trafiğini analitik/audit dışında tutmak için User-Agent'a
    // "Playwright" işareti eklenir — sunucu tarafı tespit bunu zaten tanır
    // (bkz. analytics.php analytics_is_automation_text: 'playwright').
    //
    // NEDEN extraHTTPHeaders DEĞİL: özel bir başlık (X-GAUN-Test-Automation)
    // tarayıcının TÜM isteklerine eklenir ve cross-origin fetch'leri
    // (localhost:5001/5002 OSRM, fonts.gstatic.com) "basit istek" olmaktan
    // çıkarıp CORS preflight'a sokar; OSRM bu başlığa izin vermediğinden
    // rota istekleri engellenir ve gerçek-OSRM e2e testleri düşer.
    // User-Agent ise tarayıcı-denetimli bir başlıktır, preflight tetiklemez.
    userAgent:
      'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 ' +
      '(KHTML, like Gecko) Chrome/129.0.0.0 Safari/537.36 Playwright-E2E/1.0',
    trace: 'retain-on-failure',
  },
});
