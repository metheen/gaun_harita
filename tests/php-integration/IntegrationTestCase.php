<?php
declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../db.php';
require_once __DIR__ . '/../../bordo/locations_lib.php';

/**
 * Gerçek MariaDB şemasına (docker compose'daki "db" servisi) karşı çalışan
 * entegrasyon testleri için ortak taban. tests/php/'deki SQLite in-memory
 * birim testlerinden BİLEREK farklı: burada gerçek db.php/config.php
 * bağlantı katmanı, gerçek FK/ON DELETE CASCADE, gerçek utf8mb4 charset/
 * collation kullanılır — amaç, SQLite'ın yakalayamayacağı şema-özel
 * davranışları doğrulamaktır.
 *
 * Veri hijyeni: her test benzersiz bir "_PHPINT_<rastgele>_" öneki alır;
 * insertLocation() ile eklenen kayıtların id'leri izlenir ve tearDown()'da
 * kesin olarak silinir. Ayrıca önek eşleşmesiyle bir güvenlik ağı taraması
 * yapılır (ör. bir testin ortasında başarısızlık olup id takibi eksik
 * kalırsa bile hiçbir "_PHPINT_" kaydı arkada kalmaz).
 */
abstract class IntegrationTestCase extends TestCase
{
    protected PDO $pdo;
    protected string $prefix;

    /** @var int[] */
    private array $createdIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = db('write');
        $this->prefix = '_PHPINT_' . bin2hex(random_bytes(4)) . '_';
    }

    protected function tearDown(): void
    {
        if ($this->createdIds) {
            $placeholders = implode(',', array_fill(0, count($this->createdIds), '?'));
            $this->pdo->prepare("DELETE FROM campus_locations WHERE id IN ({$placeholders})")
                ->execute($this->createdIds);
        }
        // Güvenlik ağı: id takibi kaçırılmış olsa bile önekle eşleşen hiçbir
        // kayıt arkada kalmasın (CASCADE ile zaten silinmiş olanlar için bu
        // sorgu 0 satır etkiler, zararsız).
        $this->pdo->prepare('DELETE FROM campus_locations WHERE name LIKE ?')
            ->execute([$this->prefix . '%']);
        parent::tearDown();
    }

    /**
     * collect_location() ile aynı 22 alanlık şekle sahip varsayılan bir test
     * kaydı ekler (save_location_record sütun listesini array_keys($data)'dan
     * kurduğu için şekil tam eşleşmeli). Döndürülen id tearDown'da silinir.
     */
    protected function insertLocation(array $overrides = []): int
    {
        $data = array_merge([
            'parent_id' => null,
            'name' => $this->prefix . 'Konum',
            'name_en' => null,
            'type' => 'Test Türü',
            'latitude' => null,
            'longitude' => null,
            'description' => null,
            'description_en' => null,
            'link' => null,
            'route' => null,
            'address' => null,
            'phone' => null,
            'facilities' => null,
            'capacity' => null,
            'rooms' => null,
            'amenities' => null,
            'opening_hours' => null,
            'manager_name' => null,
            'manager_email' => null,
            'image_url' => null,
            'rating' => null,
            'color' => 'red',
        ], $overrides);

        $id = save_location_record($this->pdo, $data, 0);
        $this->createdIds[] = $id;
        return $id;
    }
}
