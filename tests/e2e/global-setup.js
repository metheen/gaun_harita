// @ts-check
const crypto = require('node:crypto');
const { execFileSync } = require('node:child_process');

// Admin panel testleri GERÇEK bir kullanıcı hesabıyla giriş yapmalı (CSRF
// bypass edilmediği gibi, login de bypass edilmiyor). Var olan "admin"
// hesabının şifresini KULLANMIYORUZ/DEĞİŞTİRMİYORUZ — bu geliştirme veritabanı
// production'dan kopyalanmış olabilir (bkz. private/ROTATE_CREDENTIALS.md).
// Onun yerine her test çalışmasında rastgele şifreli, ayrı bir test-only
// kullanıcı oluşturup işimiz bitince siliyoruz (global-teardown.js).
const TEST_USERNAME = '_e2e_playwright_admin';

function runPhpInWebContainer(phpCode, envVars) {
  const args = ['compose', 'exec', '-T'];
  for (const [key, value] of Object.entries(envVars)) {
    args.push('-e', `${key}=${value}`);
  }
  args.push('web', 'php', '-r', phpCode);
  execFileSync('docker', args, { stdio: 'inherit' });
}

module.exports = async function globalSetup() {
  const password = crypto.randomBytes(18).toString('base64url');

  runPhpInWebContainer(
    `
    require '/var/www/html/db.php';
    require '/var/www/html/bordo/throttle.php';
    \$user = getenv('E2E_ADMIN_USER');
    \$hash = password_hash(getenv('E2E_ADMIN_PASS'), PASSWORD_DEFAULT);
    throttle_clear_all();
    \$pdo = db('write');
    \$pdo->prepare(
      'INSERT INTO users (username, password) VALUES (?, ?) ' .
      'ON DUPLICATE KEY UPDATE password = VALUES(password)'
    )->execute([\$user, \$hash]);
    echo "test admin hazir: {\$user}\\n";
    `,
    { E2E_ADMIN_USER: TEST_USERNAME, E2E_ADMIN_PASS: password }
  );

  // Worker process'lerine (globalSetup'ı çağıran ana process'ten türetilirler)
  // aktarılsın diye process.env'e yazıyoruz — Playwright bu değerleri
  // testlerin çalıştığı alt process'lere miras bırakır.
  process.env.E2E_ADMIN_USER = TEST_USERNAME;
  process.env.E2E_ADMIN_PASS = password;
};

module.exports.TEST_USERNAME = TEST_USERNAME;
