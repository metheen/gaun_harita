'use strict';

// i18n.js normal bir tarayıcı <script> dosyasıdır (module.exports yok, "App"
// nesnesini window üzerine asar). Node'da çalıştırmak için minimal bir DOM/
// localStorage sahtesi kurup dosyayı require ediyoruz; global.window = global
// olduğundan "window.App = ..." ataması aynı zamanda global.App'i de
// oluşturur (Node, tanımlı olmayan bare identifier'lar için gerçek global
// nesneye düşer) — bu yüzden i18n.js hiçbir değişiklik gerektirmeden çalışır.
//
// Her çağrı taze bir App.state ile başlaması için require cache'i temizler
// (i18n.js "require"landığında top-level kod her seferinde yeniden çalışır).
function loadApp({ lang = 'tr', theme = 'light' } = {}) {
  const path = require.resolve('../../../public/assets/js/i18n.js');
  delete require.cache[path];

  global.window = global;
  global.document = {
    documentElement: {
      lang,
      classList: {
        _dark: theme === 'dark',
        contains(name) { return name === 'dark' && this._dark; },
        toggle(name, value) { if (name === 'dark') this._dark = value; },
      },
    },
    querySelector() { return null; },
    getElementById() { return null; },
  };
  global.localStorage = {
    _store: {},
    getItem(key) { return Object.prototype.hasOwnProperty.call(this._store, key) ? this._store[key] : null; },
    setItem(key, value) { this._store[key] = String(value); },
  };
  delete global.App;
  require(path);
  return global.App;
}

module.exports = { loadApp };
