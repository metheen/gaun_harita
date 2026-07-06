<?php
declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../scripts/sync_gaun_sources.php';

/**
 * scripts/sync_gaun_sources.php ayrıştırma (parse_*) fonksiyonlarını, gerçek
 * ağa ASLA çıkmadan, tests/fixtures/ altındaki sabit HTML örnekleriyle test
 * eder. Canlı senkronizasyon (collect_units/collect_staff/main akış vb.)
 * burada test edilmez — bu dosya yalnızca saf ayrıştırma mantığını hedefler.
 *
 * Bu dosyanın require_once ettiği scripts/sync_gaun_sources.php güvenlidir:
 * ana çalıştırma bloğu `if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__)`
 * ile korunur, yalnızca script DOĞRUDAN çalıştırıldığında devreye girer —
 * PHPUnit'in kendisi çalıştırıcı olduğundan bu blok burada asla tetiklenmez.
 */
final class SyncGaunSourcesParserTest extends TestCase
{
    private function fixture(string $name): string
    {
        $path = __DIR__ . '/../fixtures/' . $name;
        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->fail("Fixture okunamadı: {$path}");
        }
        return $contents;
    }

    // ---- Yemek menüsü + kcal ayrıştırma -------------------------------------

    public function testParseMealMenuExtractsItemsAndKcalForMatchingDay(): void
    {
        $now = new DateTimeImmutable('2026-06-15', new DateTimeZone('Europe/Istanbul'));
        $menu = parse_meal_menu_html(
            $this->fixture('meal_menu.html'),
            'https://www.gaziantep.edu.tr/yemek/',
            $now
        );

        $this->assertNotNull($menu);
        $this->assertSame('2026-06-15', $menu['date']);
        $this->assertSame('15 Haziran 2026', $menu['date_label']);
        $this->assertCount(3, $menu['items']);
        $this->assertSame(['name' => 'Mercimek Çorbası', 'kcal' => 166], $menu['items'][0]);
        $this->assertSame(['name' => 'Tavuk Sote', 'kcal' => 320], $menu['items'][1]);
    }

    public function testParseMealMenuHandlesItemWithoutKcal(): void
    {
        $now = new DateTimeImmutable('2026-06-15', new DateTimeZone('Europe/Istanbul'));
        $menu = parse_meal_menu_html($this->fixture('meal_menu.html'), 'https://www.gaziantep.edu.tr/yemek/', $now);

        $this->assertSame('Ayran', $menu['items'][2]['name']);
        $this->assertNull($menu['items'][2]['kcal'], 'kcal parantezi olmayan öğe için null olmalı');
    }

    public function testParseMealMenuSumsTotalKcal(): void
    {
        $now = new DateTimeImmutable('2026-06-15', new DateTimeZone('Europe/Istanbul'));
        $menu = parse_meal_menu_html($this->fixture('meal_menu.html'), 'https://www.gaziantep.edu.tr/yemek/', $now);

        $this->assertSame(486, $menu['total_kcal']); // 166 + 320 (Ayran'ın kcal'ı yok)
    }

    public function testParseMealMenuOnlyMatchesTodaysCellIgnoresOtherDays(): void
    {
        $now = new DateTimeImmutable('2026-06-15', new DateTimeZone('Europe/Istanbul'));
        $menu = parse_meal_menu_html($this->fixture('meal_menu.html'), 'https://www.gaziantep.edu.tr/yemek/', $now);

        $names = array_column($menu['items'], 'name');
        $this->assertNotContains('Ezogelin Çorba', $names, '14 Haziran hücresi karışmamalı');
        $this->assertNotContains('Kuru Fasulye', $names, '16 Haziran hücresi karışmamalı');
    }

    public function testParseMealMenuReturnsEmptyItemsWhenTodayCellHasNoFood(): void
    {
        $now = new DateTimeImmutable('2026-06-15', new DateTimeZone('Europe/Istanbul'));
        $menu = parse_meal_menu_html($this->fixture('meal_menu_empty_today.html'), 'https://www.gaziantep.edu.tr/yemek/', $now);

        $this->assertNotNull($menu, 'gün hücresi bulundu, bu yüzden null DEĞİL boş bir sonuç dönmeli');
        $this->assertSame([], $menu['items']);
        $this->assertNull($menu['total_kcal']);
    }

    public function testParseMealMenuReturnsNullWhenTodayNotInTable(): void
    {
        // Tablo geçerli ama "bugün" (17 Haziran) hiçbir hücrede yok.
        $now = new DateTimeImmutable('2026-06-17', new DateTimeZone('Europe/Istanbul'));
        $menu = parse_meal_menu_html($this->fixture('meal_menu.html'), 'https://www.gaziantep.edu.tr/yemek/', $now);

        $this->assertNull($menu);
    }

    public function testParseMealMenuReturnsNullOnMalformedHtml(): void
    {
        // Bozuk/tanınmayan sayfa yapısı: null döner, eski cache korunur
        // (bkz. scripts/sync_gaun_sources.php ana akışındaki "stale" mantığı).
        $now = new DateTimeImmutable('2026-06-15', new DateTimeZone('Europe/Istanbul'));
        $menu = parse_meal_menu_html($this->fixture('meal_menu_malformed.html'), 'https://www.gaziantep.edu.tr/yemek/', $now);

        $this->assertNull($menu);
    }

    // ---- Etkinlik listesi: başlık/tarih ayrıştırma --------------------------

    public function testParseEventsListingFiltersOutPastEvents(): void
    {
        $events = parse_events_listing_html(
            $this->fixture('events_listing.html'),
            'https://etkinlik.gaziantep.edu.tr/listing.php',
            '2026-06-01'
        );

        $this->assertNotNull($events);
        $titles = array_column($events, 'title');
        $this->assertNotContains('Geçmiş Etkinlik', $titles, '2026-01-01 tarihli etkinlik 2026-06-01 referansına göre geçmiş olmalı');
    }

    public function testParseEventsListingStripsWrappingQuotesFromTitle(): void
    {
        $events = parse_events_listing_html(
            $this->fixture('events_listing.html'),
            'https://etkinlik.gaziantep.edu.tr/listing.php',
            '2026-06-01'
        );

        $titles = array_column($events, 'title');
        $this->assertContains('Kariyer Günü', $titles, "'Kariyer Günü' tek tırnaklar silinmiş olarak gelmeli");
    }

    public function testParseEventsListingParsesDateAndBuildsDetailUrl(): void
    {
        $events = parse_events_listing_html(
            $this->fixture('events_listing.html'),
            'https://etkinlik.gaziantep.edu.tr/listing.php',
            '2026-06-01'
        );

        $mezuniyet = null;
        foreach ($events as $event) {
            if ($event['title'] === 'Mezuniyet Töreni') {
                $mezuniyet = $event;
                break;
            }
        }
        $this->assertNotNull($mezuniyet);
        $this->assertSame('2026-07-04', $mezuniyet['date']);
        $this->assertSame('04 Temmuz 2026', $mezuniyet['date_label']);
        $this->assertSame(
            'https://etkinlik.gaziantep.edu.tr/main.php?po=calendar&op=calendar_only&fm_data[id]=202',
            $mezuniyet['url']
        );
    }

    public function testParseEventsListingSkipsBlockWithMissingFieldsWithoutBreaking(): void
    {
        // "Eksik Ay Bilgisi" bloğu ay div'i olmadığı için atlanmalı; bu,
        // dosyadaki diğer geçerli etkinlikleri etkilememeli (zaten yukarıdaki
        // testler Kariyer Günü + Mezuniyet Töreni'nin doğru geldiğini kanıtlıyor).
        $events = parse_events_listing_html(
            $this->fixture('events_listing.html'),
            'https://etkinlik.gaziantep.edu.tr/listing.php',
            '2026-06-01'
        );
        $titles = array_column($events, 'title');
        $this->assertNotContains('Eksik Ay Bilgisi', $titles);
        $this->assertCount(2, $events);
    }

    public function testParseEventsListingReturnsEmptyArrayWhenStructureValidButNoUpcomingEvents(): void
    {
        // Tüm etkinlikler referans tarihinden ÖNCEyse yapı doğru ama liste
        // boş olmalı — bu null DEĞİLDİR (yapısal hata değil, sadece "yok").
        $events = parse_events_listing_html(
            $this->fixture('events_listing.html'),
            'https://etkinlik.gaziantep.edu.tr/listing.php',
            '2099-01-01'
        );
        $this->assertSame([], $events);
    }

    public function testParseEventsListingReturnsNullOnMalformedHtml(): void
    {
        $events = parse_events_listing_html(
            $this->fixture('events_listing_malformed.html'),
            'https://etkinlik.gaziantep.edu.tr/listing.php',
            '2026-06-01'
        );
        $this->assertNull($events);
    }

    // ---- Etkinlik detayı: saat/yer/açıklama/görsel ayrıştırma ---------------

    public function testParseEventDetailExtractsTimeAndLocation(): void
    {
        $detail = parse_event_detail_html(
            $this->fixture('event_detail.html'),
            'https://etkinlik.gaziantep.edu.tr/main.php?po=calendar&op=calendar_only&fm_data[id]=202'
        );

        $this->assertSame('14:30', $detail['time']);
        $this->assertSame('Kongre ve Kültür Merkezi', $detail['location']);
    }

    public function testParseEventDetailAbsolutizesImageUrl(): void
    {
        $detail = parse_event_detail_html(
            $this->fixture('event_detail.html'),
            'https://etkinlik.gaziantep.edu.tr/main.php?po=calendar&op=calendar_only&fm_data[id]=202'
        );

        $this->assertSame('https://etkinlik.gaziantep.edu.tr/uploads/etkinlik/mezuniyet.jpg', $detail['image_url']);
    }

    public function testParseEventDetailExcludesStyleAndFooterTextFromDescription(): void
    {
        $detail = parse_event_detail_html(
            $this->fixture('event_detail.html'),
            'https://etkinlik.gaziantep.edu.tr/main.php?po=calendar&op=calendar_only&fm_data[id]=202'
        );

        $this->assertStringNotContainsString('color: #333', (string) $detail['description']);
        $this->assertStringNotContainsString('Ekleyen', (string) $detail['description']);
        $this->assertStringNotContainsString('Kapat', (string) $detail['description']);
    }

    public function testParseEventDetailReturnsNullFieldsWhenContentSpanMissing(): void
    {
        // .content span'i olmayan bir sayfa: fatal hata değil, sade null'lar.
        $detail = parse_event_detail_html('<html><body><p>Boş sayfa</p></body></html>', 'https://etkinlik.gaziantep.edu.tr/x');

        $this->assertNull($detail['time']);
        $this->assertNull($detail['location']);
        $this->assertNull($detail['description']);
        $this->assertNull($detail['image_url']);
    }

    // ---- Duyurular -----------------------------------------------------------

    public function testParseAnnouncementsExtractsTitleAndDatePrefix(): void
    {
        $items = parse_announcements_html($this->fixture('announcements.html'), 'https://www.gaziantep.edu.tr/');

        $this->assertNotNull($items);
        $first = $items[0];
        $this->assertSame('Yaz Okulu Kayıtları Başladı', $first['title']);
        $this->assertSame('25 Haziran', $first['date']);
    }

    public function testParseAnnouncementsAllowsMissingDatePrefix(): void
    {
        $items = parse_announcements_html($this->fixture('announcements.html'), 'https://www.gaziantep.edu.tr/');

        $noDate = null;
        foreach ($items as $item) {
            if ($item['title'] === 'Kütüphane Çalışma Saatleri Güncellendi') {
                $noDate = $item;
                break;
            }
        }
        $this->assertNotNull($noDate);
        $this->assertNull($noDate['date']);
    }

    public function testParseAnnouncementsDeduplicatesByUrlKeepingFirstOccurrence(): void
    {
        $items = parse_announcements_html($this->fixture('announcements.html'), 'https://www.gaziantep.edu.tr/');

        $matching = array_values(array_filter(
            $items,
            fn ($item) => str_contains($item['url'], 'id=502')
        ));
        $this->assertCount(1, $matching, 'aynı URL\'ye işaret eden ikinci bağlantı yinelenmemeli');
        $this->assertSame('Kütüphane Çalışma Saatleri Güncellendi', $matching[0]['title']);
    }

    public function testParseAnnouncementsAbsolutizesRelativeLinks(): void
    {
        $items = parse_announcements_html($this->fixture('announcements.html'), 'https://www.gaziantep.edu.tr/');

        $relative = null;
        foreach ($items as $item) {
            if (str_contains($item['url'], 'id=503')) {
                $relative = $item;
                break;
            }
        }
        $this->assertNotNull($relative);
        $this->assertSame('https://www.gaziantep.edu.tr/habermerkezi/duyuru_detay.php?id=503', $relative['url']);
    }

    public function testParseAnnouncementsIgnoresNonAnnouncementLinks(): void
    {
        $items = parse_announcements_html($this->fixture('announcements.html'), 'https://www.gaziantep.edu.tr/');

        foreach ($items as $item) {
            $this->assertStringNotContainsString('baska_sayfa.php', $item['url']);
        }
    }

    public function testParseAnnouncementsReturnsNullOnMalformedOrUnrelatedHtml(): void
    {
        // Yapı bozuk değil ama hiç duyuru_detay.php bağlantısı yok — bu da
        // "bozuk HTML" ile aynı güvenli null sonucu üretmeli (eski cache korunur).
        $items = parse_announcements_html($this->fixture('announcements_malformed.html'), 'https://www.gaziantep.edu.tr/');

        $this->assertNull($items);
    }
}
