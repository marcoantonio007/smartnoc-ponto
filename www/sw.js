// sw.js — service worker: permite instalar como app e abrir a página do ponto SEM internet
// (a tela, o reconhecimento facial e a fila de batidas funcionam offline; a API sempre vai direto à rede).
var V = 'ponto-mail1';
var SHELL = ['./', 'index.html', 'nav.js', 'tema.css', 'manifest.json', 'icon-192.png', 'icon-512.png'];
var CDN = ['https://cdn.jsdelivr.net/npm/@vladmandic/face-api@1.7.12/dist/face-api.js'];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(V).then(function (c) {
    return Promise.all(SHELL.concat(CDN).map(function (u) { return c.add(new Request(u, { cache: 'reload' })).catch(function () {}); }));
  }).then(function () { return self.skipWaiting(); }));
});
self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (ks) {
    return Promise.all(ks.filter(function (k) { return k !== V; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

function guardar(req, res) { if (res && (res.ok || res.type === 'opaque')) { var cp = res.clone(); caches.open(V).then(function (c) { c.put(req, cp); }); } }
function redeComCache(req) { // rede primeiro (limite de 6 s); sem rede, usa a cópia guardada
  return new Promise(function (ok, no) {
    var t = setTimeout(function () { no(new Error('tempo esgotado')); }, 6000);
    fetch(req.mode === 'navigate' ? req : new Request(req, { cache: 'no-cache' })).then(function (r) { clearTimeout(t); ok(r); }, function (er) { clearTimeout(t); no(er); });
  }).then(function (res) { if (res.ok && res.type === 'basic') guardar(req, res); return res; }).catch(function () {
    return caches.match(req, { ignoreSearch: true }).then(function (m) {
      if (m) return m;
      var p = new URL(req.url).pathname;
      if (req.mode === 'navigate' && (p === '/' || /\/index\.html$/.test(p))) return caches.match('index.html');
      return Response.error();
    });
  });
}
function cacheFirst(req) {
  return caches.match(req).then(function (m) {
    return m || fetch(req).then(function (res) { guardar(req, res); return res; });
  });
}
self.addEventListener('fetch', function (e) {
  var r = e.request;
  if (r.method !== 'GET') return;
  var u = new URL(r.url);
  if (u.origin === self.location.origin) {
    if (u.pathname.indexOf('api_ponto.php') >= 0) return; // dados sempre da rede
    if (r.mode === 'navigate' || /\.(html|js|css|json|png)$/.test(u.pathname)) e.respondWith(redeComCache(r));
    return;
  }
  if (u.hostname === 'cdn.jsdelivr.net') e.respondWith(cacheFirst(r));
});
