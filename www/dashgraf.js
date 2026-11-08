/* dashgraf.js — gráficos do Dashboard em SVG/HTML puro (sem bibliotecas): colunas empilhadas, linha com área, barras horizontais,
   barra segmentada e tabela. Marcas finas (barras até 24px, extremo de dado arredondado em 4px, vão de 2px entre segmentos),
   dica ao passar o mouse ou focar com o teclado, legenda e visão em tabela. Textos de dados entram sempre por textContent. */
(function (root) {
  'use strict';
  var NS = 'http://www.w3.org/2000/svg';
  function el(tag, cls, txt) { var e = document.createElement(tag); if (cls) e.className = cls; if (txt != null) e.textContent = txt; return e; }
  function f1(n) { return Math.round(n * 10) / 10; }

  /* ---------- dica (tooltip) ---------- */
  var tipEl = null;
  function tipo() { if (!tipEl) { tipEl = el('div', 'gtip hidden'); tipEl.setAttribute('role', 'status'); document.body.appendChild(tipEl); } return tipEl; }
  function mostrar(x, y, titulo, linhas) {
    var t = tipo(); t.textContent = '';
    t.appendChild(el('div', 'gtip-t', titulo));
    linhas.forEach(function (l) {
      var r = el('div', 'gtip-r');
      if (l.cor) { var k = el('i', 'gtip-k'); k.style.background = l.cor; r.appendChild(k); }
      r.appendChild(el('b', 'gtip-v', l.valor)); r.appendChild(el('span', 'gtip-n', l.nome)); t.appendChild(r);
    });
    t.classList.remove('hidden');
    var w = t.offsetWidth, h = t.offsetHeight, px = x + 14, py = y - h - 12;
    if (px + w > window.innerWidth - 8) px = x - w - 14;
    if (px < 8) px = 8;
    if (py < 8) py = y + 18;
    t.style.left = px + 'px'; t.style.top = py + 'px';
  }
  function esconder() { if (tipEl) tipEl.classList.add('hidden'); }

  /* ---------- escala "bonita" ---------- */
  function escala(max, n) {
    if (!(max > 0)) return { max: 1, ticks: [0, 1] };
    var bruto = max / n, mag = Math.pow(10, Math.floor(Math.log10(bruto))), r = bruto / mag;
    var passo = (r <= 1 ? 1 : r <= 2 ? 2 : r <= 2.5 ? 2.5 : r <= 5 ? 5 : 10) * mag, topo = Math.ceil(max / passo - 1e-9) * passo, ticks = [];
    for (var v = 0; v <= topo + 1e-9; v += passo) ticks.push(f1(v * 1000) / 1000);
    return { max: topo, ticks: ticks };
  }
  function mostraRot(i, n, passo, arr) {
    var h = -1; arr.forEach(function (c, k) { if (c.hoje) h = k; });
    if (i === h) return true;
    if (h >= 0 && Math.abs(i - h) < passo) return false;
    return (n - 1 - i) % passo === 0;
  }
  function fmtN(v) { return (Math.round(v * 100) / 100).toLocaleString('pt-BR'); }

  /* ---------- colunas empilhadas ----------
     o: { cats:[{curto, rot, partes:[n..], linhas:[{nome,valor}], fds, hoje}], series:[{nome,cor}], altura, fmtY, aria } */
  function colunas(host, o) {
    host.textContent = '';
    var W = Math.max(280, host.clientWidth || 600), H = o.altura || 220, m = { t: 8, r: 6, b: 24, l: 34 };
    var iw = W - m.l - m.r, ih = H - m.t - m.b, n = o.cats.length, band = iw / n, bw = Math.min(24, Math.max(3, band * 0.66));
    var tot = o.cats.map(function (c) { return c.partes.reduce(function (a, b) { return a + b; }, 0); });
    var sc = escala(Math.max.apply(null, tot.concat([0])), 4), fy = o.fmtY || fmtN;
    function y(v) { return m.t + ih - v / sc.max * ih; }
    var s = '<svg class="gsvg" viewBox="0 0 ' + W + ' ' + H + '" width="' + W + '" height="' + H + '" role="img" aria-label="' + (o.aria || '') + '">';
    sc.ticks.forEach(function (t) {
      s += '<line class="g-grid' + (t === 0 ? ' g-base' : '') + '" x1="' + m.l + '" x2="' + (W - m.r) + '" y1="' + f1(y(t)) + '" y2="' + f1(y(t)) + '"/>' +
        '<text class="g-yt" x="' + (m.l - 6) + '" y="' + f1(y(t) + 3) + '" text-anchor="end">' + fy(t) + '</text>';
    });
    var passoX = Math.max(1, Math.ceil((Math.max.apply(null, o.cats.map(function (z) { return z.curto.length; })) * 6.4 + 8) / band));
    o.cats.forEach(function (c, i) {
      var cx = m.l + band * (i + 0.5), x = cx - bw / 2, acc = 0, topIdx = -1;
      c.partes.forEach(function (v, j) { if (v > 0) topIdx = j; });
      s += '<g class="g-col" data-i="' + i + '" tabindex="0" role="img" aria-label="' + (c.rot || '') + '">';
      if (c.fds) s += '<rect class="g-fds" x="' + f1(m.l + band * i) + '" y="' + m.t + '" width="' + f1(band) + '" height="' + ih + '"/>';
      s += '<rect class="g-hl" x="' + f1(m.l + band * i) + '" y="' + m.t + '" width="' + f1(band) + '" height="' + ih + '"/>';
      c.partes.forEach(function (v, j) {
        if (!(v > 0)) return;
        var y0 = y(acc), y1 = y(acc + v), gap = acc > 0 ? 2 : 0, hh = Math.max(1.5, (y0 - y1) - gap), yy = y1, cor = o.series[j].cor; acc += v;
        if (j === topIdx) {
          var r = Math.min(4, bw / 2, hh);
          s += '<path d="M' + f1(x) + ' ' + f1(yy + hh) + 'L' + f1(x) + ' ' + f1(yy + r) + 'Q' + f1(x) + ' ' + f1(yy) + ' ' + f1(x + r) + ' ' + f1(yy) + 'L' + f1(x + bw - r) + ' ' + f1(yy) + 'Q' + f1(x + bw) + ' ' + f1(yy) + ' ' + f1(x + bw) + ' ' + f1(yy + r) + 'L' + f1(x + bw) + ' ' + f1(yy + hh) + 'Z" fill="' + cor + '"/>';
        } else s += '<rect x="' + f1(x) + '" y="' + f1(yy) + '" width="' + f1(bw) + '" height="' + f1(hh) + '" fill="' + cor + '"/>';
      });
      if (mostraRot(i, n, passoX, o.cats)) s += '<text class="g-xt' + (c.hoje ? ' g-hojet' : c.fds ? ' g-fdst' : '') + '" x="' + f1(cx) + '" y="' + (H - 8) + '" text-anchor="middle">' + c.curto + '</text>';
      s += '</g>';
    });
    host.innerHTML = s + '</svg>';
    var svg = host.firstChild, cols = svg.querySelectorAll('.g-col'), ativo = -1;
    function ligar(i, px, py) {
      if (i < 0 || i >= n) return;
      if (ativo >= 0) cols[ativo].classList.remove('on');
      ativo = i; cols[i].classList.add('on');
      var c = o.cats[i], ls = c.linhas || c.partes.map(function (v, j) { return { cor: o.series[j].cor, nome: o.series[j].nome, valor: fmtN(v) }; });
      mostrar(px, py, c.rot, ls.map(function (l, j) { return { cor: l.cor !== undefined ? l.cor : (o.series[j] && o.series[j].cor), nome: l.nome, valor: l.valor }; }));
    }
    function sair() { if (ativo >= 0) cols[ativo].classList.remove('on'); ativo = -1; esconder(); }
    svg.addEventListener('pointermove', function (e) { var r = svg.getBoundingClientRect(); ligar(Math.floor((e.clientX - r.left - m.l) / band), e.clientX, e.clientY); });
    svg.addEventListener('pointerleave', sair);
    Array.prototype.forEach.call(cols, function (g, i) {
      g.addEventListener('focus', function () { var r = g.getBoundingClientRect(); ligar(i, r.left + r.width / 2, r.top + 20); });
      g.addEventListener('blur', sair);
    });
  }

  /* ---------- linha com área (uma série) ----------
     o: { pts:[{curto, rot, v|null, txt, fds, hoje}], cor, altura, fmtY, marca:{i, txt}, aria } */
  function linha(host, o) {
    host.textContent = '';
    var W = Math.max(280, host.clientWidth || 600), H = o.altura || 220, m = { t: 20, r: 12, b: 24, l: 38 };
    var iw = W - m.l - m.r, ih = H - m.t - m.b, n = o.pts.length, band = iw / n, fy = o.fmtY || fmtN;
    var vals = o.pts.map(function (p) { return p.v == null ? 0 : p.v; }), sc = escala(Math.max.apply(null, vals.concat([0])), 4);
    function X(i) { return m.l + band * (i + 0.5); }
    function Y(v) { return m.t + ih - v / sc.max * ih; }
    var s = '<svg class="gsvg" viewBox="0 0 ' + W + ' ' + H + '" width="' + W + '" height="' + H + '" role="img" aria-label="' + (o.aria || '') + '">';
    sc.ticks.forEach(function (t) {
      s += '<line class="g-grid' + (t === 0 ? ' g-base' : '') + '" x1="' + m.l + '" x2="' + (W - m.r) + '" y1="' + f1(Y(t)) + '" y2="' + f1(Y(t)) + '"/>' +
        '<text class="g-yt" x="' + (m.l - 6) + '" y="' + f1(Y(t) + 3) + '" text-anchor="end">' + fy(t) + '</text>';
    });
    o.pts.forEach(function (p, i) { if (p.fds) s += '<rect class="g-fds" x="' + f1(m.l + band * i) + '" y="' + m.t + '" width="' + f1(band) + '" height="' + ih + '"/>'; });
    var lastI = -1; o.pts.forEach(function (p, i) { if (p.v != null) lastI = i; });
    var d = '', a = '';
    o.pts.forEach(function (p, i) { if (p.v == null) return; d += (d ? 'L' : 'M') + f1(X(i)) + ' ' + f1(Y(p.v)); });
    if (lastI >= 0) {
      var first = o.pts.findIndex(function (p) { return p.v != null; });
      a = d + 'L' + f1(X(lastI)) + ' ' + f1(Y(0)) + 'L' + f1(X(first)) + ' ' + f1(Y(0)) + 'Z';
      s += '<path d="' + a + '" fill="' + o.cor + '" fill-opacity=".10"/><path d="' + d + '" fill="none" stroke="' + o.cor + '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
    }
    var passoX = Math.max(1, Math.ceil((Math.max.apply(null, o.pts.map(function (z) { return z.curto.length; })) * 6.4 + 8) / band));
    o.pts.forEach(function (p, i) { if (mostraRot(i, n, passoX, o.pts)) s += '<text class="g-xt' + (p.hoje ? ' g-hojet' : p.fds ? ' g-fdst' : '') + '" x="' + f1(X(i)) + '" y="' + (H - 8) + '" text-anchor="middle">' + p.curto + '</text>'; });
    if (o.marca && o.pts[o.marca.i] && o.pts[o.marca.i].v != null) { // rótulo seletivo: só o ponto de maior valor
      var mx = X(o.marca.i), my = Y(o.pts[o.marca.i].v), anc = mx > W - 90 ? 'end' : mx < 90 ? 'start' : 'middle';
      s += '<text class="g-marca" x="' + f1(mx) + '" y="' + f1(Math.max(11, my - 10)) + '" text-anchor="' + anc + '">' + o.marca.txt + '</text>';
    }
    if (lastI >= 0) s += '<circle cx="' + f1(X(lastI)) + '" cy="' + f1(Y(o.pts[lastI].v)) + '" r="4" fill="' + o.cor + '" class="g-dot"/>';
    s += '<line class="g-cross" x1="0" x2="0" y1="' + m.t + '" y2="' + (m.t + ih) + '" style="display:none"/><circle class="g-hdot" r="4" fill="' + o.cor + '" style="display:none"/>';
    s += '<rect class="g-hit" x="' + m.l + '" y="' + m.t + '" width="' + iw + '" height="' + ih + '" fill="transparent" tabindex="0"/>';
    host.innerHTML = s + '</svg>';
    var svg = host.firstChild, cross = svg.querySelector('.g-cross'), hd = svg.querySelector('.g-hdot'), hit = svg.querySelector('.g-hit');
    function ligar(i, px, py) {
      i = Math.max(0, Math.min(n - 1, i)); var p = o.pts[i]; if (p.v == null) { sair(); return; }
      cross.setAttribute('x1', X(i)); cross.setAttribute('x2', X(i)); cross.style.display = ''; hd.setAttribute('cx', X(i)); hd.setAttribute('cy', Y(p.v)); hd.style.display = '';
      mostrar(px, py, p.rot, [{ cor: o.cor, nome: o.nome || '', valor: p.txt }]);
    }
    function sair() { cross.style.display = 'none'; hd.style.display = 'none'; esconder(); }
    svg.addEventListener('pointermove', function (e) { var r = svg.getBoundingClientRect(); ligar(Math.floor((e.clientX - r.left - m.l) / band), e.clientX, e.clientY); });
    svg.addEventListener('pointerleave', sair);
    var foco = Math.max(0, lastI);
    hit.addEventListener('focus', function () { var r = svg.getBoundingClientRect(); ligar(foco, r.left + X(foco), r.top + m.t + 20); });
    hit.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowLeft' && e.key !== 'ArrowRight') return; e.preventDefault();
      foco = Math.max(0, Math.min(lastI, foco + (e.key === 'ArrowRight' ? 1 : -1))); var r = svg.getBoundingClientRect(); ligar(foco, r.left + X(foco), r.top + m.t + 20);
    });
    hit.addEventListener('blur', sair);
  }

  /* ---------- barras horizontais ranqueadas (um tom) ----------
     itens: [{nome, v, rot, sub, ir}], o: {cor, vazio} */
  function barrasH(host, itens, o) {
    host.textContent = '';
    if (!itens.length) { host.appendChild(el('div', 'vazio', (o && o.vazio) || 'Nada a mostrar neste período.')); return; }
    var max = Math.max.apply(null, itens.map(function (i) { return i.v; })) || 1;
    itens.forEach(function (i) {
      var r = el('div', 'rk'), a = el('div'), b = el('div', 'barra'), f = el('i'), v = el('span', 'v', i.rot);
      var nm = i.ir ? el('button', 'nm', i.nome) : el('span', 'nm sem', i.nome); if (i.ir) { nm.type = 'button'; nm.setAttribute('data-ir', i.ir); }
      a.appendChild(nm); if (i.sub) a.appendChild(el('div', 'sub', i.sub));
      f.style.width = Math.max(3, Math.round(i.v / max * 100)) + '%'; f.style.background = (o && o.cor) || 'var(--c1)'; b.appendChild(f);
      r.title = i.nome + ': ' + i.rot; r.appendChild(a); r.appendChild(b); r.appendChild(v); host.appendChild(r);
    });
  }

  /* ---------- barra segmentada alta (um todo dividido em partes) ----------
     partes: [{nome, v, cor}], o: {unidade, aria} */
  function segmentada(host, partes, o) {
    host.textContent = '';
    var total = partes.reduce(function (a, p) { return a + p.v; }, 0);
    if (!(total > 0)) { host.appendChild(el('div', 'vazio', (o && o.vazio) || 'Sem dados.')); return; }
    var bar = el('div', 'seg'); bar.setAttribute('role', 'img');
    bar.setAttribute('aria-label', partes.map(function (p) { return p.nome + ': ' + p.v + ' (' + Math.round(p.v / total * 100) + '%)'; }).join('; '));
    var leg = el('div', 'seg-leg');
    partes.forEach(function (p) {
      if (p.v > 0) {
        var b = el('div', 'seg-b'); b.style.flexGrow = p.v; b.style.background = p.cor; b.tabIndex = 0;
        var pct = Math.round(p.v / total * 100);
        b.setAttribute('data-pct', pct + '%');
        var dica = function (x, y) { mostrar(x, y, p.nome, [{ cor: p.cor, nome: (o && o.unidade) || '', valor: p.v + ' · ' + pct + '%' }]); };
        b.addEventListener('pointermove', function (e) { dica(e.clientX, e.clientY); });
        b.addEventListener('pointerleave', esconder);
        b.addEventListener('focus', function () { var r = b.getBoundingClientRect(); dica(r.left + r.width / 2, r.top); });
        b.addEventListener('blur', esconder);
        bar.appendChild(b);
      }
      var l = el('span', 'seg-l'), k = el('i'); k.style.background = p.cor; l.appendChild(k); l.appendChild(document.createTextNode(p.nome)); leg.appendChild(l);
    });
    host.appendChild(bar); host.appendChild(leg);
    Array.prototype.forEach.call(bar.children, function (b) { // o percentual só entra no bloco se o texto couber com folga
      if (b.offsetWidth >= 56) { b.textContent = b.getAttribute('data-pct'); }
    });
  }

  /* ---------- tabela (visão alternativa de todo gráfico) ---------- */
  function tabela(host, cab, linhas) {
    host.textContent = '';
    var t = el('table', 'gtab'), th = el('thead'), tr = el('tr');
    cab.forEach(function (c, i) { var h = el('th', i ? 'num' : '', c); tr.appendChild(h); }); th.appendChild(tr); t.appendChild(th);
    var tb = el('tbody');
    linhas.forEach(function (l) { var r = el('tr'); l.forEach(function (c, i) { r.appendChild(el('td', i ? 'num' : '', String(c))); }); tb.appendChild(r); });
    t.appendChild(tb); host.appendChild(el('div', 'gtab-w')).appendChild(t);
  }

  root.Graf = { colunas: colunas, linha: linha, barrasH: barrasH, segmentada: segmentada, tabela: tabela, esconder: esconder };
})(window);
