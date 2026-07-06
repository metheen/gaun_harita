'use strict';

const { test, describe } = require('node:test');
const assert = require('node:assert/strict');
const { loadApp } = require('./helpers/load-i18n');

describe('Türkçe karakter normalizasyonu / arama eşleşmesi (foldTr, trLower)', () => {
  test('foldTr Türkçe aksanlı karakterleri ASCII karşılığına katlar', () => {
    const App = loadApp();
    assert.equal(App.i18n.foldTr('Çamlıca Öğrenci Şehri Üniversite'), 'camlica ogrenci sehri universite');
  });

  test('foldTr Türkçe dotless/dotted I ayrımını doğru katlar', () => {
    const App = loadApp();
    // "İstanbul" -> dotted İ -> "i"; "Işık" -> dotless I -> "i" (trLower ile
    // tr-TR locale kuralına göre), sonra aksan katlama.
    assert.equal(App.i18n.foldTr('İstanbul'), 'istanbul');
    assert.equal(App.i18n.foldTr('Işık'), 'isik');
  });

  test('foldTr NFC/NFD baytça farklı iki temsili aynı sonuca indirger', () => {
    const App = loadApp();
    const nfc = 'Bilgi İşlem'.normalize('NFC');
    const nfd = 'Bilgi İşlem'.normalize('NFD');
    assert.notEqual(nfc, nfd, 'test verisi gerçekten iki farklı bayt temsili olmalı');
    assert.equal(App.i18n.foldTr(nfc), App.i18n.foldTr(nfd));
  });

  test('foldTr arama eşleşmesinde kullanıldığı gibi: aksansız sorgu aksanlı metni bulur', () => {
    const App = loadApp();
    const haystack = App.i18n.foldTr('Öğrenci İşleri Daire Başkanlığı');
    const query = App.i18n.foldTr('ogrenci isleri'); // kullanıcı aksansız yazdı
    assert.ok(haystack.includes(query), 'aksansız kullanıcı sorgusu aksanlı kayıtla eşleşmeli');
  });

  test('trLower tr-TR kurallarına göre büyük harf İ/I ayrımını korur', () => {
    const App = loadApp();
    assert.equal(App.i18n.trLower('İ'), 'i');
    assert.equal(App.i18n.trLower('I'), 'ı');
  });

  test('normalizeNfc her zaman NFC birleşik forma döner', () => {
    const App = loadApp();
    const nfd = 'İ'.normalize('NFD');
    assert.equal(App.i18n.normalizeNfc(nfd), 'İ'.normalize('NFC'));
    assert.equal(App.i18n.normalizeNfc(nfd).length, 1, 'NFC birleşik İ tek code unit olmalı');
  });

  test('normalizeNfc null/undefined için boş string döner (çökme yok)', () => {
    const App = loadApp();
    assert.equal(App.i18n.normalizeNfc(null), '');
    assert.equal(App.i18n.normalizeNfc(undefined), '');
  });
});

describe('locationNameEn / displayTaxonomy çeviri mantığı', () => {
  test('displayTaxonomy TR modda değeri değiştirmeden döner', () => {
    const App = loadApp({ lang: 'tr' });
    assert.equal(App.i18n.displayTaxonomy('İdari Binalar'), 'İdari Binalar');
  });

  test('displayTaxonomy EN modda sözlükteki tam karşılığı döner', () => {
    const App = loadApp({ lang: 'en' });
    assert.equal(App.i18n.displayTaxonomy('İdari Binalar'), 'Administrative Buildings');
    assert.equal(App.i18n.displayTaxonomy('Kütüphaneler'), 'Libraries');
  });

  test('displayTaxonomy EN modda sözlükte olmayan değeri olduğu gibi bırakır (fallback)', () => {
    const App = loadApp({ lang: 'en' });
    assert.equal(App.i18n.displayTaxonomy('Tamamen Uydurma Kategori'), 'Tamamen Uydurma Kategori');
  });

  test('displayTaxonomy NFD baytlı girişi de doğru çevirir (JSON.parse kaynaklı NFD regresyonu)', () => {
    const App = loadApp({ lang: 'en' });
    const nfdInput = 'İdari Binalar'.normalize('NFD');
    assert.equal(App.i18n.displayTaxonomy(nfdInput), 'Administrative Buildings');
  });

  test('locationNameEn tam eşleşen özel isimleri sözlükten çevirir', () => {
    const App = loadApp();
    assert.equal(App.i18n.locationNameEn('Rektörlük Binası'), 'Rectorate Building');
    assert.equal(App.i18n.locationNameEn('Bilgi İşlem Daire Başkanlığı'), 'Information Technology Department');
  });

  test('locationNameEn admin eksik veri backfill adlarını da çevirir', () => {
    const App = loadApp();
    assert.equal(App.i18n.locationNameEn('Nizip'), 'Nizip Vocational School');
    assert.equal(App.i18n.locationNameEn('Makarona Makarna&Pizza'), 'Makarona Pasta & Pizza');
    assert.equal(App.i18n.locationNameEn('Bölüm Başkanı'), 'Department Head');
  });

  test('locationNameEn sözlükte doğrudan olmayan "X Bölümü" desenini kurala göre çevirir', () => {
    const App = loadApp();
    // "İnşaat Mühendisliği Bölümü" LOCATION_LABELS_EN'de doğrudan yok; bu
    // yüzden gövde ("İnşaat Mühendisliği" -> LOCATION_CORE_LABELS_EN'den
    // "Civil Engineering") + " Bölümü" -> " Department" ek kuralı birleşir.
    assert.equal(App.i18n.LOCATION_LABELS_EN['İnşaat Mühendisliği Bölümü'], undefined,
      'bu örnek gerçekten ek-kuralı yolunu test etmeli, doğrudan sözlük eşleşmesini değil');
    assert.equal(App.i18n.locationNameEn('İnşaat Mühendisliği Bölümü'), 'Civil Engineering Department');
  });

  test('locationNameEn bilinen bir kurumsal ekle bitmeyen kaydı çeviremezse boş string döner', () => {
    const App = loadApp();
    assert.equal(App.i18n.locationNameEn('Tamamen Uydurma Bir Yer Adı'), '');
  });

  test('locationNameEn boş/undefined girişte boş string döner', () => {
    const App = loadApp();
    assert.equal(App.i18n.locationNameEn(''), '');
    assert.equal(App.i18n.locationNameEn(undefined), '');
  });

  test('displayLocationName TR modda orijinal adı korur', () => {
    const App = loadApp({ lang: 'tr' });
    assert.equal(App.i18n.displayLocationName('Rektörlük Binası'), 'Rektörlük Binası');
    assert.equal(App.i18n.displayLocationName({ name: 'Rektörlük Binası', name_en: 'Custom Rectorate Name' }), 'Rektörlük Binası');
  });

  test('displayLocationName EN modda çeviri varsa onu, yoksa orijinali döner', () => {
    const App = loadApp({ lang: 'en' });
    assert.equal(App.i18n.displayLocationName('Rektörlük Binası'), 'Rectorate Building');
    assert.equal(App.i18n.displayLocationName('Bilinmeyen Özel İsim XYZ'), 'Bilinmeyen Özel İsim XYZ');
  });

  test('displayLocationName EN modda name_en alanını sözlükten önce kullanır', () => {
    const App = loadApp({ lang: 'en' });
    assert.equal(
      App.i18n.displayLocationName({ name: 'Rektörlük Binası', name_en: 'University Rectorate' }),
      'University Rectorate'
    );
    assert.equal(
      App.i18n.displayLocationName({ name: 'Rektörlük Binası', name_en: '   ' }),
      'Rectorate Building'
    );
  });

  test('t() değişken yer tutucularını doldurur ve dile göre şablon seçer', () => {
    const trApp = loadApp({ lang: 'tr' });
    assert.equal(trApp.i18n.t('route.result', { mode: 'Yürüme', eta: '5 dk' }), 'Yürüme rotası 5 dk');

    const enApp = loadApp({ lang: 'en' });
    assert.equal(enApp.i18n.t('route.result', { mode: 'Walking', eta: '5 min' }), 'Walking route 5 min');
  });

  test('t() bilinmeyen anahtar için anahtarın kendisini döner (çökme yok)', () => {
    const App = loadApp();
    assert.equal(App.i18n.t('bilinmeyen.anahtar'), 'bilinmeyen.anahtar');
  });
});
