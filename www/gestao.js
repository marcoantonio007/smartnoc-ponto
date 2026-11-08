/* gestao.js — Gestão do ponto (somente admin/mestre): aprovações, registros, colaboradores e regra de horário. */
(function () {
  'use strict';
  var doc = document;
  var DIAS = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
  var ORDEM_DIAS = [1, 2, 3, 4, 5, 6, 0];
  var ROT = { ENTRADA: 'Início da atividade', SAIDA_ALMOCO: 'Saída para o almoço', RETORNO_ALMOCO: 'Retorno do almoço', SAIDA: 'Fim de expediente' };
  var APR = doc.body.getAttribute('data-modo') === 'aprovacoes'; // página Aprovações (menu) usa só a aba de aprovações
  var aba = APR ? 'aprovacoes' : 'registros', contPend = 0;

  function el(id) { return doc.getElementById(id); }
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function vpLinks(i) { var l = i.vidpols || []; return l.length ? l.map(function (v) { return '<a href="https://videopolicia.atlassian.net/browse/VIDPOL-' + encodeURIComponent(v) + '" target="_blank" rel="noopener noreferrer" title="Abrir o chamado no Jira" style="color:#9fc0f5;text-decoration:underline;font-weight:700">VIDPOL-' + esc(v) + '</a>'; }).join(', ') : esc(i.justificativa || ''); }
  function toast(m, t) {
    var e = el('erro-geral'); e.textContent = m;
    e.className = 'rounded-lg font-bold text-sm p-3 mb-3 ' + (t === 'error' ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700');
    clearTimeout(toast.t); toast.t = setTimeout(function () { e.classList.add('hidden'); }, t === 'error' ? 8000 : 3500);
  }
  function hhmm(ts) { return ts ? String(ts).substring(11, 16) : ''; }
  function dataBR(s) { var p = s.split('-'); return p[2] + '/' + p[1] + '/' + p[0]; }
  function fmtMin(m) { m = Math.abs(Math.round(m)); return Math.floor(m / 60) + 'h' + (m % 60 ? String(m % 60).padStart(2, '0') + 'm' : ''); }
  function hoje() { var d = new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function periodoFolha() { // período da folha atual: de 15 do mês anterior a 14 do mês de referência (ex.: folha de novembro = 15/10 a 14/11)
    var d = new Date(), y = d.getFullYear(), m = d.getMonth(), a, b;
    if (d.getDate() >= 15) { a = new Date(y, m, 15); b = new Date(y, m + 1, 14); } else { a = new Date(y, m - 1, 15); b = new Date(y, m, 14); }
    function f(x) { return x.getFullYear() + '-' + String(x.getMonth() + 1).padStart(2, '0') + '-' + String(x.getDate()).padStart(2, '0'); }
    return [f(a), f(b)];
  }
  function diasAtras(n) { var d = new Date(); d.setDate(d.getDate() - n); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }

  async function api(action, body) {
    var r = await fetch('api_ponto.php?action=' + action, body === undefined ? { credentials: 'same-origin' } :
      { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    var txt = await r.text(), j;
    try { j = JSON.parse(txt); } catch (e) { throw new Error('Resposta inválida do servidor.'); }
    if (r.status === 401) { location.href = 'index.html'; throw new Error('Sessão expirada.'); }
    return j;
  }

  var ABAS = [['registros', 'Registros'], ['colaboradores', 'Colaboradores'], ['regra', 'Regra de horário']];
  function renderAbas() {
    if (APR) { el('abas').classList.add('hidden'); if (window.ptNav) ptNav.badge(contPend); return; }
    el('abas').innerHTML = ABAS.map(function (a) {
      var ativa = a[0] === aba;
      return '<button type="button" data-aba="' + a[0] + '" class="px-3 py-2 -mb-px border-b-2 whitespace-nowrap ' + (ativa ? 'border-blue-600 text-blue-700' : 'border-transparent text-slate-500 hover:text-slate-700') + '">' + a[1] +
        (a[0] === 'aprovacoes' && contPend ? ' <span class="ml-1 px-1.5 py-0.5 rounded-full bg-red-500 text-white text-[10px]">' + contPend + '</span>' : '') + '</button>';
    }).join('');
    Array.prototype.forEach.call(el('abas').querySelectorAll('[data-aba]'), function (b) { b.onclick = function () { aba = b.getAttribute('data-aba'); abrirAba(); }; });
  }
  async function abrirAba() {
    renderAbas();
    var c = el('corpo'); c.innerHTML = '<div class="text-sm text-slate-400">Carregando…</div>';
    try {
      if (aba === 'aprovacoes') await abaAprovacoes(c);
      else if (aba === 'registros') abaRegistros(c);
      else if (aba === 'colaboradores') await abaColaboradores(c);
      else await abaRegra(c);
    } catch (e) { c.innerHTML = '<div class="text-sm font-bold text-red-600">' + esc(e.message) + '</div>'; }
  }

  /* ------------------------------ filtro com busca por nome ------------------------------ */
  function semAcento(t) { return String(t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase(); }
  /* Liga um campo de texto a uma lista de nomes que aparece enquanto se digita; ao escolher, chama aoEscolher(nome). */
  function autocompletar(inp, nomes, aoEscolher) {
    var box = doc.createElement('div'); box.className = 'ta-lista hidden'; inp.parentNode.appendChild(box);
    var idx = -1;
    function fechar() { box.classList.add('hidden'); idx = -1; }
    function lista() {
      var q = semAcento(inp.value.trim());
      return nomes().filter(function (n) { return !q || semAcento(n).indexOf(q) >= 0; }).slice(0, 50);
    }
    function mostrar() {
      var l = lista(); idx = -1;
      box.innerHTML = l.length ? l.map(function (n) { return '<button type="button" data-n="' + esc(n) + '">' + esc(n) + '</button>'; }).join('') : '<div class="vz">Nenhum colaborador encontrado.</div>';
      box.classList.remove('hidden');
      Array.prototype.forEach.call(box.querySelectorAll('[data-n]'), function (b) {
        b.onmousedown = function (ev) { ev.preventDefault(); escolher(b.getAttribute('data-n')); };
      });
    }
    function escolher(n) { inp.value = n; fechar(); aoEscolher(n); }
    inp.addEventListener('focus', mostrar);
    inp.addEventListener('input', function () { mostrar(); aoEscolher(null, inp.value.trim()); });
    inp.addEventListener('blur', function () { setTimeout(fechar, 120); });
    inp.addEventListener('keydown', function (ev) {
      var bs = box.querySelectorAll('[data-n]');
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') {
        ev.preventDefault(); if (box.classList.contains('hidden')) mostrar(); bs = box.querySelectorAll('[data-n]'); if (!bs.length) return;
        idx = (idx + (ev.key === 'ArrowDown' ? 1 : -1) + bs.length) % bs.length;
        Array.prototype.forEach.call(bs, function (b, i) { b.classList.toggle('on', i === idx); });
        bs[idx].scrollIntoView({ block: 'nearest' });
      } else if (ev.key === 'Enter') {
        if (idx >= 0 && bs[idx]) { ev.preventDefault(); escolher(bs[idx].getAttribute('data-n')); } else fechar();
      } else if (ev.key === 'Escape') fechar();
    });
  }

  /* ------------------------------ aprovações ------------------------------ */
  var pendFiltro = '';
  /* APROV_FILTRO_V2 — pesquisa de aprovações por colaborador, período e status + cancelamento de aprovações */
  var apF = { nome: '', ini: '', fim: '', status: 'pendente' }, apNomes = null;
  var ST_ROT = { pendente: 'Pendente', aprovada: 'Aprovada', recusada: 'Recusada', cancelada: 'Cancelada' };
  var ST_COR = { pendente: 'bg-amber-100 text-amber-700', aprovada: 'bg-emerald-100 text-emerald-700', recusada: 'bg-red-100 text-red-700', cancelada: 'bg-slate-200 text-slate-600' };
  function diasFrente(n) { var d = new Date(); d.setDate(d.getDate() + n); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  async function abaAprovacoes(c) {
    if (!apF.ini) { apF.ini = periodoFolha()[0]; apF.fim = periodoFolha()[1]; }
    var L = 'text-[10px] font-bold text-slate-500 uppercase';
    var opt = [['pendente', 'Pendente'], ['aprovada', 'Aprovada'], ['recusada', 'Recusada'], ['cancelada', 'Cancelada'], ['todas', 'Todos']];
    c.innerHTML = '<div class="flex flex-wrap gap-2 items-end mb-3">' +
      '<label class="' + L + '">Colaborador<span class="ta block mt-1" style="display:block"><input id="ap-nome" class="text-xs block" autocomplete="off" placeholder="Todos" value="' + esc(apF.nome) + '"></span></label>' +
      '<label class="' + L + '">De<input id="ap-ini" type="date" class="mt-1 text-xs block" value="' + apF.ini + '"></label>' +
      '<label class="' + L + '">Até<input id="ap-fim" type="date" class="mt-1 text-xs block" value="' + apF.fim + '"></label>' +
      '<label class="' + L + '">Status<select id="ap-status" class="mt-1 text-xs block">' + opt.map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === apF.status ? ' selected' : '') + '>' + o[1] + '</option>'; }).join('') + '</select></label>' +
      '<button id="ap-buscar" type="button" class="px-3 py-1.5 rounded bg-blue-600 text-white text-xs font-bold">Buscar</button>' +
      '<button id="ap-limpar" type="button" class="px-3 py-1.5 rounded border border-slate-300 text-xs font-bold">Limpar</button>' +
      '<span id="ap-lote" class="flex gap-2 ml-auto"></span></div>' +
      '<div class="text-[10px] text-slate-400 mb-2">O período considera o dia da solicitação (para abonos, o dia inicial/final).</div>' +
      '<div id="ap-info" class="text-xs text-slate-500 mb-2"></div><div id="ap-tab"></div>';
    var itens = [];
    function dentro(i) { return i.data >= apF.ini && i.data <= apF.fim; }
    async function buscar() {
      var nome = el('ap-nome').value.trim(), ini = el('ap-ini').value, fim = el('ap-fim').value, st = el('ap-status').value;
      if (!ini || !fim || ini > fim) return toast('Informe um período válido (De ≤ Até).', 'error');
      apF = { nome: nome, ini: ini, fim: fim, status: st };
      el('ap-tab').innerHTML = '<div class="text-sm text-slate-400 py-4">Carregando…</div>';
      try {
        itens = [];
        if (st !== 'pendente') {
          var j = await api('ponto_admin_historico&inicio=' + ini + '&fim=' + fim + '&nome=' + encodeURIComponent(nome) + '&status=' + st);
          if (!j.sucesso) throw new Error(j.erro);
          itens = j.itens;
        }
        var p = await api('ponto_admin_pendencias');
        if (p.sucesso) {
          contPend = p.itens.length; renderAbas();
          if (st === 'pendente' || st === 'todas') {
            var q = semAcento(nome);
            p.itens.forEach(function (i) { if ((!q || semAcento(i.nome).indexOf(q) >= 0) && dentro(i)) { i.status = 'pendente'; i.cancelavel = false; itens.push(i); } });
          }
        }
        itens.sort(function (a, b) { return (b.data + b.nome) > (a.data + a.nome) ? 1 : -1; });
        desenhar();
      } catch (e) { el('ap-tab').innerHTML = '<div class="text-sm font-bold text-red-600">' + esc(e.message) + '</div>'; }
    }
    function desenhar() {
      var pend = itens.filter(function (i) { return i.status === 'pendente' && !i.propria; });
      el('ap-info').textContent = itens.length + ' solicitação(ões) encontrada(s)' + (apF.nome ? ' para "' + apF.nome + '"' : '') + ' de ' + dataBR(apF.ini) + ' a ' + dataBR(apF.fim) + ' — ' + (ST_ROT[apF.status] || 'todos os status') + '.';
      el('ap-lote').innerHTML = pend.length ? '<button id="ap-todos" type="button" class="px-3 py-1.5 rounded bg-emerald-600 text-white text-xs font-bold">Aprovar todos (' + pend.length + ')</button>' +
        '<button id="ap-recusar-todos" type="button" class="px-3 py-1.5 rounded bg-red-600 text-white text-xs font-bold">Recusar todos (' + pend.length + ')</button>' : '';
      if (pend.length) { el('ap-todos').onclick = function () { lote('aprovar', pend); }; el('ap-recusar-todos').onclick = function () { lote('recusar', pend); }; }
      if (!itens.length) { el('ap-tab').innerHTML = '<div class="text-sm text-slate-400 font-semibold py-6 text-center">Nenhuma solicitação encontrada para os filtros escolhidos.</div>'; return; }
      el('ap-tab').innerHTML = '<div class="overflow-x-auto"><table class="w-full text-xs"><thead class="bg-slate-50 text-[10px] uppercase text-slate-500"><tr>' +
        '<th class="text-left px-2 py-2">Colaborador</th><th class="text-left px-2 py-2">Pedido</th><th class="text-left px-2 py-2">Dia</th><th class="text-left px-2 py-2">Batida</th><th class="text-left px-2 py-2">Hora</th>' +
        '<th class="text-left px-2 py-2">Justificativa</th><th class="text-left px-2 py-2">Status</th><th></th></tr></thead><tbody>' +
        itens.map(function (i, k) {
          var rem = i.solic === 'remocao', ate = i.solic === 'atestado' || i.solic === 'extra' || i.solic === 'folga_banco';
          var quem = i.decidido_por ? '<div class="text-[10px] text-slate-500 mt-0.5">por ' + esc(i.decidido_por) + (i.decidido_em ? ' · ' + dataBR(i.decidido_em.substring(0, 10)) + ' ' + i.decidido_em.substring(11) : '') + '</div>' : '';
          var mot = i.motivo_decisao ? '<div class="text-[10px] text-slate-500">“' + esc(i.motivo_decisao) + '”</div>' : '';
          var acao = i.cancelavel ? '<button data-cancelar="' + k + '" class="px-2 py-1 rounded bg-red-600 text-white font-bold">Cancelar aprovação</button>' :
            i.status === 'pendente' ? (i.propria ? '<span class="text-slate-500 font-bold">Pedido seu — outro gestor decide</span>' :
              '<button data-dec="aprovar" data-k="' + k + '" class="px-2 py-1 rounded bg-emerald-600 text-white font-bold mr-1">Aprovar</button><button data-dec="recusar" data-k="' + k + '" class="px-2 py-1 rounded bg-red-600 text-white font-bold">Recusar</button>') : '';
          return '<tr><td class="px-2 py-2 font-bold uppercase">' + esc(i.nome) + '</td><td class="px-2 py-2"><span class="px-1.5 py-0.5 rounded text-[10px] font-black uppercase ' +
            (ate ? 'bg-amber-100 text-amber-700' : rem ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700') + '">' + (i.solic === 'folga_banco' ? 'Folga (banco)' : i.solic === 'extra' ? 'Hora extra' : ate ? 'Abono' : rem ? 'Remoção' : 'Inclusão') + '</span></td>' +
            '<td class="px-2 py-2">' + dataBR(i.data) + '</td><td class="px-2 py-2">' + esc(i.rotulo) + '</td><td class="px-2 py-2 font-black tabular-nums">' + esc(i.hora) + '</td>' +
            '<td class="px-2 py-2 max-w-xs">' + (i.solic === 'extra' ? vpLinks(i) : esc(i.justificativa)) + (i.solic === 'atestado' && i.tem_anexo ? ' <a href="api_ponto.php?action=ponto_atestado_arquivo&id=' + i.id + '" target="_blank" rel="noopener" class="text-blue-600 underline">📎 Ver anexo</a>' : '') + '</td>' +
            '<td class="px-2 py-2"><span class="px-1.5 py-0.5 rounded text-[10px] font-black uppercase ' + (ST_COR[i.status] || '') + '">' + (ST_ROT[i.status] || esc(i.status)) + '</span>' + quem + mot + '</td>' +
            '<td class="px-2 py-2 whitespace-nowrap text-right">' + acao + '</td></tr>';
        }).join('') + '</tbody></table></div>';
      Array.prototype.forEach.call(el('ap-tab').querySelectorAll('[data-cancelar]'), function (b) {
        b.onclick = async function () {
          var i = itens[+b.getAttribute('data-cancelar')];
          var motivo = prompt('Cancelar a aprovação de ' + i.nome + ' — ' + i.rotulo + ' (' + dataBR(i.data) + ').\nOs efeitos da aprovação serão desfeitos.' + (i.solic === 'extra' ? '\nA(s) batida(s) da hora extra serão removidas da folha e os VIDPOL excluídos; o colaborador precisará lançar a batida de novo.' : '') + '\nMotivo do cancelamento (obrigatório):') || '';
          if (motivo.trim().length < 5) return toast('Informe o motivo do cancelamento.', 'error');
          b.disabled = true;
          var r = await api('ponto_admin_cancelar', { id: i.id, solic: i.solic, motivo: motivo });
          if (!r.sucesso) { toast(r.erro, 'error'); b.disabled = false; return; }
          toast('Aprovação cancelada.'); buscar();
        };
      });
      Array.prototype.forEach.call(el('ap-tab').querySelectorAll('[data-dec]'), function (b) {
        b.onclick = async function () {
          var i = itens[+b.getAttribute('data-k')], dec = b.getAttribute('data-dec'), motivo = '';
          if (dec === 'recusar') { motivo = prompt('Motivo da recusa (obrigatório):') || ''; if (motivo.trim().length < 5) return toast('Informe o motivo da recusa.', 'error'); }
          b.disabled = true;
          var r = await api('ponto_admin_decidir', { id: i.id, solic: i.solic, decisao: dec, motivo: motivo });
          if (!r.sucesso) { toast(r.erro, 'error'); b.disabled = false; return; }
          toast(dec === 'aprovar' ? 'Solicitação aprovada.' : 'Solicitação recusada.'); buscar();
        };
      });
    }
    async function lote(dec, alvo) {
      var motivo = '';
      if (dec === 'recusar') { motivo = prompt('Recusar ' + alvo.length + ' solicitação(ões).\nMotivo da recusa (obrigatório):') || ''; if (motivo.trim().length < 5) return toast('Informe o motivo da recusa.', 'error'); }
      else if (!confirm('Aprovar ' + alvo.length + ' solicitação(ões)?')) return;
      var ok = 0, falhas = [];
      for (var k = 0; k < alvo.length; k++) {
        try { var r = await api('ponto_admin_decidir', { id: alvo[k].id, solic: alvo[k].solic, decisao: dec, motivo: motivo }); if (r.sucesso) ok++; else falhas.push(r.erro); } catch (e) { falhas.push(e.message); }
      }
      if (falhas.length) toast(ok + ' decidida(s); ' + falhas.length + ' com erro: ' + falhas[0], 'error'); else toast(ok + ' solicitação(ões) ' + (dec === 'aprovar' ? 'aprovada(s).' : 'recusada(s).'));
      buscar();
    }
    el('ap-buscar').onclick = buscar;
    el('ap-status').onchange = buscar; el('ap-ini').onchange = buscar; el('ap-fim').onchange = buscar;
    el('ap-nome').addEventListener('keydown', function (ev) { if (ev.key === 'Enter') buscar(); });
    el('ap-limpar').onclick = function () { apF = { nome: '', ini: periodoFolha()[0], fim: periodoFolha()[1], status: 'pendente' }; abaAprovacoes(c); };
    if (apNomes) autocompletar(el('ap-nome'), function () { return apNomes; }, function (n) { if (n != null) buscar(); });
    else api('ponto_admin_colaboradores').then(function (cj) {
      if (!cj.sucesso) return;
      apNomes = cj.itens.map(function (i) { return i.nome; }).sort();
      autocompletar(el('ap-nome'), function () { return apNomes; }, function (n) { if (n != null) buscar(); });
    }).catch(function () {});
    await buscar();
  }
  async function abaAprovacoesPend(c) {
    var j = await api('ponto_admin_pendencias');
    if (!j.sucesso) throw new Error(j.erro);
    var itens = j.itens;
    contPend = itens.length; renderAbas();
    if (!itens.length) { c.innerHTML = '<div class="text-sm text-slate-400 font-semibold py-6 text-center">Nenhuma solicitação aguardando aprovação.</div>'; return; }
    var nomes = []; itens.forEach(function (i) { if (nomes.indexOf(i.nome) < 0) nomes.push(i.nome); }); nomes.sort();
    if (pendFiltro && !visiveis().length) pendFiltro = '';
    c.innerHTML = '<div class="barra-ap"><div><label class="text-[10px] font-bold text-slate-500 uppercase block">Filtrar colaborador</label>' +
      '<div class="ta mt-1"><input id="ap-filtro" class="text-xs" autocomplete="off" placeholder="Digite o nome…" value="' + esc(pendFiltro) + '"></div>' +
      ' <button id="ap-limpar" type="button" class="px-2 py-1 rounded border text-xs font-bold ml-1">Limpar</button></div>' +
      '<div class="acoes"><button id="ap-todos" type="button" class="px-3 py-1.5 rounded bg-emerald-600 text-white text-xs font-bold">Aprovar todos</button>' +
      '<button id="ap-recusar-todos" type="button" class="px-3 py-1.5 rounded bg-red-600 text-white text-xs font-bold">Recusar todos</button></div></div>' +
      '<div id="ap-info" class="text-xs text-slate-500 mb-2"></div><div id="ap-tab"></div>';

    function visiveis() { var q = semAcento(pendFiltro); return itens.filter(function (i) { return !q || semAcento(i.nome).indexOf(q) >= 0; }); }
    function desenhar() {
      var v = visiveis(), dec = v.filter(function (i) { return !i.propria; });
      el('ap-info').textContent = v.length + ' solicitação(ões) exibida(s)' + (pendFiltro ? ' para "' + pendFiltro + '"' : '') + ' de ' + itens.length + ' no total.';
      el('ap-todos').disabled = el('ap-recusar-todos').disabled = !dec.length;
      el('ap-todos').textContent = 'Aprovar todos' + (dec.length ? ' (' + dec.length + ')' : '');
      el('ap-recusar-todos').textContent = 'Recusar todos' + (dec.length ? ' (' + dec.length + ')' : '');
      if (!v.length) { el('ap-tab').innerHTML = '<div class="text-sm text-slate-400 font-semibold py-6 text-center">Nenhuma solicitação para este colaborador.</div>'; return; }
      el('ap-tab').innerHTML = '<div class="overflow-x-auto"><table class="w-full text-xs"><thead class="bg-slate-50 text-[10px] uppercase text-slate-500"><tr>' +
        '<th class="text-left px-2 py-2">Colaborador</th><th class="text-left px-2 py-2">Pedido</th><th class="text-left px-2 py-2">Dia</th><th class="text-left px-2 py-2">Batida</th><th class="text-left px-2 py-2">Hora</th><th class="text-left px-2 py-2">Justificativa</th><th></th></tr></thead><tbody class="divide-y">' +
        v.map(function (i) {
          var rem = i.solic === 'remocao', ate = i.solic === 'atestado' || i.solic === 'extra' || i.solic === 'folga_banco';
          var bt = i.propria ? '<span class="text-slate-500 font-bold">Pedido seu — outro gestor decide</span>' :
            '<button data-dec="aprovar" data-solic="' + i.solic + '" data-id="' + i.id + '" class="px-2 py-1 rounded bg-emerald-600 text-white font-bold mr-1">Aprovar</button>' +
            '<button data-dec="recusar" data-solic="' + i.solic + '" data-id="' + i.id + '" class="px-2 py-1 rounded bg-red-600 text-white font-bold">Recusar</button>';
          return '<tr><td class="px-2 py-2 font-bold uppercase">' + esc(i.nome) + '</td><td class="px-2 py-2"><span class="px-1.5 py-0.5 rounded text-[10px] font-black uppercase ' + (ate ? 'bg-amber-100 text-amber-700' : rem ? 'bg-red-100 text-red-700' : 'bg-emerald-100 text-emerald-700') + '">' + (i.solic === 'folga_banco' ? 'Folga (banco)' : i.solic === 'extra' ? 'Hora extra' : ate ? 'Abono' : rem ? 'Remoção' : 'Inclusão') + '</span></td><td class="px-2 py-2">' + dataBR(i.data) + '</td><td class="px-2 py-2">' + esc(i.rotulo) + '</td>' +
            '<td class="px-2 py-2 font-black tabular-nums">' + esc(i.hora) + '</td><td class="px-2 py-2 max-w-xs">' + (i.solic === 'extra' ? vpLinks(i) : esc(i.justificativa)) + (ate && i.tem_anexo ? ' <a href="api_ponto.php?action=ponto_atestado_arquivo&id=' + i.id + '" target="_blank" rel="noopener" class="font-bold text-blue-600 underline">📎 Ver anexo</a>' : '') + '</td>' +
            '<td class="px-2 py-2 whitespace-nowrap text-right">' + bt + '</td></tr>';
        }).join('') + '</tbody></table></div>';
      Array.prototype.forEach.call(el('ap-tab').querySelectorAll('[data-dec]'), function (b) {
        b.onclick = async function () {
          var dec = b.getAttribute('data-dec'), motivo = '';
          if (dec === 'recusar') { motivo = prompt('Motivo da recusa (obrigatório):') || ''; if (motivo.trim().length < 5) return toast('Informe o motivo da recusa.', 'error'); }
          b.disabled = true;
          var r = await api('ponto_admin_decidir', { id: +b.getAttribute('data-id'), solic: b.getAttribute('data-solic'), decisao: dec, motivo: motivo });
          if (!r.sucesso) { toast(r.erro, 'error'); b.disabled = false; return; }
          toast(dec === 'aprovar' ? 'Solicitação aprovada.' : 'Solicitação recusada.'); abrirAba();
        };
      });
    }
    /* decide em lote tudo o que está na tela (respeita o filtro); os pedidos do próprio gestor ficam de fora */
    async function lote(dec) {
      var alvo = visiveis().filter(function (i) { return !i.propria; }), motivo = '';
      if (!alvo.length) return;
      var esc_ = pendFiltro ? ' de "' + pendFiltro + '"' : '';
      if (dec === 'recusar') {
        motivo = prompt('Recusar ' + alvo.length + ' solicitação(ões)' + esc_ + '.\nMotivo da recusa (obrigatório):') || '';
        if (motivo.trim().length < 5) return toast('Informe o motivo da recusa.', 'error');
      } else if (!confirm('Aprovar ' + alvo.length + ' solicitação(ões)' + esc_ + '?')) return;
      el('ap-todos').disabled = el('ap-recusar-todos').disabled = true;
      var ok = 0, falhas = [];
      for (var k = 0; k < alvo.length; k++) {
        try {
          var r = await api('ponto_admin_decidir', { id: alvo[k].id, solic: alvo[k].solic, decisao: dec, motivo: motivo });
          if (r.sucesso) ok++; else falhas.push(r.erro);
        } catch (e) { falhas.push(e.message); }
      }
      if (falhas.length) toast(ok + ' ' + (dec === 'aprovar' ? 'aprovada(s)' : 'recusada(s)') + '; ' + falhas.length + ' com erro: ' + falhas[0], 'error');
      else toast(ok + ' solicitação(ões) ' + (dec === 'aprovar' ? 'aprovada(s).' : 'recusada(s).'));
      abrirAba();
    }
    autocompletar(el('ap-filtro'), function () { return nomes; }, function (n, livre) { pendFiltro = n != null ? n : livre; desenhar(); });
    el('ap-limpar').onclick = function () { pendFiltro = ''; el('ap-filtro').value = ''; desenhar(); };
    el('ap-todos').onclick = function () { lote('aprovar'); };
    el('ap-recusar-todos').onclick = function () { lote('recusar'); };
    desenhar();
  }

  /* ------------------------------ registros ------------------------------ */
  function abaRegistros(c) {
    c.innerHTML = '<div class="flex flex-wrap gap-2 items-end mb-3">' +
      '<label class="text-[10px] font-bold text-slate-500 uppercase">Colaborador<span class="ta block mt-1" style="display:block"><input id="pr-nome" class="text-xs block" autocomplete="off" placeholder="Todos"></span></label>' +
      '<label class="text-[10px] font-bold text-slate-500 uppercase">De<input id="pr-ini" type="date" class="mt-1 text-xs block" value="' + periodoFolha()[0] + '"></label>' +
      '<label class="text-[10px] font-bold text-slate-500 uppercase">Até<input id="pr-fim" type="date" class="mt-1 text-xs block" value="' + periodoFolha()[1] + '"></label>' +
      '<button id="pr-buscar" class="px-3 py-1.5 rounded bg-blue-600 text-white text-xs font-bold">Buscar</button>' +
      '<button id="pr-xlsx" type="button" class="px-2.5 py-1.5 rounded border border-emerald-600 text-emerald-700 hover:bg-emerald-50 text-xs font-bold flex items-center gap-1" title="Salvar os registros em exibição em planilha Excel (XLSX)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/><path d="M9 13l5 6M14 13l-5 6"/></svg>XLSX</button>' +
      '<span class="inline-flex items-center gap-2 px-2 py-1 rounded border border-red-600">' +
      '<button id="pr-pdf" type="button" class="text-red-700 hover:bg-red-50 rounded px-1.5 py-0.5 text-xs font-bold flex items-center gap-1" title="Salvar os registros em exibição em PDF"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/><path d="M8 17v-4h1.5a1 1 0 0 1 0 2H8"/></svg>PDF</button>' +
      '<label class="flex items-center gap-1 text-xs font-bold text-slate-600 cursor-pointer" title="Incluir no PDF a foto de cada batida facial"><input id="pr-fotos" type="checkbox" style="width:auto;display:inline-block"> Fotos</label></span></div><div id="pr-lista" class="space-y-2"></div>';
    var buscar = async function () {
      var l = el('pr-lista'); l.innerHTML = '<div class="text-sm text-slate-400">Carregando…</div>';
      try {
        var j = await api('ponto_admin_registros&inicio=' + el('pr-ini').value + '&fim=' + el('pr-fim').value + '&nome=' + encodeURIComponent(el('pr-nome').value.trim()));
        if (!j.sucesso) { l.innerHTML = '<div class="text-sm font-bold text-red-600">' + esc(j.erro) + '</div>'; return; }
        l.innerHTML = j.dias.length ? j.dias.map(cartaoDia).join('') : '<div class="text-sm text-slate-400 py-4 text-center">Sem registros no período.</div>';
        Array.prototype.forEach.call(l.querySelectorAll('[data-foto]'), function (b) { b.onclick = function () { verFoto(+b.getAttribute('data-foto')); }; });
      } catch (e) { l.innerHTML = '<div class="text-sm font-bold text-red-600">' + esc(e.message) + '</div>'; }
    };
    el('pr-buscar').onclick = buscar; /* REG_VAZIO_V1 */ el('pr-lista').innerHTML = '<div class="text-sm text-slate-400 py-8 text-center">Defina os filtros e clique em <b>Buscar</b> para carregar os registros.</div>';
    var urlExp = function (fmt) { return 'api_ponto.php?action=ponto_admin_exportar&inicio=' + el('pr-ini').value + '&fim=' + el('pr-fim').value + '&nome=' + encodeURIComponent(el('pr-nome').value.trim()) + (fmt ? '&formato=' + fmt : ''); };
    el('pr-xlsx').onclick = function () { if (!el('pr-ini').value || !el('pr-fim').value) return toast('Informe o período.', 'error'); toast('Gerando a planilha XLSX…'); location.href = urlExp('xlsx'); };
    el('pr-pdf').onclick = function () { if (!el('pr-ini').value || !el('pr-fim').value) return toast('Informe o período.', 'error'); var cf = el('pr-fotos').checked; toast(cf ? 'Gerando o PDF com fotos… pode levar alguns segundos.' : 'Gerando o PDF…'); location.href = urlExp('pdf') + '&fotos=' + (cf ? '1' : '0'); };
    api('ponto_admin_colaboradores').then(function (cj) {
      if (!cj.sucesso) return;
      var todos = cj.itens.map(function (i) { return i.nome; }).sort();
      autocompletar(el('pr-nome'), function () { return todos; }, function (n) { if (n != null) buscar(); });
    }).catch(function () {});
  }
  function cartaoDia(d) {
    var linhas = d.batidas.map(function (b) {
      var cor = b.status === 'pendente' ? 'bg-amber-100 text-amber-700' : b.status === 'recusada' ? 'bg-red-100 text-red-700' : b.status === 'aprovada' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-600';
      var gps = b.lat != null ? '<a target="_blank" rel="noopener" class="text-blue-600 underline" href="https://www.google.com/maps?q=' + b.lat + ',' + b.lng + '">📍 mapa</a> <span class="text-slate-400">±' + Math.round(b.precisao || 0) + 'm</span>' : '';
      var foto = b.tem_foto ? '<button data-foto="' + b.id + '" class="text-blue-600 underline">📷 foto</button>' : '';
      var just = b.justificativa ? '<div class="text-[11px] text-slate-500 pl-1">“' + esc(b.justificativa) + '”' + (b.motivo_decisao ? ' — gestor: ' + esc(b.motivo_decisao) : '') + '</div>' : '';
      return '<div class="py-1"><div class="flex flex-wrap gap-x-3 gap-y-1 items-center text-xs"><span class="w-44 font-bold text-slate-600">' + ROT[b.tipo] + '</span><span class="font-black tabular-nums">' + hhmm(b.momento) + '</span>' +
        '<span class="px-1.5 py-0.5 rounded text-[10px] font-black uppercase ' + cor + '">' + b.origem + ' · ' + b.status + (b.remocao_status === 'pendente' ? ' · remoção pendente' : '') + '</span>' + foto + gps +
        (b.distancia != null ? '<span class="text-slate-400">similaridade ' + Number(b.distancia).toFixed(2) + '</span>' : '') + '</div>' + just + '</div>';
    }).join('');
    return '<div class="border rounded-lg p-3 ' + (d.alerta ? 'border-red-300 bg-red-50/40' : '') + '"><div class="flex justify-between flex-wrap gap-1 mb-1"><div class="text-sm font-black uppercase">' + esc(d.nome) +
      ' <span class="text-slate-400 font-bold normal-case">· ' + DIAS[d.dow] + ' ' + dataBR(d.data) + '</span></div><div class="text-xs font-bold text-slate-500">' +
      (d.trabalhado_min != null ? 'Trabalhado ' + fmtMin(d.trabalhado_min) + (d.saldo_min ? ' · saldo ' + (d.saldo_min > 0 ? '+' : '−') + fmtMin(d.saldo_min) : '') : '') + '</div></div>' +
      (d.alerta ? '<div class="text-xs font-bold text-red-600 mb-1">⚠ ' + esc(d.alerta) + '</div>' : '') + linhas + '</div>';
  }
  async function verFoto(id) {
    var j = await api('ponto_admin_foto&id=' + id);
    if (!j.sucesso) return toast(j.erro, 'error');
    el('foto-img').src = j.foto; el('foto-modal').classList.remove('hidden');
  }
  el('foto-modal').onclick = function () { this.classList.add('hidden'); };
  doc.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') el('foto-modal').classList.add('hidden'); });

  /* ------------------------------ colaboradores ------------------------------ */
  async function abaColaboradores(c) {
    var j = await api('ponto_admin_colaboradores');
    if (!j.sucesso) throw new Error(j.erro);
    var link = location.origin + '/';
    c.innerHTML = '<div class="mb-3 p-3 rounded bg-blue-50 border border-blue-200 text-xs text-slate-700">Link para os colaboradores baterem o ponto (abrir no celular): <b class="select-all">' + esc(link) + '</b>' +
      '<div class="mt-1 text-slate-500">O login é o mesmo da escala. Quem não tem usuário precisa ser cadastrado na escala (Configurações → Usuários), com o <b>nome completo igual ao do cadastro do colaborador</b>. No primeiro acesso a pessoa cadastra o rosto.</div></div>' +
      '<div class="overflow-x-auto"><table class="w-full text-xs"><thead class="bg-slate-50 text-[10px] uppercase text-slate-500"><tr><th class="text-left px-2 py-2">Colaborador</th><th class="text-left px-2 py-2">Setor</th><th class="text-left px-2 py-2">E-mail</th><th class="px-2 py-2">Login</th><th class="px-2 py-2">Rosto</th><th class="px-2 py-2">Regra</th><th class="px-2 py-2">Situação</th><th></th></tr></thead><tbody class="divide-y">' +
      j.itens.map(function (i) {
        return '<tr><td class="px-2 py-2 font-bold uppercase">' + esc(i.nome) + '</td><td class="px-2 py-2 text-slate-500">' + esc(i.setor || '') + '</td>' +
          '<td class="px-2 py-1"><input type="email" data-email="' + esc(i.nome) + '" value="' + esc(i.email || '') + '" placeholder="e-mail do colaborador" maxlength="200" class="text-xs w-48 border rounded px-2 py-1"></td>' +
          '<td class="px-2 py-2 text-center">' + (i.tem_login ? '<span class="font-mono">' + esc(i.usuario) + '</span>' : '<span class="text-amber-600 font-bold">sem login</span>') + '</td>' +
          '<td class="px-2 py-2 text-center">' + (i.cadastrado ? '✔' : '—') + '</td><td class="px-2 py-2 text-center">' + (i.regra_individual ? '<span class="text-amber-600 font-bold">Individual</span>' : '<span class="text-slate-500">Padrão</span>') + '</td><td class="px-2 py-2 text-center">' + (i.ativo ? 'Ativo' : '<span class="text-red-600 font-bold">Desativado</span>') + '</td>' +
          '<td class="px-2 py-2 text-right whitespace-nowrap"><button data-a="regra" data-n="' + esc(i.nome) + '" class="px-2 py-1 rounded border font-bold mr-1">Regra</button>' + (i.cadastrado ? '<button data-a="rosto" data-n="' + esc(i.nome) + '" class="px-2 py-1 rounded bg-slate-600 text-white font-bold mr-1">Resetar rosto</button>' : '') +
          '<button data-a="ativo" data-v="' + (i.ativo ? 0 : 1) + '" data-n="' + esc(i.nome) + '" class="px-2 py-1 rounded border font-bold">' + (i.ativo ? 'Desativar' : 'Ativar') + '</button></td></tr>';
      }).join('') + '</tbody></table></div>';
    (function () { /* COL_FIXO_V1 — o aviso e o cabeçalho da tabela ficam fixos; só as linhas dos colaboradores rolam */
      var w = c.querySelector('.overflow-x-auto'); if (!w) return;
      w.style.overflowY = 'auto';
      Array.prototype.forEach.call(w.querySelectorAll('thead th'), function (th) { th.style.position = 'sticky'; th.style.top = '0'; th.style.zIndex = '2'; th.style.background = '#161d35'; });
      function aj() {
        if (!document.body.contains(w)) { window.removeEventListener('resize', aj); return; }
        var topo = w.getBoundingClientRect().top + (window.pageYOffset || 0);
        w.style.maxHeight = Math.max(240, window.innerHeight - topo - 24) + 'px';
      }
      aj(); setTimeout(aj, 300); window.addEventListener('resize', aj);
    })();
    Array.prototype.forEach.call(c.querySelectorAll('[data-email]'), function (inp) {
      var ant = inp.value;
      inp.onchange = async function () {
        var r = await api('ponto_admin_email', { nome: inp.getAttribute('data-email'), email: inp.value.trim() });
        if (!r.sucesso) { toast(r.erro, 'error'); inp.value = ant; return; }
        ant = inp.value.trim(); toast(ant ? 'E-mail salvo.' : 'E-mail removido.');
      };
    });
    Array.prototype.forEach.call(c.querySelectorAll('[data-a]'), function (b) {
      b.onclick = async function () {
        var a = b.getAttribute('data-a'), n = b.getAttribute('data-n'), r;
        if (a === 'regra') return abaRegra(c, n);
        if (a === 'rosto') { if (!confirm('Resetar o cadastro facial de ' + n + '? Ela(e) precisará cadastrar o rosto de novo.')) return; r = await api('ponto_admin_resetar_rosto', { nome: n }); }
        else {
          if (b.getAttribute('data-v') !== '1' && !confirm('Desativar o ponto de ' + n + '?\n\nPela LGPD, o cadastro facial (foto e biometria) será apagado. As batidas já registradas continuam guardadas. Se for reativado, precisará cadastrar o rosto de novo.')) return;
          r = await api('ponto_admin_ativar', { nome: n, ativo: b.getAttribute('data-v') === '1' });
        }
        if (!r.sucesso) return toast(r.erro, 'error');
        toast('Atualizado.'); abrirAba();
      };
    });
  }

  /* ------------------------------ regra de horário ------------------------------ */
  async function fiscalRegra(c) { // PORTARIA_671_V1: dados do empregador e geração do AFD/AEJ
    var o = await api('ponto_admin_fiscal'); if (!o.sucesso) return;
    var f = o.cfg, d = doc.createElement('div'); d.className = 'mt-6 pt-4 border-t';
    var pf = (typeof periodoFolha === 'function') ? periodoFolha() : ['', ''];
    var inp = function (id, rot, v, ph, w) { return '<label class="text-[10px] font-bold text-slate-500 uppercase">' + rot + '<input id="' + id + '" class="mt-1 text-xs block" style="width:' + (w || '13rem') + '" value="' + esc(v || '') + '" placeholder="' + esc(ph || '') + '"></label>'; };
    d.innerHTML = '<h3 class="text-sm font-bold text-slate-700 mb-1">Arquivos fiscais — Portaria 671 (AFD e AEJ)</h3>' +
      '<p class="text-[11px] text-slate-400 mb-2">Dados do empregador e do programa de ponto (REP-P) usados nos arquivos. O CPF de cada colaborador é cadastrado na Escala (Editar Colaborador).</p>' +
      '<div class="flex flex-wrap gap-3 items-end">' + inp('fi-cnpj', 'CNPJ do empregador', f.cnpj, '00.000.000/0000-00') + inp('fi-razao', 'Razão social', f.razao, '', '20rem') +
      inp('fi-local', 'Local de prestação de serviço', f.local, 'Ex.: Salvador/BA', '20rem') + inp('fi-inpi', 'Registro do programa no INPI', f.inpi, '17 dígitos, se houver') +
      inp('fi-cpfresp', 'CPF do responsável', f.cpf_resp, '000.000.000-00') + inp('fi-devcnpj', 'CNPJ/CPF do desenvolvedor', f.dev_cnpj) +
      inp('fi-devrazao', 'Nome do desenvolvedor', f.dev_razao, '', '20rem') + inp('fi-devemail', 'E-mail do desenvolvedor', f.dev_email) +
      '<button id="fi-salvar" type="button" class="px-4 py-2 rounded bg-blue-600 text-white text-xs font-bold">Salvar dados</button></div>' +
      (o.sem_cpf.length ? '<div class="mt-2 p-2 rounded text-[11px] font-bold" style="background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.5);color:#fcd34d">⚠ Sem CPF válido (ficam fora dos arquivos até cadastrar na Escala): ' + o.sem_cpf.map(esc).join(', ') + '</div>' : '') +
      '<div class="mt-3 flex flex-wrap gap-3 items-end"><label class="text-[10px] font-bold text-slate-500 uppercase">De<input id="fi-ini" type="date" class="mt-1 text-xs block" value="' + pf[0] + '"></label>' +
      '<label class="text-[10px] font-bold text-slate-500 uppercase">Até<input id="fi-fim" type="date" class="mt-1 text-xs block" value="' + pf[1] + '"></label>' +
      '<button id="fi-afd" type="button" class="px-3 py-2 rounded border border-slate-400 text-slate-700 text-xs font-bold">⬇ Gerar AFD</button>' +
      '<button id="fi-aej" type="button" class="px-3 py-2 rounded border border-slate-400 text-slate-700 text-xs font-bold">⬇ Gerar AEJ</button></div>' +
      '<p class="text-[11px] text-slate-400 mt-1">AFD: marcações originais (facial) com NSR e hash encadeado. AEJ: jornada tratada (inclusões, desconsiderações, almoço automático), horários contratuais, faltas e banco de horas. A assinatura digital (.p7s com certificado ICP-Brasil) é feita à parte.</p>';
    c.appendChild(d);
    el('fi-salvar').onclick = async function () {
      var b = this; b.disabled = true;
      try {
        var r = await api('ponto_admin_fiscal', { cnpj: el('fi-cnpj').value, razao: el('fi-razao').value, local: el('fi-local').value, inpi: el('fi-inpi').value,
          cpf_resp: el('fi-cpfresp').value, dev_cnpj: el('fi-devcnpj').value, dev_razao: el('fi-devrazao').value, dev_email: el('fi-devemail').value });
        if (!r.sucesso) return toast(r.erro, 'error');
        toast('Dados fiscais salvos.');
      } catch (e) { toast(e.message, 'error'); } finally { b.disabled = false; }
    };
    var baixar = async function (acao, b) {
      var ini = el('fi-ini').value, fim = el('fi-fim').value; if (!ini || !fim) return toast('Informe o período.', 'error');
      var rot = b.textContent; b.disabled = true; b.textContent = 'Gerando…';
      try {
        var r = await fetch('api_ponto.php?action=' + acao + '&inicio=' + ini + '&fim=' + fim, { credentials: 'same-origin' });
        if (!/text\/plain/.test(r.headers.get('Content-Type') || '')) { var j = {}; try { j = await r.json(); } catch (e) {} return toast(j.erro || 'Não foi possível gerar o arquivo.', 'error'); }
        var nomeArq = ((r.headers.get('Content-Disposition') || '').match(/filename="([^"]+)"/) || [])[1] || (acao + '.txt');
        var sem = r.headers.get('X-Sem-CPF'), blob = await r.blob(), u = URL.createObjectURL(blob), a = doc.createElement('a');
        a.href = u; a.download = nomeArq; doc.body.appendChild(a); a.click(); a.remove(); setTimeout(function () { URL.revokeObjectURL(u); }, 4000);
        toast(sem ? 'Arquivo gerado. Ficaram de fora (sem CPF): ' + decodeURIComponent(sem) : 'Arquivo gerado.', sem ? 'error' : 'success');
      } catch (e) { toast(e.message, 'error'); } finally { b.disabled = false; b.textContent = rot; }
    };
    el('fi-afd').onclick = function () { baixar('ponto_admin_afd', this); };
    el('fi-aej').onclick = function () { baixar('ponto_admin_aej', this); };
  }
  async function opcoesRegra(c) {
    var o = await api('ponto_admin_opcoes');
    if (!o.sucesso) return;
    var d = doc.createElement('div'); d.className = 'mt-6 pt-4 border-t';
    var lin = function (id, chk, tit, ajuda) {
      return '<label class="flex items-start gap-3 py-2 cursor-pointer"><input type="checkbox" id="' + id + '" class="mt-1" ' + (chk ? 'checked' : '') + '><span><span class="block text-sm font-bold text-slate-700">' + tit + '</span><span class="block text-[11px] text-slate-500">' + ajuda + '</span></span></label>';
    };
    d.innerHTML = '<h3 class="text-sm font-bold text-slate-700 mb-1">Permissões e avisos</h3>' +
      lin('op-gestor', o.gestor_edita, 'O gestor pode incluir e remover batidas dos subordinados', 'Desabilitado: na Apuração de Batida o gestor só consulta a folha e aprova ou recusa os pedidos dos colaboradores; não lança nem remove batidas por eles.') +
      lin('op-email', o.email_auto, 'Enviar o relatório da folha por e-mail ao fim da folha', 'No dia 15 (após as 07:00) cada colaborador com e-mail cadastrado recebe o espelho da folha: parabéns se estiver tudo certo, ou a lista das datas a regularizar.') +
      (o.smtp ? '' : '<div class="mt-1 p-2 rounded bg-amber-50 border border-amber-200 text-[11px] font-bold text-amber-700">O servidor de e-mail (SMTP) ainda não foi configurado. Configure em Escala → Configurações → E-mail (SMTP).</div>');
    c.appendChild(d);
    var salvar = async function (campo, caixa) {
      var b = {}; b[campo] = caixa.checked;
      var r = await api('ponto_admin_opcoes', b);
      if (!r.sucesso) { caixa.checked = !caixa.checked; return toast(r.erro, 'error'); }
      toast('Opção salva.');
    };
    el('op-gestor').onchange = function () { salvar('gestor_edita', this); };
    el('op-email').onchange = function () { salvar('email_auto', this); };
  }
  var FLEX_OPCOES = [0, 15, 30, 45, 60, 75, 90, 105, 120];
  function rotFlex(m) { return m === 0 ? 'Sem flexibilidade' : '± ' + (m < 60 ? m + ' min' : m === 60 ? '1 hora' : m === 120 ? '2 horas' : Math.floor(m / 60) + 'h' + String(m % 60).padStart(2, '0')); }
  /* nome vazio = regra padrão; com nome = regra individual do colaborador */
  async function abaRegra(c, nome) {
    var q = nome ? '&nome=' + encodeURIComponent(nome) : '';
    var j = await api('ponto_admin_regra' + q);
    if (!j.sucesso) throw new Error(j.erro);
    var r = j.regra, dias = r.dias, flex = +r.flex_min || 0;
    var cab = nome ? '<div class="flex flex-wrap items-center gap-3 mb-3"><button id="rg-voltar" type="button" class="px-3 py-1.5 rounded border text-xs font-bold">← Colaboradores</button>' +
      '<div class="text-sm font-bold uppercase">' + esc(nome) + '</div><span class="px-1.5 py-0.5 rounded text-[10px] font-black uppercase ' + (j.individual ? 'bg-amber-100 text-amber-700' : 'bg-slate-100 text-slate-600') + '">' + (j.individual ? 'Regra individual' : 'Usando a regra padrão') + '</span></div>' : '';
    c.innerHTML = cab + '<p class="text-xs text-slate-500 mb-3">' + (nome ? 'Ajuste a jornada, a tolerância e a flexibilidade só para este colaborador. Ao salvar, ele deixa de seguir a regra padrão.' : 'Regra padrão de todos os colaboradores que não têm regra individual.') + ' Ela gera os alertas de batida faltando e as sugestões de horário nas batidas manuais. Dias desmarcados não têm expediente.</p>' +
      '<div class="overflow-x-auto"><table class="text-xs"><thead class="bg-slate-50 text-[10px] uppercase text-slate-500"><tr><th class="px-2 py-2 text-left">Dia</th><th class="px-2 py-2">Trabalha</th><th class="px-2 py-2">Início</th><th class="px-2 py-2">Saída almoço</th><th class="px-2 py-2">Retorno almoço</th><th class="px-2 py-2">Fim</th></tr></thead><tbody class="divide-y">' +
      ORDEM_DIAS.map(function (i) {
        var d = dias[i] || dias[String(i)] || null, v = d || { ini: '08:00', alm_ini: '12:00', alm_fim: '13:00', fim: '18:00' };
        return '<tr data-dia="' + i + '"><td class="px-2 py-2 font-bold">' + DIAS[i] + '</td><td class="px-2 py-2 text-center"><input type="checkbox" class="rg-on" ' + (d ? 'checked' : '') + '></td>' +
          ['ini', 'alm_ini', 'alm_fim', 'fim'].map(function (k) { return '<td class="px-2 py-1"><input type="time" class="rg-' + k + '" value="' + v[k] + '"></td>'; }).join('') + '</tr>';
      }).join('') + '</tbody></table></div>' +
      '<div class="mt-3 flex flex-wrap items-center gap-3 text-xs"><label class="flex items-center gap-2 cursor-pointer"><input id="rg-banco" type="checkbox" style="width:16px;height:16px;min-width:16px;display:inline-block"' + (r.banco_horas ? ' checked' : '') + '><b>Banco de horas</b></label>' +
      '<label class="flex items-center gap-2">a partir de <input id="rg-banco-ini" type="date" class="text-xs" style="width:auto;display:inline-block" value="' + (r.banco_inicio || '') + '"></label></div>' +
      '<p class="text-[11px] text-slate-400 mt-1">Com o banco ligado, a hora extra aprovada vai para o banco do colaborador (deixa de ir para a Escala mensal) e a hora a pagar é descontada. As horas valem até o fim da 3ª folha seguinte; depois expiram. Com 09:00 ou mais o colaborador pode pedir folga, que o gestor aprova em Aprovações.</p>' +
      '<label class="mt-3 flex items-start gap-2 text-xs cursor-pointer"><input id="rg-almauto" type="checkbox" style="width:16px;height:16px;min-width:16px;margin-top:1px;display:inline-block"' + (r.almoco_auto ? ' checked' : '') + '><span><b>Almoço automático</b> — quando o colaborador registrar só a entrada e a saída em dia de expediente, o sistema registra o horário do almoço automaticamente, sem aprovação do gestor. O almoço segue o horário da regra, acompanhando a entrada.</span></label>' +
      '<div class="mt-3 flex flex-wrap items-end gap-3"><label class="text-[10px] font-bold text-slate-500 uppercase">Tolerância (min)<input id="rg-tol" type="number" min="0" max="60" value="' + (r.tolerancia || 0) + '" class="mt-1 text-xs w-24 block"></label>' +
      '<label class="text-[10px] font-bold text-slate-500 uppercase">Flexibilidade de horário<select id="rg-flex" class="mt-1 text-xs block">' +
      FLEX_OPCOES.map(function (m) { return '<option value="' + m + '"' + (m === flex ? ' selected' : '') + '>' + rotFlex(m) + '</option>'; }).join('') + '</select></label>' +
      '<button id="rg-salvar" class="px-4 py-2 rounded bg-blue-600 text-white text-xs font-bold">' + (nome ? 'Salvar regra individual' : 'Salvar regra') + '</button>' +
      (nome && j.individual ? '<button id="rg-padrao" type="button" class="px-4 py-2 rounded border text-xs font-bold">Voltar para a regra padrão</button>' : '') + '</div>' +
      '<p class="mt-2 text-[11px] text-slate-400">A tolerância define quando o sistema passa a alertar uma batida do dia como atrasada. A flexibilidade permite que o colaborador comece a jornada até o limite escolhido (máximo de 2 horas) antes ou depois do horário padrão: o fim do expediente e o almoço acompanham o horário real de entrada, mantendo a mesma carga horária.</p>';
    if (nome) el('rg-voltar').onclick = function () { abrirAba(); };
    if (!nome) { opcoesRegra(c); fiscalRegra(c); }
    el('rg-salvar').onclick = async function () {
      var out = { tolerancia: +el('rg-tol').value, flex_min: +el('rg-flex').value, almoco_auto: !!(el('rg-almauto') && el('rg-almauto').checked), banco_horas: !!(el('rg-banco') && el('rg-banco').checked), banco_inicio: el('rg-banco-ini') ? el('rg-banco-ini').value : '', dias: {} };
      Array.prototype.forEach.call(c.querySelectorAll('tr[data-dia]'), function (tr) {
        var i = tr.getAttribute('data-dia'), q2 = function (k) { return tr.querySelector('.rg-' + k).value; };
        out.dias[i] = tr.querySelector('.rg-on').checked ? { ini: q2('ini'), alm_ini: q2('alm_ini'), alm_fim: q2('alm_fim'), fim: q2('fim') } : null;
      });
      var s = await api('ponto_admin_regra' + q, out);
      if (!s.sucesso) return toast(s.erro, 'error');
      toast(nome ? 'Regra individual de ' + nome + ' salva.' : 'Regra de horário salva.');
      if (nome) abaRegra(c, nome);
    };
    if (el('rg-padrao')) el('rg-padrao').onclick = async function () {
      if (!confirm('Voltar ' + nome + ' para a regra padrão? A regra individual será descartada.')) return;
      var s = await api('ponto_admin_regra' + q, { padrao: true });
      if (!s.sucesso) return toast(s.erro, 'error');
      toast(nome + ' voltou para a regra padrão.'); abaRegra(c, nome);
    };
  }

  /* ------------------------------ início ------------------------------ */
  el('btn-sair').onclick = async function () { try { await api('ponto_logout', {}); } catch (e) {} location.href = 'index.html'; };
  (async function () {
    try {
      var me = await api('ponto_me');
      if (!me.sucesso) { location.href = 'index.html'; return; }
      if (window.ptNav) ptNav.aplicar(me);
      if (!me.gestor) { el('erro-geral').className = 'rounded-lg bg-red-100 text-red-700 font-bold text-sm p-3 mb-3'; el('erro-geral').textContent = 'Acesso restrito a gestores (perfil admin/mestre).'; return; }
      el('painel').classList.remove('hidden');
      await abrirAba();
    } catch (e) { toast(e.message, 'error'); }
  })();
})();
