// @ts-check
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');

/**
 * Admin panel (bordo/) uçtan uca testleri: gerçek login + CSRF + POST
 * dispatcher akışı. Hiçbir yerde token/oturum bypass edilmez — CSRF jetonu
 * her zaman o anki render edilmiş formdan (page.locator ile) okunur/gönderilir,
 * Playwright'ın kendi form doldurma/submit mekanizması kullanılır.
 *
 * Test kullanıcısı: global-setup.js tarafından oluşturulan, rastgele şifreli,
 * yalnızca bu test koşumu için var olan "_e2e_playwright_admin" hesabı
 * (bkz. o dosyadaki not — gerçek "admin" hesabına dokunulmaz).
 *
 * Oluşturulan konum kayıtları: her testte Date.now()+random ile benzersiz
 * isim kullanılır VE test sonunda panelin kendi "Sil" akışıyla temizlenir
 * (gerçek silme dispatcher'ını da dolaylı olarak test eder).
 */

const ADMIN_BASE = '/bordo/';

function uniqueName(label) {
  return `_E2E_${label}_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`;
}

async function login(page, { username, password } = {}) {
  await page.goto(ADMIN_BASE + 'index.php', { waitUntil: 'domcontentloaded' });
  await page.locator('input[name="username"]').fill(username ?? process.env.E2E_ADMIN_USER);
  await page.locator('input[name="password"]').fill(password ?? process.env.E2E_ADMIN_PASS);
  // Giriş formundaki düğmenin açık bir type="submit" niteliği yok (varsayılan
  // buton davranışına güveniyor); metne göre seçiyoruz.
  await page.locator('button:has-text("Giriş Yap")').click();
}

/** Panelin kendi "Sil" formunu kullanarak (gerçek dispatcher) test kaydını siler. */
async function deleteLocationByName(page, name) {
  await page.goto(ADMIN_BASE + 'locations.php?search=' + encodeURIComponent(name), { waitUntil: 'domcontentloaded' });
  const row = page.locator('table tbody tr', { hasText: name });
  if ((await row.count()) === 0) return; // zaten yok (test başarısız olup hiç oluşmamış olabilir)
  page.once('dialog', (dialog) => dialog.accept());
  await row.locator('button:has-text("Sil")').click();
  await expect(page.locator('table')).not.toContainText(name);
}

test.describe('Admin panel: giriş (gerçek CSRF + oturum)', () => {
  test('doğru kimlik bilgileriyle giriş yapılıp dashboard açılıyor', async ({ page }) => {
    await login(page);
    await expect(page).toHaveURL(/dashboard\.php/);
    await expect(page.locator('.navbar-text')).toContainText(process.env.E2E_ADMIN_USER);
  });

  test('yanlış şifreyle giriş reddediliyor ve genel hata mesajı gösteriliyor', async ({ page }) => {
    await login(page, { password: 'kesinlikle-yanlis-bir-sifre' });
    await expect(page).toHaveURL(/index\.php/);
    await expect(page.locator('.alert-danger')).toContainText('Kullanıcı adı veya şifre hatalı');
  });

  test('girişsiz konum yönetimi sayfasına gidilince login ekranına yönlendiriliyor', async ({ page }) => {
    await page.context().clearCookies();
    await page.goto(ADMIN_BASE + 'locations.php', { waitUntil: 'domcontentloaded' });
    await expect(page).toHaveURL(/index\.php/);
  });

  test('CSRF jetonu olmadan POST gönderilirse 419 ile reddediliyor', async ({ page }) => {
    // Not: bu tek test BİLEREK gerçek formu atlayıp jetonsuz bir istek
    // gönderir — amacı CSRF korumasının GERÇEKTEN çalıştığını kanıtlamaktır.
    // Diğer tüm testler (login, kayıt ekleme, yükleme, CSV) her zaman
    // sayfadan okunan gerçek jetonu kullanır; bu test onun tersini/negatif
    // kontrolünü sağlar. page.request kullanılır çünkü bu, page'in
    // browser context'iyle (oturum çerezi) garanti paylaşımlıdır.
    //
    // DÜZELTİLDİ: csrf_verify() önceden http_response_code(419) kullanıyordu;
    // 419 IANA'da kayıtlı olmadığından Apache+mod_php altında durum satırı
    // sessizce 500'e düşüyordu (CSRF reddini sunucu hatası gibi gösteriyordu).
    // Artık header('HTTP/1.1 419 Page Expired') ile durum satırı doğrudan
    // yazılıyor; bu test gerçek 419'un telden geldiğini doğrular.
    await login(page);
    const response = await page.request.post(ADMIN_BASE + 'locations.php', {
      form: { action: 'delete', id: '999999' },
      // Oturum çerezi otomatik gider ama formdaki gizli "csrf" alanı YOK.
      maxRedirects: 0,
    });
    expect(response.status()).toBe(419);
    await expect(response.text()).resolves.toContain('Oturum doğrulaması başarısız');
  });

  test('güvenlik sayfasında site durumu ve giriş kayıtları görünüyor', async ({ page }) => {
    await login(page);
    await page.goto(ADMIN_BASE + 'security.php');
    await expect(page.locator('h1')).toContainText('Güvenlik ve Site Durumu');
    await expect(page.locator('label[for="siteEnabled"]')).toContainText('Harita public sitede yayında');
    await expect(page.locator('table').first()).toBeVisible();
  });
});

test.describe('Admin panel: konum yönetimi (gerçek form + CSRF)', () => {
  test.beforeEach(async ({ page }) => {
    await login(page);
    await page.goto(ADMIN_BASE + 'locations.php');
  });

  test('zorunlu alanlarla yeni konum ekleniyor ve listede görünüyor', async ({ page }) => {
    const name = uniqueName('Konum');
    try {
      await page.locator('button:has-text("Yeni Konum Ekle")').click();
      await expect(page.locator('#locationModal')).toBeVisible();

      await page.locator('#f_name').fill(name);
      await page.locator('#f_type').fill('E2E Test Türü');
      await page.locator('#locationModal button[type="submit"]').click();

      await expect(page).toHaveURL(/locations\.php/);
      await expect(page.locator('.alert-success')).toContainText('Kayıt eklendi');
      await expect(page.locator('table')).toContainText(name);
    } finally {
      await deleteLocationByName(page, name);
    }
  });

  test('iki dilli konum alanları kaydediliyor, düzenlemede dolu geliyor ve İngilizce aranabiliyor', async ({ page }) => {
    const name = uniqueName('I18N');
    const nameEn = `${name} English`;
    const descriptionTr = 'Türkçe E2E açıklama';
    const descriptionEn = 'English E2E description';

    try {
      await page.locator('button:has-text("Yeni Konum Ekle")').click();
      await expect(page.locator('#locationModal')).toBeVisible();

      await page.locator('#f_name').fill(name);
      await page.locator('#f_name_en').fill(nameEn);
      await page.locator('#f_type').fill('E2E Test Türü');
      await page.locator('#f_description').fill(descriptionTr);
      await page.locator('#f_description_en').fill(descriptionEn);
      await page.locator('#locationModal button[type="submit"]').click();

      await expect(page).toHaveURL(/locations\.php/);
      await expect(page.locator('.alert-success')).toContainText('Kayıt eklendi');
      await expect(page.locator('table')).toContainText(name);
      await expect(page.locator('table')).toContainText(nameEn);

      const row = page.locator('table tbody tr', { hasText: name });
      await row.locator('button:has-text("Düzenle")').click();
      await expect(page.locator('#locationModal')).toBeVisible();
      await expect(page.locator('#f_name')).toHaveValue(name);
      await expect(page.locator('#f_name_en')).toHaveValue(nameEn);
      await expect(page.locator('#f_description')).toHaveValue(descriptionTr);
      await expect(page.locator('#f_description_en')).toHaveValue(descriptionEn);
      await page.locator('#locationModal button.close, #locationModal [data-dismiss="modal"]').first().click();
      await expect(page.locator('#locationModal')).toBeHidden();

      await page.locator('input[name="search"]').fill(nameEn);
      await page.locator('button:has-text("Ara")').click();
      await expect(page).toHaveURL(/locations\.php\?search=/);
      await expect(page.locator('table')).toContainText(name);
      await expect(page.locator('table')).toContainText(nameEn);
    } finally {
      await deleteLocationByName(page, name);
    }
  });

  test('zorunlu alan (tür) boş bırakılınca kayıt reddediliyor', async ({ page }) => {
    const name = uniqueName('Eksik');
    await page.locator('button:has-text("Yeni Konum Ekle")').click();
    await expect(page.locator('#locationModal')).toBeVisible();
    await page.locator('#f_name').fill(name);
    // #f_type boş bırakılır — tarayıcı "required" doğrulaması engelleyebilir,
    // bu yüzden HTML5 doğrulamasını atlayıp gerçek sunucu tarafı kuralı
    // (validate_location -> "Tür alanı zorunludur.") test edilir.
    await page.locator('#f_type').evaluate((el) => { el.removeAttribute('required'); });
    await page.locator('#locationModal button[type="submit"]').click();

    await expect(page).toHaveURL(/locations\.php/);
    await expect(page.locator('.alert-danger')).toContainText('Tür alanı zorunludur');
    await expect(page.locator('table')).not.toContainText(name);
  });

  test('görsel dosyası setInputFiles ile yükleniyor ve image_url gerçek yola güncelleniyor', async ({ page }) => {
    const name = uniqueName('Gorsel');
    // 1x1 şeffaf PNG — gerçek, geçerli bir görsel (getimagesize/finfo bunu
    // image/png olarak tanır); diskte kalıcı bir fixture dosyasına gerek yok.
    const pngBuffer = Buffer.from(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
      'base64'
    );
    let uploadedImageUrl = null;

    try {
      await page.locator('button:has-text("Yeni Konum Ekle")').click();
      await expect(page.locator('#locationModal')).toBeVisible();
      await page.locator('#f_name').fill(name);
      await page.locator('#f_type').fill('E2E Test Türü');
      await page.locator('#f_image_file').setInputFiles({
        name: 'e2e-test.png',
        mimeType: 'image/png',
        buffer: pngBuffer,
      });
      await page.locator('#locationModal button[type="submit"]').click();

      await expect(page.locator('.alert-success')).toContainText('Kayıt eklendi');
      await expect(page.locator('table')).toContainText(name);

      // Kaydı düzenle'ye açıp image_url'in gerçekten uploads/locations/ altına
      // kaydedilmiş bir dosyaya işaret ettiğini doğrula (bkz.
      // uploaded_location_image_path() — is_uploaded_file() burada GERÇEKTEN
      // true döner çünkü bu istek gerçek bir tarayıcı multipart yüklemesi).
      const row = page.locator('table tbody tr', { hasText: name });
      await row.locator('button:has-text("Düzenle")').click();
      await expect(page.locator('#locationModal')).toBeVisible();
      const imageUrl = await page.locator('#f_image_url').inputValue();
      expect(imageUrl).toMatch(/^uploads\/locations\/[a-f0-9]+\.png$/);
      uploadedImageUrl = imageUrl;

      const uploadedFileResponse = await page.request.get('/' + imageUrl);
      expect(uploadedFileResponse.ok()).toBeTruthy();

      await page.locator('#locationModal button.close, #locationModal [data-dismiss="modal"]').first().click();
    } finally {
      await deleteLocationByName(page, name);
      // Uygulama, konum silinirken yüklenmiş görsel dosyasını diskten
      // KALDIRMIYOR (yalnızca DB satırını siliyor) — bu, uygulamanın kendi
      // davranışı/eksikliği, testin sorumluluğu değil. Ama testin YARATTIĞI
      // dosyayı diskte bırakmamak için burada elle temizliyoruz (bind mount
      // sayesinde container'daki public/uploads/locations/ ile host'taki
      // aynı klasör aynı dosyadır).
      if (uploadedImageUrl) {
        const hostPath = path.join(__dirname, '..', '..', 'public', uploadedImageUrl);
        fs.rmSync(hostPath, { force: true });
      }
    }
  });

  test('CSV içe aktarma: geçici CSV fixture ile yeni kayıt oluşturuluyor', async ({ page }) => {
    const name = uniqueName('CSV');
    // Geçici, yalnızca bellekte var olan CSV içeriği — diske yazılmaz.
    const csv = `name,category,address\n${name},E2E Test Türü,Test Adresi 1\n`;

    try {
      await page.locator('#csv_file').setInputFiles({
        name: 'e2e-import.csv',
        mimeType: 'text/csv',
        buffer: Buffer.from(csv, 'utf8'),
      });
      await page.locator('button:has-text("CSV İçe Aktar")').click();

      await expect(page).toHaveURL(/locations\.php/);
      await expect(page.locator('.alert-success, .alert-warning')).toContainText('CSV içe aktarıldı');
      await expect(page.locator('table')).toContainText(name);
    } finally {
      await deleteLocationByName(page, name);
    }
  });
});

// Not (bkz. plan "Test planı"): bu iki senaryo, yeni eklenen Ayarlar ve
// aktif/pasif konum özelliklerinin UÇTAN UCA en azından bir kez gerçek
// tarayıcıda çalıştığını doğrular — settings.php'nin/users.php'nin her
// köşesini (PWA alanları, kullanıcı oluşturma/şifre sıfırlama, rol bazlı
// buton gizleme) KAPSAMAZ; bu bilinçli bir zaman/kapsam dengesi.
test.describe('Admin panel: site ayarları kalıcılığı (gerçek form + CSRF)', () => {
  test('kurumsal başlık değiştirilip kaydedilince sayfa yeniden yüklendiğinde kalıcı kalıyor', async ({ page }) => {
    await login(page);
    await page.goto(ADMIN_BASE + 'settings.php');

    const original = await page.locator('#site_title').inputValue();
    const updated = `_E2E_Settings_${Date.now()}`;

    try {
      await page.locator('#site_title').fill(updated);
      await page.locator('form button:has-text("Kaydet")').click();

      await expect(page).toHaveURL(/settings\.php/);
      await expect(page.locator('.alert-success')).toContainText('Site ayarları güncellendi');
      await expect(page.locator('#site_title')).toHaveValue(updated);

      await page.reload();
      await expect(page.locator('#site_title')).toHaveValue(updated);
    } finally {
      await page.locator('#site_title').fill(original);
      await page.locator('form button:has-text("Kaydet")').click();
      await expect(page.locator('.alert-success')).toContainText('Site ayarları güncellendi');
    }
  });
});

test.describe('Admin panel: konum aktif/pasif (gerçek form + bulk işlem)', () => {
  test('bir konum pasif yapılınca listede "Pasif" görünüyor', async ({ page }) => {
    test.setTimeout(30_000);
    await login(page);
    await page.goto(ADMIN_BASE + 'locations.php');

    const name = uniqueName('Pasif');

    try {
      await page.locator('button:has-text("Yeni Konum Ekle")').click();
      await expect(page.locator('#locationModal')).toBeVisible();
      await page.locator('#f_name').fill(name);
      await page.locator('#f_type').fill('E2E Test Türü');
      await page.locator('#locationModal button[type="submit"]').click();
      await expect(page.locator('.alert-success')).toContainText('Kayıt eklendi');
      await page.goto(ADMIN_BASE + 'locations.php?search=' + encodeURIComponent(name), { waitUntil: 'domcontentloaded' });
      await expect(page.locator('table tbody tr', { hasText: name })).toHaveCount(1);

      const row = page.locator('table tbody tr', { hasText: name });
      await row.locator('.row-select').check();
      page.once('dialog', (dialog) => dialog.accept());
      await page.locator('button:has-text("Pasif Yap")').click();

      await expect(page).toHaveURL(/locations\.php/);
      await page.goto(ADMIN_BASE + 'locations.php?search=' + encodeURIComponent(name), { waitUntil: 'domcontentloaded' });
      const updatedRow = page.locator('table tbody tr', { hasText: name });
      await expect(updatedRow).toContainText('Pasif');
    } finally {
      await deleteLocationByName(page, name);
    }
  });
});
