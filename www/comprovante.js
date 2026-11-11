/* comprovante.js — COMPROVANTE_V2: comprovante da batida em PDF para baixar (tela inicial, Minha folha, Histórico do dia e Apuração) */
(function () {
  var css = document.createElement('style');
  css.textContent = '.lin.comp{cursor:pointer}.lin.comp:hover .r{color:#fff}.ic-comp{margin-left:8px;font-size:13px;opacity:.7}' +
    '.comp-bts .btn{width:100%;padding:13px;font-size:14px}' +
    '.comp-bts .comp-mini{background:transparent;border:1px solid rgba(255,255,255,.25);border-radius:8px;color:#9fc0f5;font-size:12px;font-weight:700;padding:6px 10px;cursor:pointer}' +
    '.comp-bts .comp-mini:hover{background:rgba(255,255,255,.06)}.comp-bts .comp-mini:disabled{opacity:.5;cursor:default}' +
    '.comp-err{color:#ff9aa1;font-size:13px;font-weight:700;margin-top:6px}';
  document.head.appendChild(css);
  function obter(id, alvo) {
    return fetch('api_ponto.php?action=ponto_comprovante_pdf&id=' + encodeURIComponent(id) + (alvo ? '&alvo=' + encodeURIComponent(alvo) : ''), { credentials: 'same-origin' })
      .then(function (r) {
        if (r.status === 401) { location.href = 'index.html'; throw new Error('Sessão expirada.'); }
        if (!/pdf/.test(r.headers.get('Content-Type') || '')) return r.json().catch(function () { return {}; }).then(function (j) { throw new Error(j.erro || 'Não foi possível gerar o comprovante.'); });
        var nome = ((r.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/) || [])[1] || 'comprovante-ponto.pdf';
        return r.blob().then(function (b) { return { blob: b, nome: nome }; });
      });
  }
  function baixar(c) {
    var u = URL.createObjectURL(c.blob), a = document.createElement('a');
    a.href = u; a.download = c.nome; document.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(u); }, 5000);
  }
  // Botão "Baixar comprovante" dentro de um elemento (o PDF só é gerado ao clicar). o.compacto = botão pequeno (Histórico do dia)
  window.ptComprovanteBotoes = function (cx, o) {
    cx.innerHTML = '<div class="comp-bts"><button type="button" class="' + (o.compacto ? 'comp-mini' : 'btn') + '">⬇ Baixar comprovante' + (o.compacto ? ' (PDF)' : '') + '</button></div><div class="comp-err" hidden></div>';
    var b = cx.querySelector('button'), er = cx.querySelector('.comp-err');
    b.onclick = function () {
      var rot = b.textContent; b.disabled = true; b.textContent = 'Gerando…'; er.hidden = true;
      obter(o.id, o.alvo).then(baixar).catch(function (e) { er.textContent = e.message; er.hidden = false; })
        .then(function () { b.disabled = false; b.textContent = rot; });
    };
  };
  // Janela do comprovante (tela inicial): horário da batida + botão
  window.ptComprovanteAbrir = function (o) {
    var m = document.getElementById('modal-comp');
    if (!m) {
      m = document.createElement('div'); m.id = 'modal-comp'; m.className = 'modal hidden'; m.style.zIndex = '70';
      m.innerHTML = '<div class="modal-box"><div class="modal-topo"><span>Comprovante da batida</span><button type="button" class="x" data-x aria-label="Fechar">&times;</button></div><div id="comp-info"></div><div id="comp-bts"></div></div>';
      document.body.appendChild(m);
      m.querySelector('[data-x]').onclick = function () { m.classList.add('hidden'); };
      m.addEventListener('click', function (e) { if (e.target === m) m.classList.add('hidden'); });
    }
    var esc = function (s) { return String(s || '').replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
    document.getElementById('comp-info').innerHTML = '<div style="font-size:30px;font-weight:800;line-height:1.1">' + esc(o.hora) + '</div><div style="opacity:.75;margin:4px 0 16px">' + esc(o.tipo) + ' · ' + esc(o.data) + '</div>';
    window.ptComprovanteBotoes(document.getElementById('comp-bts'), o);
    m.classList.remove('hidden');
  };
})();
