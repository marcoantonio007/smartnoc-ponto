/* nav.js — cabeçalho padrão do Ponto: logo + SmartNOC à esquerda e menu na ordem fixa
   Ponto · Folha · Apuração · Aprovações · Dashboard · Configuração · Sair. A página aberta fica em destaque (data-pag no <header>).
   Na tela de batida (data-menu="burger") e em telas estreitas o menu abre pelo ícone de 3 linhas. */
(function () {
  var cab = document.querySelector('header[data-pag]');
  if (!cab) return;
  var atual = cab.getAttribute('data-pag');
  var sempreBurger = cab.getAttribute('data-menu') === 'burger';
  var ITENS = [
    { k: 'ponto', t: 'Ponto', h: 'index.html' },
    { k: 'folha', t: 'Folha', h: 'folha.html', quem: 'vinculado' },
    { k: 'apuracao', t: 'Apuração', h: 'apuracao.html', quem: 'gestor' },
    { k: 'aprovacoes', t: 'Aprovações', h: 'aprovacoes.html', quem: 'gestor' },
    { k: 'dashboard', t: 'Dashboard', h: 'dashboard.html', quem: 'gestor' },
    { k: 'config', t: 'Configuração', h: 'gestao.html', quem: 'gestor' }
  ];
  var CH = 'pt_nav';
  function ler() { try { return JSON.parse(sessionStorage.getItem(CH)) || null; } catch (e) { return null; } }
  function gravar(p) { try { sessionStorage.setItem(CH, JSON.stringify(p)); } catch (e) {} }

  var LOGO = '<svg viewBox="0 0 64 64" fill="none" stroke="#fff" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' +
    '<circle cx="32" cy="35" r="21"/><path d="M32 24V35L41 41"/><path d="M27 7H37M32 7V12" stroke-width="4"/>' +
    '<circle cx="32" cy="19" r="2" fill="#fff" stroke="none"/><circle cx="48" cy="35" r="2" fill="#fff" stroke="none"/><circle cx="32" cy="51" r="2" fill="#fff" stroke="none"/><circle cx="16" cy="35" r="2" fill="#fff" stroke="none"/></svg>';
  cab.innerHTML =
    '<div class="marca"><span class="mini-logo">' + LOGO + '</span><span>SmartNOC</span></div>' +
    '<button type="button" id="nav-burger" class="nav-burger" aria-label="Abrir menu" aria-expanded="false" aria-controls="nav-menu"><span></span><span></span><span></span></button>' +
    '<nav id="nav-menu" class="nav-menu" aria-label="Menu principal">' +
    ITENS.map(function (i) {
      var on = i.k === atual;
      return '<a class="nav-i' + (on ? ' on' : '') + '" data-k="' + i.k + '" href="' + i.h + '"' + (on ? ' aria-current="page"' : '') + '>' + i.t + (i.k === 'aprovacoes' ? ' <span class="nav-nb hidden"></span>' : '') + '</a>';
    }).join('') +
    '<button type="button" id="btn-sair" class="nav-i nav-sair">Sair</button></nav>';

  var burger = document.getElementById('nav-burger'), menu = document.getElementById('nav-menu');
  function abrir(sim) { menu.classList.toggle('aberto', sim); burger.setAttribute('aria-expanded', sim ? 'true' : 'false'); }
  burger.addEventListener('click', function (e) { e.stopPropagation(); abrir(!menu.classList.contains('aberto')); });
  document.addEventListener('click', function (e) { if (menu.classList.contains('aberto') && !cab.contains(e.target)) abrir(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') abrir(false); });

  function compacto() {
    var c = sempreBurger || (window.matchMedia && window.matchMedia('(max-width:759px)').matches);
    cab.classList.toggle('nav-comp', !!c);
    if (!c) abrir(false);
  }
  compacto();
  if (window.matchMedia) { var mq = window.matchMedia('(max-width:759px)'); if (mq.addEventListener) mq.addEventListener('change', compacto); else if (mq.addListener) mq.addListener(compacto); }

  function badge(n) { // quantidade de solicitações aguardando aprovação, ao lado de "Aprovações"
    var b = menu.querySelector('[data-k="aprovacoes"] .nav-nb'); if (!b) return;
    b.textContent = n; b.classList.toggle('hidden', !(n > 0));
  }
  var buscouPend = false;
  function contarPend() {
    fetch('api_ponto.php?action=ponto_admin_pendencias', { credentials: 'same-origin' }).then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.sucesso) badge(j.itens.length); }).catch(function () {});
  }
  function aplicar(p) { // p = resposta de ponto_me (gestor, vinculado)
    p = p || {};
    if (p.gestor && !buscouPend) { buscouPend = true; contarPend(); }
    gravar({ gestor: !!p.gestor, vinculado: p.vinculado !== false });
    ITENS.forEach(function (i) {
      var a = menu.querySelector('[data-k="' + i.k + '"]');
      var ok = !i.quem || (i.quem === 'gestor' ? !!p.gestor : p.vinculado !== false);
      if (a) a.classList.toggle('hidden', !ok);
    });
  }
  var c0 = ler(); aplicar(c0 || { gestor: false, vinculado: true });

  function sair() {
    try { sessionStorage.removeItem(CH); } catch (e) {}
    return fetch('api_ponto.php?action=ponto_logout', { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: '{}' })
      .catch(function () {}).then(function () { location.href = 'index.html'; });
  }
  document.getElementById('btn-sair').onclick = sair; // a tela de batida troca por um tratamento próprio (encerra a câmera e o relógio)

  window.ptNav = { aplicar: aplicar, badge: badge, limpar: function () { try { sessionStorage.removeItem(CH); } catch (e) {} aplicar({ gestor: false, vinculado: true }); } };

  if (atual !== 'ponto') { // as demais páginas descobrem o perfil por conta própria
    fetch('api_ponto.php?action=ponto_me', { credentials: 'same-origin' }).then(function (r) { return r.json(); })
      .then(function (j) { if (j && j.sucesso) aplicar(j); }).catch(function () {});
  }
})();
