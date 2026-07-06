<?php
declare(strict_types=1);

/**
 * Uygulama yapılandırması.
 *
 * Kimlik bilgileri YALNIZCA ortam değişkenlerinden okunur. Kimlik bilgileri
 * asla kaynak koduna gömülmez. Değişkenleri sunucu ortamında (Apache
 * SetEnv, nginx fastcgi_param, systemd, .env yükleyici vb.) tanımlayın:
 *
 *   DB_HOST, DB_NAME
 *   DB_READ_USER,  DB_READ_PASS    # ön yüz için okuma yetkili hesap
 *   DB_WRITE_USER, DB_WRITE_PASS   # panel için okuma-yazma yetkili hesap
 *   APP_DEBUG=true|false           # üretimde false
 */

// Varsa proje kökündeki .env dosyasını ortam değişkenlerine yükler
// (yalnızca getenv ile zaten tanımlı olmayanları; gerçek ortam önceliklidir).
$envFile = __DIR__ . '/.env';
if (is_readable($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        // Çevreleyen tırnakları soy.
        $value = trim($value, "\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv("{$key}={$value}");
        }
    }
}

/** Zorunlu ortam değişkenini okur; tanımsız/boşsa yapılandırmayı reddeder. */
$env = static function (string $key): string {
    $value = getenv($key);
    if ($value === false || $value === '') {
        http_response_code(500);
        error_log("Yapılandırma hatası: {$key} ortam değişkeni tanımlı değil.");
        exit('Sunucu yapılandırması eksik. (Yönetici için: gerekli ortam değişkeni tanımsız — ayrıntı sunucu error log\'unda; kurulum için README "Hızlı Başlangıç" ve "Üretim notları" bölümlerine bakın.)');
    }
    return $value;
};

return [
    'db' => [
        'host'    => getenv('DB_HOST') ?: 'localhost',
        'name'    => getenv('DB_NAME') ?: 'gau_kampus',
        'charset' => 'utf8mb4',

        // Halka açık ön yüz: yalnızca okuma yetkili hesap (en az yetki ilkesi).
        'read' => [
            'user' => $env('DB_READ_USER'),
            'pass' => $env('DB_READ_PASS'),
        ],

        // Yönetim paneli: okuma-yazma yetkili hesap.
        'write' => [
            'user' => $env('DB_WRITE_USER'),
            'pass' => $env('DB_WRITE_PASS'),
        ],
    ],

    'app' => [
        // Üretimde 'false' bırakın; hata ayrıntılarını gizler.
        'debug' => getenv('APP_DEBUG') === 'true',
    ],
];
