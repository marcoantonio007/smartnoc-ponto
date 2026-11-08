/* dashboard.js — painel do gestor: situação de hoje, batidas pendentes, aprovações e horas extras da folha */
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var est = { ref: null, atual: null, rot: {}, pend: [], recAlvo: [], ocupado: false };
  var MES = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
  var DIA = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];
  var EVT = { BATIDA_FACIAL: 'Batida registrada (facial)', INCLUSAO_SOLICITADA: 'Inclusão solicitada', INCLUSAO_APROVADA: 'Inclusão aprovada', INCLUSAO_RECUSADA: 'Inclusão recusada',
    INCLUSAO_CANCELADA: 'Inclusão cancelada', ATESTADO_SOLICITADO: 'Abono solicitado', ATESTADO_APROVADO: 'Abono aprovado', ATESTADO_RECUSADO: 'Abono recusado', REMOCAO_SOLICITADA: 'Remoção solicitada', REMOCAO_APROVADA: 'Remoção aprovada', REMOCAO_RECUSADA: 'Remoção recusada',
    REMOCAO_CANCELADA: 'Remoção cancelada', VIDPOL_INCLUIDO: 'VIDPOL incluído', VIDPOL_REMOVIDO: 'VIDPOL removido' };
  var COR = { INCLUSAO_SOLICITADA: 'pend', REMOCAO_SOLICITADA: 'pend', INCLUSAO_APROVADA: 'ok', REMOCAO_APROVADA: 'ok', BATIDA_FACIAL: 'ok', INCLUSAO_RECUSADA: 'bad', REMOCAO_RECUSADA: 'bad', ATESTADO_SOLICITADO: 'pend', ATESTADO_APROVADO: 'ok', ATESTADO_RECUSADO: 'bad' };

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function vpLinks(i) { var l = i.vidpols || []; return l.length ? l.map(function (v) { return '<a href="https://videopolicia.atlassian.net/browse/VIDPOL-' + encodeURIComponent(v) + '" target="_blank" rel="noopener noreferrer" title="Abrir o chamado no Jira" style="color:#9fc0f5;text-decoration:underline;font-weight:700">VIDPOL-' + esc(v) + '</a>'; }).join(', ') : esc(i.justificativa || ''); }
  function p2(n) { return (n < 10 ? '0' : '') + n; }
  function hm(min) { min = Math.round(min || 0); var s = min < 0 ? '-' : ''; min = Math.abs(min); return s + p2(Math.floor(min / 60)) + ':' + p2(min % 60); }
  function ddmm(iso) { var d = iso.split('-'); return d[2] + '/' + d[1]; }
  function dmy(iso) { var d = iso.split('-'); return d[2] + '/' + d[1] + '/' + d[0]; }
  function dhm(ts) { var p = (ts || '').split(' '); return p.length === 2 ? ddmm(p[0]) + ' ' + p[1].slice(0, 5) : ts; }
  function ha(min) {
    min = Math.max(0, Math.round(min));
    if (min < 1) return 'agora há pouco';
    if (min < 60) return 'há ' + min + ' min';
    if (min < 1440) return 'há ' + Math.floor(min / 60) + ' h' + (min % 60 ? ' ' + (min % 60) + ' min' : '');
    return 'há ' + Math.floor(min / 1440) + ' d';
  }
  function aviso(msg, tipo) {
    var a = $('aviso'); if (!msg) { a.classList.add('hidden'); return; }
    a.className = 'aviso ' + (tipo === 'erro' ? 'erro' : tipo === 'ok' ? 'ok' : 'info'); a.textContent = msg; a.classList.remove('hidden');
    if (tipo !== 'erro') setTimeout(function () { if (a.textContent === msg) a.classList.add('hidden'); }, 6000);
  }
  async function api(action, body) {
    var r = await fetch('api_ponto.php?action=' + action, body === undefined ? { credentials: 'same-origin' } :
      { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    var t = await r.text(), j;
    try { j = JSON.parse(t); } catch (e) { throw new Error('Resposta inválida do servidor.'); }
    if (r.status === 401) { location.href = 'index.html'; throw new Error('Sessão expirada.'); }
    return j;
  }
  function tile(rot, val, sub, cls) { return '<div class="kt ' + (cls || '') + '"><small>' + rot + '</small><b>' + val + '</b>' + (sub ? '<em>' + sub + '</em>' : '') + '</div>'; }
  function lnk(nome) { return '<button type="button" class="nm" data-ir="' + esc(nome) + '">' + esc(nome) + '</button>'; }
  function plural(n, a, b) { return n + ' ' + (n === 1 ? a : b); }

  /* ---------------- hoje, aprovações e movimentações ---------------- */
  function pintaHoje(d) {
    var k = d.kpi, dt = new Date(d.hoje + 'T12:00:00');
    $('d-hoje').textContent = DIA[dt.getDay()] + ', ' + dmy(d.hoje);
    $('d-atual').textContent = 'Atualizado às ' + d.agora.slice(11, 19) + ' · atualiza sozinho a cada minuto';
    $('k-hoje').innerHTML =
      tile('Esperados hoje', k.esperados, k.monitorados + ' monitorados · ' + k.batidas_hoje + ' batidas hoje') +
      tile('Trabalhando agora', k.trabalhando, 'com a última batida de entrada', k.trabalhando ? 'bom' : '') +
      tile('Jornada completa', k.completos, 'já fecharam as 4 batidas', k.completos ? 'bom' : '') +
      tile('Batida atrasada', k.atrasados, k.atrasados ? 'fora da tolerância da regra' : 'ninguém em atraso', k.atrasados ? 'ruim' : '') +
      tile('Sem nenhuma batida', k.sem_batida, k.aguardando ? k.aguardando + ' ainda no prazo' : 'todos já bateram', k.sem_batida ? 'atencao' : '') +
      tile('Fora do expediente', k.fora_expediente, k.extra_hoje ? plural(k.extra_hoje, 'com hora extra', 'com hora extra') : 'folga, feriado ou sem regra');

    est.kpiHoje = k; desenhaHoje();
    var l = d.pendentes_batida; $('n-pend').textContent = l.length; $('n-pend').classList.toggle('zero', !l.length);
    $('l-pend').innerHTML = l.length ? l.map(function (c) {
      return '<div class="li"><div class="info">' + lnk(c.nome) + (c.setor ? ' <small>' + esc(c.setor) + '</small>' : '') +
        '<div class="sub">' + esc(c.alerta) + '</div>' +
        '<div class="chips">' + (c.feitas.length ? c.feitas.map(function (b) { return '<span class="chip ok">' + esc(est.rot[b.tipo] || b.tipo) + ' ' + esc(b.hora) + '</span>'; }).join('') : '<span class="chip bad">Nenhuma batida</span>') + '</div></div>' +
        '<button type="button" class="mini" data-ir="' + esc(c.nome) + '">Apurar</button></div>';
    }).join('') : '<div class="vazio">Nenhum colaborador com batida atrasada agora.</div>';

    var a = d.aprovacoes;
    $('k-apr').innerHTML = a.total ? 'Inclusões: <b>' + a.inclusao + '</b> · Remoções: <b>' + a.remocao + '</b>' + (a.atestado ? ' · Abonos: <b>' + a.atestado + '</b>' : '') + ' · ' + plural(a.colaboradores, 'colaborador', 'colaboradores') + ' · mais antiga ' + ha(a.mais_antiga_min) : '';

    var m = d.movimentos;
    $('l-mov').innerHTML = m.length ? m.map(function (e) {
      var dh = e.criado_em.split(' '), tp = e.tipo ? ' · ' + esc(est.rot[e.tipo] || e.tipo) + ' ' + esc(e.hora) + ' de ' + esc(e.dia) : '';
      return '<div class="mov"><time>' + ddmm(dh[0]) + ' ' + dh[1].slice(0, 5) + '</time><div><b>' + esc(e.nome) + '</b> <span class="chip ' + (COR[e.evento] || '') + '">' + esc(EVT[e.evento] || e.evento) + '</span>' + tp +
        (e.usuario && e.usuario !== e.nome ? ' <span class="sub">por ' + esc(e.usuario) + '</span>' : '') + '</div></div>';
    }).join('') : '<div class="vazio">Sem movimentações ainda.</div>';

    var c = d.cadastro;
    $('l-cad').innerHTML =
      '<div class="li"><span>Colaboradores na escala</span><b>' + c.total + '</b></div>' +
      '<div class="li"><span>Com ponto ativo e rosto cadastrado</span><b>' + k.monitorados + '</b></div>' +
      '<div class="li"><div class="info"><span>Sem rosto cadastrado</span>' + (c.nomes_sem_rosto.length ? '<div class="chips">' + c.nomes_sem_rosto.map(function (n) { return '<span class="chip">' + esc(n) + '</span>'; }).join('') + (c.sem_rosto > c.nomes_sem_rosto.length ? '<span class="sub">+' + (c.sem_rosto - c.nomes_sem_rosto.length) + '</span>' : '') + '</div>' : '') + '</div><b>' + c.sem_rosto + '</b></div>' +
      '<div class="li"><span>Com o ponto desativado</span><b>' + c.desativados + '</b></div>';
  }

  function pintaAprov(itens) {
    est.pend = itens;
    var a = $('n-apr'); a.textContent = itens.length; a.classList.toggle('zero', !itens.length);
    var pode = itens.filter(function (i) { return !i.propria; });
    $('acoes-apr').innerHTML = pode.length > 1 ? '<button type="button" class="mini ok" data-a="aprovar-todas">Aprovar todas</button><button type="button" class="mini ruim" data-a="recusar-todas">Recusar todas</button>' : '';
    $('l-apr').innerHTML = itens.length ? itens.map(function (i) {
      var rem = i.solic === 'remocao', ate = i.solic === 'atestado' || i.solic === 'extra' || i.solic === 'folga_banco';
      return '<div class="li"><div class="info"><span class="chip ' + (ate ? 'pend' : rem ? 'bad' : 'ok') + '">' + (i.solic === 'folga_banco' ? 'Folga (banco)' : i.solic === 'extra' ? 'Hora extra' : ate ? 'Abono' : rem ? 'Remoção' : 'Inclusão') + '</span> ' + lnk(i.nome) +
        '<div><b>' + ddmm(i.data) + ' · ' + esc(i.rotulo) + ' · ' + esc(i.hora) + '</b> <span class="sub">pedido em ' + esc(dhm(i.criado_em)) + '</span></div>' +
        '<div class="sub">' + (i.solic === 'extra' ? vpLinks(i) : esc(i.justificativa || '')) + (ate && i.tem_anexo ? ' <a class="nm" style="text-transform:none" href="api_ponto.php?action=ponto_atestado_arquivo&id=' + i.id + '" target="_blank" rel="noopener">📎 Ver anexo</a>' : '') + '</div></div>' +
        (i.propria ? '<span class="sub">Pedido seu — outro gestor decide</span>' :
          '<div class="acoes"><button type="button" class="mini ok" data-a="aprovar" data-id="' + i.id + '" data-solic="' + i.solic + '">Aprovar</button>' +
          '<button type="button" class="mini ruim" data-a="recusar" data-id="' + i.id + '" data-solic="' + i.solic + '">Recusar</button></div>') + '</div>';
    }).join('') : '<div class="vazio">Nenhuma solicitação aguardando aprovação.</div>';
  }

  async function carregarLeve() {
    var r = await Promise.all([api('ponto_admin_dashboard'), api('ponto_admin_pendencias')]);
    if (!r[0].sucesso) throw new Error(r[0].erro || 'Não foi possível carregar o painel.');
    est.rot = r[0].rotulos; pintaHoje(r[0]); pintaAprov(r[1].sucesso ? r[1].itens : []);
  }

  /* ---------------- folha ---------------- */
  function ranking(el, lista, valor, rotValor, amb, sub) {
    if (!lista.length) { el.innerHTML = '<div class="vazio">Nada a mostrar neste período.</div>'; return; }
    var max = Math.max.apply(null, lista.map(valor)) || 1;
    el.innerHTML = lista.map(function (c) {
      return '<div class="rk"><div>' + lnk(c.nome) + (sub(c) ? '<div class="sub">' + sub(c) + '</div>' : '') + '</div><div class="barra' + (amb ? ' amb' : '') + '"><i style="width:' + Math.max(4, Math.round(valor(c) / max * 100)) + '%"></i></div><span class="v">' + rotValor(c) + '</span></div>';
    }).join('');
  }
  function pintaFolha(f) {
    est.ref = f.ref; est.atual = f.atual;
    var y = +f.ref.slice(0, 4), m = +f.ref.slice(5, 7);
    $('f-tit').textContent = MES[m - 1] + ' de ' + y; $('f-per').textContent = ddmm(f.inicio) + ' a ' + ddmm(f.fim) + (f.ref === f.atual ? ' · folha atual' : '');
    $('f-prox').disabled = f.ref >= f.atual;
    var t = f.totais, k = $('k-folha'); k.classList.remove('sk');
    k.innerHTML =
      tile('Horas extras', hm(t.extra_min), plural(t.colab_extra, 'colaborador', 'colaboradores'), t.extra_min ? 'bom' : '') +
      tile('Horas a pagar', hm(t.pagar_min), 'saldo negativo no período', t.pagar_min ? 'atencao' : '') +
      tile('Dias a ajustar', t.dias_ajustar, plural(t.colab_ajustar, 'colaborador', 'colaboradores'), t.dias_ajustar ? 'ruim' : 'bom') +
      tile('Extras sem VIDPOL', t.extras_sem_vidpol, 'dias com extra sem VIDPOL', t.extras_sem_vidpol ? 'atencao' : '') +
      tile('VIDPOL informados', t.vidpol, 'na hora extra') +
      tile('Horas trabalhadas', hm(t.trabalhado_min), t.colaboradores + ' colaboradores');
    ranking($('r-extra'), f.ranking_extra, function (c) { return c.extra_min; }, function (c) { return hm(c.extra_min); }, false,
      function (c) { return c.vidpol + ' VIDPOL' + (c.extras_sem_vidpol ? ' · <span style="color:#f1cf86">' + c.extras_sem_vidpol + ' dia(s) sem VIDPOL</span>' : ''); });
    ranking($('r-ajustar'), f.ranking_ajustar, function (c) { return c.dias_ajustar; }, function (c) { return plural(c.dias_ajustar, 'dia', 'dias'); }, true,
      function (c) { return c.solicitacoes ? plural(c.solicitacoes, 'solicitação aguardando', 'solicitações aguardando') : ''; });
  }
  /* ---------------- gráficos ---------------- */
  var C1 = '#3987e5', C2 = '#d95926', C3 = '#199e70', CN = '#6f7ba0';
  var DS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
  var cartoes = {};
  function cartao(id, tit, sub, leg, desenhar) {
    var sec = $(id); sec.textContent = '';
    var topo = document.createElement('div'); topo.className = 'topo-pn';
    var h = document.createElement('h3'); h.textContent = tit; topo.appendChild(h);
    var tg = document.createElement('div'); tg.className = 'tg'; tg.setAttribute('role', 'group'); tg.setAttribute('aria-label', 'Modo de exibição de ' + tit);
    var bg = document.createElement('button'), bt = document.createElement('button'); bg.type = bt.type = 'button'; bg.textContent = 'Gráfico'; bt.textContent = 'Tabela';
    tg.appendChild(bg); tg.appendChild(bt); topo.appendChild(tg); sec.appendChild(topo);
    var s = document.createElement('div'); s.className = 'sub-pn'; s.textContent = sub; sec.appendChild(s);
    var lg = document.createElement('div'); lg.className = 'leg';
    (leg || []).forEach(function (l) { var sp = document.createElement('span'), i = document.createElement('i'); i.style.background = l[1]; sp.appendChild(i); sp.appendChild(document.createTextNode(l[0])); lg.appendChild(sp); });
    if (leg && leg.length > 1) sec.appendChild(lg);
    var corpo = document.createElement('div'); corpo.className = 'gc'; sec.appendChild(corpo);
    var c = { modo: cartoes[id] ? cartoes[id].modo : 'g', corpo: corpo, lg: lg, desenhar: desenhar };
    function pinta() { bg.setAttribute('aria-pressed', c.modo === 'g'); bt.setAttribute('aria-pressed', c.modo === 't'); lg.classList.toggle('hid', c.modo === 't'); Graf.esconder(); c.desenhar(corpo, c.modo); }
    bg.onclick = function () { c.modo = 'g'; pinta(); }; bt.onclick = function () { c.modo = 't'; pinta(); };
    c.pinta = pinta; cartoes[id] = c; pinta(); return c;
  }
  function pct(a, b) { return b > 0 ? Math.round(a / b * 100) : null; }
  function desenhaHoje() {
    var k = est.kpiHoje, h = $('g-hoje'); if (!k || !h) return;
    Graf.segmentada(h, [
      { nome: 'Jornada completa', v: k.completos, cor: C1 }, { nome: 'Em andamento', v: k.em_andamento, cor: C3 },
      { nome: 'Aguardando (no prazo)', v: k.aguardando, cor: CN }, { nome: 'Atrasados', v: k.atrasados, cor: C2 }], { unidade: 'colaboradores', vazio: 'Ninguém tem expediente hoje.' });
  }
  function pintaGraficos(f) {
    var t = f.totais, hoje = f.hoje, ser = f.serie || [];
    var asp = pct(t.completos, t.esperados), pont = pct(t.pontuais, t.entradas), man = pct(t.manual, t.facial + t.manual);
    var dec = f.decisoes || {}, tx = pct(dec.aprovadas, (dec.aprovadas || 0) + (dec.recusadas || 0));
    var k = $('k-ind'); k.classList.remove('sk');
    k.innerHTML =
      tile('Aderência', asp == null ? '—' : asp + '%', t.completos + ' de ' + t.esperados + ' jornadas completas', asp == null ? '' : asp >= 90 ? 'bom' : asp >= 70 ? 'atencao' : 'ruim') +
      tile('Pontualidade', pont == null ? '—' : pont + '%', t.pontuais + ' de ' + t.entradas + ' entradas no horário', pont == null ? '' : pont >= 90 ? 'bom' : pont >= 75 ? 'atencao' : 'ruim') +
      tile('Atraso médio', t.atrasados ? Math.round(t.atraso_total / t.atrasados) + ' min' : '0 min', plural(t.atrasados, 'entrada atrasada', 'entradas atrasadas')) +
      tile('Batidas manuais', man == null ? '—' : man + '%', t.manual + ' manuais · ' + t.facial + ' faciais', man != null && man > 30 ? 'atencao' : '') +
      tile('Taxa de aprovação', tx == null ? '—' : tx + '%', 'últimos 30 dias · ' + (dec.aprovadas || 0) + ' ok / ' + (dec.recusadas || 0) + ' recusadas') +
      tile('Tempo de decisão', dec.tempo_medio_min == null ? '—' : (dec.tempo_medio_min >= 120 ? Math.round(dec.tempo_medio_min / 60) + ' h' : dec.tempo_medio_min + ' min'), 'média entre pedido e decisão');

    var cats = ser.map(function (s) {
      var d = new Date(s.data + 'T12:00:00'), fds = s.dow === 0 || s.dow === 6;
      return { s: s, fds: fds, hoje: s.data === hoje, curto: s.data.slice(8), rot: DS[s.dow] + ', ' + ddmm(s.data) };
    });
    cartao('c-pres', 'Presença diária', 'Jornadas esperadas por dia, em dias já encerrados do período.', [['Completa', C1], ['Incompleta', C2], ['Sem batida', CN]], function (corpo, modo) {
      var tem = cats.some(function (c) { return c.s.esperados > 0; });
      if (!tem) { corpo.textContent = ''; var v = document.createElement('div'); v.className = 'vazio'; v.textContent = 'Sem dias encerrados com expediente neste período.'; corpo.appendChild(v); return; }
      if (modo === 't') return Graf.tabela(corpo, ['Dia', 'Esperadas', 'Completas', 'Incompletas', 'Sem batida'], cats.filter(function (c) { return c.s.esperados > 0; }).map(function (c) { return [c.rot, c.s.esperados, c.s.completos, c.s.incompletos, c.s.sem_batida]; }));
      Graf.colunas(corpo, { aria: 'Presença diária por situação da jornada', altura: 230, series: [{ nome: 'Completa', cor: C1 }, { nome: 'Incompleta', cor: C2 }, { nome: 'Sem batida', cor: CN }],
        cats: cats.map(function (c) { return { curto: c.curto, rot: c.rot + (c.fds ? ' (fim de semana)' : ''), fds: c.fds, hoje: c.hoje, partes: [c.s.completos, c.s.incompletos, c.s.sem_batida] }; }) });
    });

    var mx = -1, mi = -1; cats.forEach(function (c, i) { if (c.s.extra_min > mx) { mx = c.s.extra_min; mi = i; } });
    cartao('c-extra', 'Horas extras por dia', 'Total de horas extras de todos os colaboradores a cada dia.', [], function (corpo, modo) {
      if (!(mx > 0)) { corpo.textContent = ''; var v = document.createElement('div'); v.className = 'vazio'; v.textContent = 'Nenhuma hora extra registrada neste período.'; corpo.appendChild(v); return; }
      if (modo === 't') return Graf.tabela(corpo, ['Dia', 'Horas extras'], cats.filter(function (c) { return c.s.extra_min > 0; }).map(function (c) { return [c.rot, hm(c.s.extra_min)]; }));
      Graf.linha(corpo, { aria: 'Horas extras por dia', altura: 210, cor: C1, nome: 'Horas extras', fmtY: function (v) { return v + 'h'; },
        marca: { i: mi, txt: 'Pico ' + hm(mx) },
        pts: cats.map(function (c) { return { curto: c.curto, rot: c.rot, fds: c.fds, hoje: c.hoje, v: c.s.data > hoje ? null : Math.round(c.s.extra_min / 6) / 10, txt: hm(c.s.extra_min) }; }) });
    });

    var st = f.setores || [];
    cartao('c-setor', 'Horas extras por setor', 'Onde as horas extras se concentram no período.', [], function (corpo, modo) {
      var l = st.filter(function (s) { return s.extra_min > 0; });
      if (modo === 't') return Graf.tabela(corpo, ['Setor', 'Colab.', 'Horas extras', 'Trabalhadas', 'Dias a ajustar'], st.map(function (s) { return [s.setor || 'Sem setor', s.colaboradores, hm(s.extra_min), hm(s.trabalhado_min), s.dias_ajustar]; }));
      Graf.barrasH(corpo, l.map(function (s) { return { nome: s.setor || 'Sem setor', v: s.extra_min, rot: hm(s.extra_min), sub: plural(s.colaboradores, 'colaborador', 'colaboradores') }; }), { vazio: 'Nenhuma hora extra neste período.' });
    });

    var fx = f.atraso_faixas || [0, 0, 0, 0, 0], rotF = ['No horário', '1–15 min', '16–30 min', '31–60 min', '+60 min'];
    cartao('c-atraso', 'Pontualidade na entrada', 'Quantas entradas ficaram em cada faixa de atraso (já descontada a tolerância).', [['No horário', C1], ['Com atraso', C2]], function (corpo, modo) {
      var tot = fx.reduce(function (a, b) { return a + b; }, 0);
      if (!tot) { corpo.textContent = ''; var v = document.createElement('div'); v.className = 'vazio'; v.textContent = 'Sem entradas registradas neste período.'; corpo.appendChild(v); return; }
      if (modo === 't') return Graf.tabela(corpo, ['Faixa', 'Entradas'], fx.map(function (n, i) { return [rotF[i], n]; }));
      Graf.colunas(corpo, { aria: 'Entradas por faixa de atraso', altura: 190, series: [{ nome: 'No horário', cor: C1 }, { nome: 'Com atraso', cor: C2 }],
        cats: fx.map(function (n, i) { return { curto: rotF[i].replace(' min', ''), rot: rotF[i], partes: i ? [0, n] : [n, 0], linhas: [{ cor: i ? C2 : C1, nome: n === 1 ? 'entrada' : 'entradas', valor: String(n) }] }; }) });
      var ra = f.ranking_atrasos || [];
      if (ra.length) {
        var t = document.createElement('div'); t.className = 'sub-pn'; t.style.marginTop = '10px'; t.textContent = 'Mais atrasos no período'; corpo.appendChild(t);
        var b = document.createElement('div'); corpo.appendChild(b);
        Graf.barrasH(b, ra.slice(0, 5).map(function (c) { return { nome: c.nome, ir: c.nome, v: c.atrasos, rot: c.atrasos + '/' + c.entradas }; }), { cor: C2 });
      }
    });

    var fa = t.facial, ma = t.manual;
    cartao('c-origem', 'Origem das batidas', 'Reconhecimento facial contra batida incluída manualmente (com aprovação).', [], function (corpo, modo) {
      if (modo === 't') return Graf.tabela(corpo, ['Origem', 'Batidas'], [['Facial', fa], ['Manual', ma]]);
      Graf.segmentada(corpo, [{ nome: 'Facial', v: fa, cor: C1 }, { nome: 'Manual', v: ma, cor: C2 }], { unidade: 'batidas', vazio: 'Sem batidas neste período.' });
    });

    var sw = f.solic_semanas || [];
    cartao('c-solic', 'Solicitações por semana', 'Pedidos de inclusão e remoção de batida nas últimas semanas.', [['Inclusão', C1], ['Remoção', C2]], function (corpo, modo) {
      if (!sw.some(function (s) { return s.inclusao + s.remocao > 0; })) { corpo.textContent = ''; var v = document.createElement('div'); v.className = 'vazio'; v.textContent = 'Nenhuma solicitação nas últimas semanas.'; corpo.appendChild(v); return; }
      var rot = function (s) { return 'Semana de ' + ddmm(s.semana); };
      if (modo === 't') return Graf.tabela(corpo, ['Semana', 'Inclusões', 'Remoções'], sw.map(function (s) { return [rot(s), s.inclusao, s.remocao]; }));
      Graf.colunas(corpo, { aria: 'Solicitações por semana', altura: 190, series: [{ nome: 'Inclusão', cor: C1 }, { nome: 'Remoção', cor: C2 }],
        cats: sw.map(function (s) { return { curto: ddmm(s.semana), rot: rot(s), partes: [s.inclusao, s.remocao] }; }) });
    });
  }
  var rzT = null;
  window.addEventListener('resize', function () { clearTimeout(rzT); rzT = setTimeout(function () { desenhaHoje(); Object.keys(cartoes).forEach(function (id) { if (cartoes[id].modo === 'g') cartoes[id].pinta(); }); }, 150); });

  async function carregarFolha() {
    $('k-folha').classList.add('sk');
    var j = await api('ponto_admin_dashboard_folha' + (est.ref ? '&ref=' + encodeURIComponent(est.ref) : ''));
    if (!j.sucesso) throw new Error(j.erro || 'Não foi possível carregar a folha.');
    pintaFolha(j); pintaGraficos(j);
  }

  /* ---------------- ações ---------------- */
  async function decidir(itens, dec, motivo) {
    var ok = 0, falha = null;
    for (var i = 0; i < itens.length; i++) {
      try { var r = await api('ponto_admin_decidir', { id: +itens[i].id, solic: itens[i].solic, decisao: dec, motivo: motivo || '' }); if (r.sucesso) ok++; else falha = r.erro; }
      catch (e) { falha = e.message; }
    }
    aviso(falha ? ok + ' decidida(s); erro: ' + falha : ok + (dec === 'aprovar' ? ' solicitação(ões) aprovada(s).' : ' solicitação(ões) recusada(s).'), falha ? 'erro' : 'ok');
    await Promise.all([carregarLeve(), carregarFolha()]);
  }
  function aposAcao(e) {
    var ir = e.target.closest('[data-ir]'); if (ir) { location.href = 'apuracao.html?colab=' + encodeURIComponent(ir.getAttribute('data-ir')); return; }
    var t = e.target.closest('[data-a]'); if (!t || est.ocupado) return;
    var a = t.getAttribute('data-a'), alvo, dec;
    var meus = est.pend.filter(function (i) { return !i.propria; });
    if (a === 'aprovar-todas' || a === 'recusar-todas') { alvo = meus; dec = a === 'aprovar-todas' ? 'aprovar' : 'recusar'; }
    else { alvo = est.pend.filter(function (i) { return String(i.id) === t.getAttribute('data-id') && i.solic === t.getAttribute('data-solic'); }); dec = a; }
    if (!alvo.length) return;
    if (dec === 'recusar') { est.recAlvo = alvo; $('rec-tit').textContent = alvo.length > 1 ? 'Recusar ' + alvo.length + ' solicitações' : 'Recusar solicitação'; $('rec-motivo').value = ''; $('rec-erro').classList.add('hidden'); $('modal-rec').classList.remove('hidden'); $('rec-motivo').focus(); return; }
    if (alvo.length > 1 && !confirm('Aprovar ' + alvo.length + ' solicitações?')) return;
    est.ocupado = true; t.disabled = true;
    decidir(alvo, 'aprovar').finally(function () { est.ocupado = false; });
  }
  document.addEventListener('click', function (e) { if (e.target.closest('#l-pend,#l-apr,#acoes-apr,#r-extra,#r-ajustar,#l-mov,#c-atraso')) aposAcao(e); });
  $('rec-fechar').onclick = function () { $('modal-rec').classList.add('hidden'); };
  $('rec-ok').onclick = async function () {
    var m = $('rec-motivo').value.trim();
    if (m.length < 5) { $('rec-erro').textContent = 'Informe o motivo da recusa (mínimo de 5 caracteres).'; $('rec-erro').classList.remove('hidden'); return; }
    est.ocupado = true; this.disabled = true;
    try { $('modal-rec').classList.add('hidden'); await decidir(est.recAlvo, 'recusar', m); } finally { est.ocupado = false; $('rec-ok').disabled = false; }
  };
  $('d-atualizar').onclick = async function () { this.disabled = true; try { await Promise.all([carregarLeve(), carregarFolha()]); aviso(''); } catch (e) { aviso(e.message, 'erro'); } finally { this.disabled = false; } };
  $('f-ant').onclick = async function () { try { est.ref = await refDeslocada(-1); await carregarFolha(); } catch (e) { aviso(e.message, 'erro'); } };
  $('f-prox').onclick = async function () { try { est.ref = await refDeslocada(1); await carregarFolha(); } catch (e) { aviso(e.message, 'erro'); } };
  function refDeslocada(d) { var y = +est.ref.slice(0, 4), m = +est.ref.slice(5, 7), t = y * 12 + (m - 1) + d; return Promise.resolve(Math.floor(t / 12) + '-' + p2(t % 12 + 1)); }

  /* ---------------- início ---------------- */
  (async function () {
    try {
      var me = await api('ponto_me');
      if (!me.sucesso) { location.href = 'index.html'; return; }
      if (window.ptNav) ptNav.aplicar(me);
      if (!me.gestor) { aviso('Acesso restrito a gestores (perfil admin/mestre).', 'erro'); return; }
      $('conteudo').classList.remove('hidden');
      await carregarLeve();
      await carregarFolha();
      setInterval(function () { if (!document.hidden && !est.ocupado) carregarLeve().catch(function () {}); }, 60000);
      setInterval(function () { if (!document.hidden && !est.ocupado) carregarFolha().catch(function () {}); }, 300000);
    } catch (e) { aviso(e.message, 'erro'); }
  })();
})();
