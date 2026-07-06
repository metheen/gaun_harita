// @ts-check
const { execFileSync } = require('node:child_process');
const { TEST_USERNAME } = require('./global-setup');

module.exports = async function globalTeardown() {
  execFileSync(
    'docker',
    [
      'compose', 'exec', '-T', '-e', `E2E_ADMIN_USER=${TEST_USERNAME}`,
      'web', 'php', '-r',
      `
      require '/var/www/html/db.php';
      db('write')->prepare('DELETE FROM users WHERE username = ?')->execute([getenv('E2E_ADMIN_USER')]);
      echo "test admin temizlendi\\n";
      `,
    ],
    { stdio: 'inherit' }
  );
};
