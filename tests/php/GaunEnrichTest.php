<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../gaun_enrich.php';

/**
 * gaun_enrich.php: Türkçe normalizasyon, cache tazelik filtresi ("bugün"
 * olmayan menü/etkinlik asla sızmaz) ve public veri filtreleme (yalnızca
 * izinli GAÜN host'ları, güvenli alanlar) davranışını doğrular.
 */
final class GaunEnrichTest extends TestCase
{
    // ---- Türkçe karakter normalizasyonu -----------------------------------

    public function testNormNameFixesDottedAndDotlessITurkishCasing(): void
    {
        // "İ" -> "i" (dotted), "I" -> "ı" (dotless) BEFORE lowercasing —
        // gaun_norm_name diyakritikleri (ş, ğ, ı, ü, ö, ç) korur, yalnızca
        // İ/I ayrımını ve büyük/küçük harfi normalize eder (bkz. gaun_fold_key
        // aşağıda, diyakritikleri de ASCII'ye katlar).
        $this->assertSame('bilgi işlem daire başkanlığı', gaun_norm_name('Bilgi İşlem Daire BAŞKANLIĞI'));
    }

    public function testNormNameLowercasesAndCollapsesWhitespace(): void
    {
        $this->assertSame('gaziantep üniversitesi', gaun_norm_name('  Gaziantep    Üniversitesi  '));
    }

    public function testFoldKeyStripsInstitutionalSuffixesAndDiacritics(): void
    {
        $this->assertSame('bilgisayar muhendisligi', gaun_fold_key('Bilgisayar Mühendisliği Bölümü'));
        $this->assertSame('ogrenci isleri', gaun_fold_key('Öğrenci İşleri Daire Başkanlığı'));
    }

    public function testFoldKeyNormalizesMakinaToMakine(): void
    {
        $this->assertSame('makine muhendisligi', gaun_fold_key('Makina Mühendisliği'));
    }

    public function testTokenOverlapScoreExactMatchIsHighest(): void
    {
        $this->assertSame(70, gaun_token_overlap_score('Bilgi İşlem Daire Başkanlığı', 'Bilgi İşlem Daire Başkanlığı'));
    }

    public function testTokenOverlapScoreZeroForUnrelatedNames(): void
    {
        $this->assertSame(0, gaun_token_overlap_score('Bilgi İşlem Daire Başkanlığı', 'Tıp Fakültesi'));
    }

    // ---- Public URL filtreleme (yalnızca izinli GAÜN host'ları) -----------

    public function testPublicUrlAcceptsGaziantepEduTrAndSubdomains(): void
    {
        $this->assertSame(
            'https://oidb.gaziantep.edu.tr/sayfa',
            gaun_public_url('https://oidb.gaziantep.edu.tr/sayfa')
        );
        $this->assertSame(
            'https://gantep.edu.tr/',
            gaun_public_url('https://gantep.edu.tr/')
        );
    }

    public function testPublicUrlRejectsForeignHost(): void
    {
        $this->assertNull(gaun_public_url('https://evil.example.com/phish'));
    }

    public function testPublicUrlRejectsNonHttpScheme(): void
    {
        $this->assertNull(gaun_public_url('javascript:alert(1)'));
        $this->assertNull(gaun_public_url('ftp://gaziantep.edu.tr/dosya'));
    }

    public function testPublicUrlRejectsSimilarLookingHost(): void
    {
        // "gaziantep.edu.tr.evil.com" barındırır ama gerçek host değildir.
        $this->assertNull(gaun_public_url('https://gaziantep.edu.tr.evil.com/'));
    }

    public function testPublicUrlRejectsCredentialsAndUnexpectedPorts(): void
    {
        $this->assertNull(gaun_public_url('https://user:pass@gaziantep.edu.tr/'));
        $this->assertNull(gaun_public_url('https://gaziantep.edu.tr:8080/'));
    }

    // ---- Yemek menüsü: yalnızca "bugün" (Türkiye saati) sızar -------------

    public function testDiningReturnsEmptyShellWhenCacheMissing(): void
    {
        $out = gaun_dining(null);
        $this->assertSame([], $out['items']);
        $this->assertNull($out['calories']);
    }

    public function testDiningDiscardsStaleMenuButKeepsLinks(): void
    {
        $cache = [
            'meal_menu' => [
                'date' => '2000-01-01', // asla "bugün" olmayacak bir tarih
                'items' => [['name' => 'Mercimek Çorbası', 'kcal' => 120]],
                'total_kcal' => 500,
                'source_url' => 'https://www.gaziantep.edu.tr/yemek/',
                'monthly_url' => 'https://www.gaziantep.edu.tr/yemek/aylik',
            ],
        ];
        $out = gaun_dining($cache);
        $this->assertSame([], $out['items'], 'bayat menü öğeleri asla "bugünkü" olarak gösterilmemeli');
        $this->assertSame('https://www.gaziantep.edu.tr/yemek/aylik', $out['monthly_url']);
    }

    public function testDiningReturnsItemsWhenDateMatchesToday(): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
        $cache = [
            'meal_menu' => [
                'date' => $today,
                'items' => [
                    ['name' => 'Mercimek Çorbası', 'kcal' => 120],
                    'Pilav', // eski biçim: düz string de tolere edilir
                ],
                'total_kcal' => 650,
            ],
        ];
        $out = gaun_dining($cache);
        $this->assertCount(2, $out['items']);
        $this->assertSame('Mercimek Çorbası', $out['items'][0]['name']);
        $this->assertSame(120, $out['items'][0]['kcal']);
        $this->assertSame('Pilav', $out['items'][1]['name']);
        $this->assertNull($out['items'][1]['kcal']);
        $this->assertSame(650, $out['calories']);
    }

    public function testDiningCapsItemsAtTwelve(): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
        $items = [];
        for ($i = 0; $i < 20; $i++) {
            $items[] = ['name' => "Yemek {$i}"];
        }
        $out = gaun_dining(['meal_menu' => ['date' => $today, 'items' => $items]]);
        $this->assertCount(12, $out['items']);
    }

    // ---- Etkinlikler: geçmiş asla sızmaz, tarihe göre sıralanır -----------

    public function testEventsFilterOutPastDates(): void
    {
        $cache = ['events' => [
            ['title' => 'Geçmiş Etkinlik', 'date' => '2000-01-01', 'url' => 'https://etkinlik.gaziantep.edu.tr/x'],
        ]];
        $this->assertSame([], gaun_events($cache));
    }

    public function testEventsFilterOutUnsafeHostAndKeepValidOnesSortedByDate(): void
    {
        $future1 = (new \DateTimeImmutable('+10 days'))->format('Y-m-d');
        $future2 = (new \DateTimeImmutable('+2 days'))->format('Y-m-d');
        $cache = ['events' => [
            ['title' => 'Uzak Etkinlik', 'date' => $future1, 'url' => 'https://etkinlik.gaziantep.edu.tr/uzak'],
            ['title' => 'Yakın Etkinlik', 'date' => $future2, 'url' => 'https://etkinlik.gaziantep.edu.tr/yakin'],
            ['title' => 'Şüpheli', 'date' => $future2, 'url' => 'https://evil.example.com/x'],
        ]];
        $out = gaun_events($cache);
        $this->assertCount(2, $out);
        $this->assertSame('Yakın Etkinlik', $out[0]['title'], 'en yakın tarihli önce gelmeli');
        $this->assertSame('Uzak Etkinlik', $out[1]['title']);
    }

    public function testEventsLimitsToFive(): void
    {
        $events = [];
        for ($i = 0; $i < 8; $i++) {
            $date = (new \DateTimeImmutable("+{$i} days"))->format('Y-m-d');
            $events[] = ['title' => "Etkinlik {$i}", 'date' => $date, 'url' => 'https://etkinlik.gaziantep.edu.tr/e' . $i];
        }
        $out = gaun_events(['events' => $events]);
        $this->assertCount(5, $out);
    }

    public function testEventsRejectsMissingOrMalformedDate(): void
    {
        $cache = ['events' => [
            ['title' => 'Tarihsiz', 'date' => '', 'url' => 'https://etkinlik.gaziantep.edu.tr/x'],
            ['title' => 'Bozuk tarih', 'date' => '10 Ocak', 'url' => 'https://etkinlik.gaziantep.edu.tr/y'],
        ]];
        $this->assertSame([], gaun_events($cache));
    }

    // ---- Bülten: yalnızca güvenli alan/URL geçer, en fazla 5 kayıt --------

    public function testBulletinFiltersUnsafeUrlAndCapsAtFive(): void
    {
        $rows = [];
        for ($i = 0; $i < 7; $i++) {
            $rows[] = ['title' => "Duyuru {$i}", 'url' => 'https://oidb.gaziantep.edu.tr/d' . $i];
        }
        $rows[] = ['title' => 'Kötü niyetli', 'url' => 'https://evil.example.com/'];
        $out = gaun_bulletin(['announcements' => $rows]);
        $this->assertCount(5, $out['announcements']);
        foreach ($out['announcements'] as $item) {
            $this->assertStringContainsString('gaziantep.edu.tr', $item['url']);
        }
    }

    public function testBulletinRejectsOverlongTitle(): void
    {
        $rows = [['title' => str_repeat('a', 201), 'url' => 'https://oidb.gaziantep.edu.tr/x']];
        $this->assertSame([], gaun_bulletin(['announcements' => $rows])['announcements']);
    }

    public function testBulletinIncludesTodayIsoForClientSideEventPins(): void
    {
        $today = (new \DateTimeImmutable('now', new \DateTimeZone('Europe/Istanbul')))->format('Y-m-d');
        $this->assertSame($today, gaun_bulletin([])['today']);
    }

    // ---- Personel filtreleme: eksik zorunlu alan varsa kayıt elenir -------

    public function testPublicStaffRequiresNameTitleAndSafeSourceUrl(): void
    {
        $this->assertNull(gaun_public_staff(['name' => '', 'title' => 'Prof.', 'source_url' => 'https://gaziantep.edu.tr/x']));
        $this->assertNull(gaun_public_staff(['name' => 'Ayşe Yılmaz', 'title' => '', 'source_url' => 'https://gaziantep.edu.tr/x']));
        $this->assertNull(gaun_public_staff(['name' => 'Ayşe Yılmaz', 'title' => 'Prof.', 'source_url' => 'https://evil.example.com']));
    }

    public function testPublicStaffPassesThroughSafeFieldsOnly(): void
    {
        $staff = gaun_public_staff([
            'name' => 'Ayşe Yılmaz',
            'title' => 'Prof. Dr.',
            'unit_name' => 'Bilgisayar Mühendisliği',
            'department_name' => 'Mühendislik Fakültesi',
            'source_url' => 'https://gaziantep.edu.tr/personel/ayse',
            'verified_at' => '2026-01-01',
            'secret_internal_field' => 'sizmamali',
        ]);
        $this->assertNotNull($staff);
        $this->assertSame('Ayşe Yılmaz', $staff['name']);
        $this->assertSame('Mühendislik Fakültesi', $staff['department']);
        $this->assertArrayNotHasKey('secret_internal_field', $staff);
    }

    // ---- Yemekhane konumu tespiti ------------------------------------------

    public function testIsDiningLocationMatchesYemekhaneNameOrType(): void
    {
        $this->assertTrue(gaun_is_dining_location('Merkez Öğrenci Yemekhanesi', 'Yemekhane'));
        $this->assertFalse(gaun_is_dining_location('Kütüphane', 'Bina'));
    }

    public function testIsDiningLocationExcludesCategoryRows(): void
    {
        // "Kategori" tipindeki kök düğümler (ör. "Yemekhaneler" kategorisi)
        // dining flag'i almamalı; yalnız gerçek yemekhane KONUMLARI alır.
        $this->assertFalse(gaun_is_dining_location('Yemekhaneler', 'Kategori'));
    }

    // ---- İsim/host eşleştirme (koordinatlara asla dokunmaz) ---------------

    public function testMatchByExactNormalizedName(): void
    {
        $index = gaun_build_index(['units' => [
            ['name' => 'Bilgi İşlem Daire Başkanlığı', 'website' => 'https://bidb.gaziantep.edu.tr', 'source_url' => 'https://bidb.gaziantep.edu.tr/hakkinda'],
        ]]);
        $match = gaun_match($index, 'Bilgi İşlem Daire Başkanlığı', null);
        $this->assertNotNull($match);
        $this->assertSame('name', $match['matched_by']);
        $this->assertSame('https://bidb.gaziantep.edu.tr', $match['website']);
    }

    public function testMatchByHostWhenNameDiffers(): void
    {
        $index = gaun_build_index(['units' => [
            ['name' => 'Bilgi İşlem Daire Başkanlığı', 'website' => 'https://bidb.gaziantep.edu.tr'],
        ]]);
        $match = gaun_match($index, 'Farklı bir görünen ad', 'https://bidb.gaziantep.edu.tr/iletisim');
        $this->assertNotNull($match);
        $this->assertSame('website', $match['matched_by']);
    }

    public function testMatchReturnsNullWhenNoOverlap(): void
    {
        $index = gaun_build_index(['units' => [
            ['name' => 'Bilgi İşlem Daire Başkanlığı', 'website' => 'https://bidb.gaziantep.edu.tr'],
        ]]);
        $this->assertNull(gaun_match($index, 'Alakasız Bina', 'https://baska-bir-site.com'));
    }

    public function testBuildIndexDropsAmbiguousDuplicateNames(): void
    {
        // Aynı normalize isimde iki farklı birim varsa güvenli taraf: hiçbiri
        // indekslenmez (yanlış eşleşme riskine karşı).
        $index = gaun_build_index(['units' => [
            ['name' => 'Kütüphane', 'website' => 'https://a.gaziantep.edu.tr'],
            ['name' => 'kütüphane', 'website' => 'https://b.gaziantep.edu.tr'],
        ]]);
        $this->assertArrayNotHasKey('kutuphane', $index['by_name']);
        $this->assertSame([], $index['by_name']);
    }
}
