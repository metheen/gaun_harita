<?php
declare(strict_types=1);

/**
 * İstenen erişim moduna ('read' | 'write') göre paylaşımlı bir PDO
 * bağlantısı döndürür. Bağlantılar tekil (singleton) olarak havuzlanır.
 */
function db(string $mode = 'read'): PDO
{
    static $pool = [];

    if (isset($pool[$mode])) {
        return $pool[$mode];
    }

    $config = require __DIR__ . '/config.php';
    $db     = $config['db'];

    if (!isset($db[$mode]['user'])) {
        throw new InvalidArgumentException("Geçersiz veritabanı modu: {$mode}");
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $db['host'],
        $db['name'],
        $db['charset']
    );

    $pool[$mode] = new PDO($dsn, $db[$mode]['user'], $db[$mode]['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pool[$mode];
}
