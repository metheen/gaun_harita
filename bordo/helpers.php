<?php
declare(strict_types=1);

/** Güvenli çıktı için HTML kaçışı yapar. */
function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Belirtilen adrese yönlendirir ve yürütmeyi durdurur. */
function redirect(string $path): void
{
    header('Location: ' . $path);
    exit;
}

/** Tek kullanımlık (flash) mesaj kaydeder. */
function flash(string $message, string $type = 'success'): void
{
    $_SESSION['flash'][] = ['message' => $message, 'type' => $type];
}

/** Kayıtlı flash mesajları döndürür ve temizler. */
function take_flashes(): array
{
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $flashes;
}
