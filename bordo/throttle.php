<?php
declare(strict_types=1);

/**
 * Basit dosya tabanlı login hız sınırlayıcı (brute-force koruması).
 *
 * Denemeler IP başına, web kökü dışındaki private/ klasöründe bir JSON
 * dosyasında tutulur. Şema değişikliği gerektirmez. Küçük/orta ölçekli
 * dağıtımlar içindir; yüksek trafikte Redis/DB tabanlı bir çözüm yeğlenir.
 */

const THROTTLE_MAX_ATTEMPTS = 5;      // Kilitlemeden önceki başarısız deneme
const THROTTLE_WINDOW       = 900;    // Sayım penceresi (saniye) = 15 dk
const THROTTLE_LOCKOUT      = 900;    // Kilit süresi (saniye) = 15 dk

/** Denemelerin tutulduğu dosya yolu. */
function throttle_file(): string
{
    return __DIR__ . '/../private/login_attempts.json';
}

/** İstemciyi tanımlayan anahtar (IP). Ters proxy arkasında güvenilmez başlıklara güvenilmez. */
function throttle_key(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

/** Kilitli dosyayı okuyup callback ile değiştirip geri yazar (yarış koşulu güvenli). */
function throttle_mutate(callable $mutator)
{
    $file = throttle_file();
    $dir  = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0770, true);
    }

    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        // Depolama yazılamıyorsa güvenli tarafta kal: sınırlama yapma, engelleme.
        return $mutator([]);
    }

    flock($fp, LOCK_EX);
    $raw  = stream_get_contents($fp);
    $data = $raw !== '' ? (json_decode($raw, true) ?: []) : [];

    // Süresi geçmiş kayıtları temizle (dosyanın şişmesini önle).
    $now = time();
    foreach ($data as $k => $rec) {
        $lastSeen = max($rec['first'] ?? 0, $rec['locked_until'] ?? 0);
        if ($now - $lastSeen > max(THROTTLE_WINDOW, THROTTLE_LOCKOUT)) {
            unset($data[$k]);
        }
    }

    $result = $mutator($data);

    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $result;
}

/**
 * İstemcinin şu an kilitli olup olmadığını döndürür.
 * Kilitliyse kalan saniye (>0), değilse 0.
 */
function throttle_locked_for(): int
{
    return throttle_mutate(function (array &$data): int {
        $key = throttle_key();
        $rec = $data[$key] ?? null;
        if ($rec && ($rec['locked_until'] ?? 0) > time()) {
            return $rec['locked_until'] - time();
        }
        return 0;
    });
}

/** Başarısız bir denemeyi kaydeder; eşiğe ulaşılırsa kilitler. */
function throttle_register_failure(): void
{
    throttle_mutate(function (array &$data): void {
        $key = throttle_key();
        $now = time();
        $rec = $data[$key] ?? ['count' => 0, 'first' => $now, 'locked_until' => 0];

        // Pencere kapandıysa sayacı sıfırla.
        if ($now - ($rec['first'] ?? $now) > THROTTLE_WINDOW) {
            $rec = ['count' => 0, 'first' => $now, 'locked_until' => 0];
        }

        $rec['count']++;
        if ($rec['count'] >= THROTTLE_MAX_ATTEMPTS) {
            $rec['locked_until'] = $now + THROTTLE_LOCKOUT;
        }

        $data[$key] = $rec;
    });
}

/** Başarılı giriş sonrası istemcinin sayacını temizler. */
function throttle_clear(): void
{
    throttle_mutate(function (array &$data): void {
        unset($data[throttle_key()]);
    });
}

/** Admin paneli için aktif deneme/kilit kayıtlarının güvenli bir özetini döndürür. */
function throttle_snapshot(): array
{
    return throttle_mutate(function (array &$data): array {
        $now = time();
        $rows = [];

        foreach ($data as $ip => $rec) {
            $lockedUntil = (int) ($rec['locked_until'] ?? 0);
            $count = (int) ($rec['count'] ?? 0);
            if ($count <= 0 && $lockedUntil <= $now) {
                continue;
            }

            $rows[] = [
                'ip' => (string) $ip,
                'count' => $count,
                'first' => (int) ($rec['first'] ?? 0),
                'locked_until' => $lockedUntil,
                'locked_for' => max(0, $lockedUntil - $now),
                'is_locked' => $lockedUntil > $now,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return ($b['locked_until'] <=> $a['locked_until']) ?: ($b['count'] <=> $a['count']);
        });

        return $rows;
    });
}

/** Admin panelinden yanlışlıkla kilitlenen istemcileri serbest bırakmak için. */
function throttle_clear_all(): void
{
    throttle_mutate(function (array &$data): void {
        $data = [];
    });
}
