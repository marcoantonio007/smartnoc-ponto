(function () {
  'use strict';
  var DIAS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
  var MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
  var TIPOS = ['ENTRADA', 'SAIDA_ALMOCO', 'RETORNO_ALMOCO', 'SAIDA'];
  var CURTO = { ENTRADA: 'Entrada', SAIDA_ALMOCO: 'Almoço', RETORNO_ALMOCO: 'Almoço', SAIDA: 'Saída', EXTRA_ENTRADA: 'Início extra', EXTRA_SAIDA: 'Fim extra' };
  var EXTRAS = ['EXTRA_ENTRADA', 'EXTRA_SAIDA'];
  var ROT_FDS = { ENTRADA: 'Entrada', SAIDA: 'Saída', EXTRA_ENTRADA: 'Entrada (2º período)', EXTRA_SAIDA: 'Saída (2º período)' }; // PT_FDS_V1
  var CURTO_FDS = { ENTRADA: 'Entrada', SAIDA: 'Saída', EXTRA_ENTRADA: 'Entrada', EXTRA_SAIDA: 'Saída' };
  function fdsDia(d) { return (!d.tem_regra || d.sem_obrigacao) && !ativaDe(d, 'SAIDA_ALMOCO') && !ativaDe(d, 'RETORNO_ALMOCO'); }
  function rotDia(d, t) { return (d && fdsDia(d) && ROT_FDS[t]) || est.rotulos[t]; }
  var ATIVAS = ['valida', 'pendente', 'aprovada'];
  var PREVISTO = 540;
  var $ = function (id) { return document.getElementById(id); };
  var est = { folha: null, rotulos: {}, alvo: null, gestor: false, colab: null };
  var MODO = document.body.getAttribute('data-modo') === 'apuracao'; // página Apuração de Batida: o gestor escolhe o colaborador
  var FACE = '<svg class="fi" aria-hidden="true"><use href="#ic-face"/></svg>';

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
  function vpLinks(i) { var l = i.vidpols || []; return l.length ? l.map(function (v) { return '<a href="https://videopolicia.atlassian.net/browse/VIDPOL-' + encodeURIComponent(v) + '" target="_blank" rel="noopener noreferrer" title="Abrir o chamado no Jira" style="color:#9fc0f5;text-decoration:underline;font-weight:700">VIDPOL-' + esc(v) + '</a>'; }).join(', ') : esc(i.justificativa || ''); }
  function hhmm(ts) { return ts ? ts.substring(11, 16) : ''; }
  function heSt(d) { var s = d.he_status; if (!s || s === 'aprovada') return ''; return (s === 'recusada' || s === 'cancelada') ? ' · ' + s : ''; }
  function dataBR(s) { var p = s.split('-'); return p[2] + '/' + p[1]; }
  function fmtMin(m) { var a = Math.abs(Math.round(m)); return String(Math.floor(a / 60)).padStart(2, '0') + ':' + String(a % 60).padStart(2, '0'); }
  function tmin(h) { var m = /^(\d{1,2}):(\d{2})$/.exec(h || ''); return m ? (+m[1]) * 60 + (+m[2]) : null; }
  function dia(data) { return est.folha.dias.filter(function (x) { return x.data === data; })[0]; }
  function ativaDe(d, t) { return d.batidas.filter(function (b) { return b.tipo === t && ATIVAS.indexOf(b.status) >= 0; })[0]; }
  function temAtiva(d) { return d.batidas.some(function (b) { return ATIVAS.indexOf(b.status) >= 0; }); }

  async function api(action, body) {
    if (MODO && est.colab) action += '&alvo=' + encodeURIComponent(est.colab);
    var r = await fetch('api_ponto.php?action=' + action, body === undefined ? { credentials: 'same-origin' } :
      { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) });
    var txt = await r.text(), j;
    try { j = JSON.parse(txt); } catch (e) { throw new Error('Resposta inválida do servidor.'); }
    if (r.status === 401) { location.href = 'index.html'; }
    return j;
  }
  function aviso(msg, tipo) {
    var a = $('aviso'); if (!msg) { a.classList.add('hidden'); return; }
    a.className = 'aviso ' + (tipo === 'erro' ? 'erro' : tipo === 'ok' ? 'ok' : 'info');
    a.textContent = msg;
    if (tipo !== 'erro') setTimeout(function () { if (a.textContent === msg) a.classList.add('hidden'); }, 6000);
  }

  async function carregar(ref) {
    var j = await api('ponto_folha' + (ref ? '&ref=' + encodeURIComponent(ref) : ''));
    if (!j.sucesso) { $('conteudo').classList.add('hidden'); return aviso(j.erro, 'erro'); }
    est.folha = j.folha; est.rotulos = j.rotulos; est.banco = j.banco || null;
    est.bloq = MODO && j.gestor_edita === false; // regra: o gestor não inclui/remove batidas dos subordinados
    if (est.bloq) est.folha.dias.forEach(function (d) { d.editavel = false; });
    render();
    if (MODO) await painelPendentes();
  }

  /* ---------------- lista de dias ---------------- */
  function abonoDia(d) { return (d.abonos || []).filter(function (a) { return a.tipo === 'dia' && a.status === 'aprovada'; })[0]; }
  function abonoHtml(d) { // abonos do dia (aprovados ou em análise), com o motivo escolhido
    return (d.abonos || []).map(function (a) {
      var ok = a.status === 'aprovada', q = a.tipo === 'dia' ? 'Dia todo' : (a.hora_ini + '–' + a.hora_fim);
      return '<div class="lh ' + (ok ? 'pos' : 'amb') + '"><span>Abono · ' + esc(a.motivo) + (ok ? '' : ' (em análise)') +
        (a.tem_anexo ? ' <a href="api_ponto.php?action=ponto_atestado_arquivo&id=' + a.id + '" target="_blank" rel="noopener" style="color:#9fc0f5;text-transform:none">📎 Ver anexo</a>' : '') + '</span><b>' + q + '</b></div>';
    }).join('');
  }
  function chipDia(d) {
    var ab = abonoDia(d);
    if (d.pre_admissao) return '<span class="chip" title="Antes da data de admissão">Sem expediente</span>';
    if (ab && !temAtiva(d)) return '<span class="chip ok" title="' + esc(ab.motivo) + '">Abono</span>';
    if (d.feriado && !temAtiva(d)) return '<span class="chip" title="' + esc(d.feriado + ' — dia sem expediente') + '">Feriado</span>'; /* FERIADO_CHIP_V1 */
    if (d.futuro) return '<span class="chip">Futuro</span>';
    if (d.extra_min > 0 && ['aguardando_batida', 'sem_info', 'em_aprovacao'].indexOf(d.he_status) >= 0) return '<span class="chip pend" title="' + ({ aguardando_batida: 'Aguardando o gestor aprovar a batida', sem_info: 'Informe o VIDPOL ou a justificativa da hora extra', em_aprovacao: 'Hora extra aguardando a aprovação do gestor' }[d.he_status]) + '">Em análise</span>';
    var pend = d.pendentes + d.remocoes_pendentes;
    if (!d.faltam.length) return '<span class="chip ' + (pend ? 'pend' : 'ok') + '">' + (pend ? 'Em análise' : 'Completo') + '</span>';
    if ((!d.tem_regra || d.sem_obrigacao) && ativaDe(d, 'ENTRADA') && ativaDe(d, 'SAIDA') && !(ativaDe(d, 'EXTRA_ENTRADA') && !ativaDe(d, 'EXTRA_SAIDA'))) return '<span class="chip ' + (pend ? 'pend' : 'ok') + '">' + (pend ? 'Em análise' : 'Hora extra') + '</span>';
    if (!d.tem_regra || d.sem_obrigacao) return temAtiva(d) ? '<span class="chip pend">Incompleto</span>' : '<span class="chip">' + (d.sem_obrigacao ? 'Folga/feriado' : 'Sem expediente') + '</span>';
    if (d.hoje) return '<span class="chip pend">Em andamento</span>';
    return '<span class="chip bad">' + (temAtiva(d) ? 'Incompleto' : 'Pendente') + '</span>';
  }

  function celulas(d) {
    var sug = (d.tem_regra && !d.sem_obrigacao) ? (d.sugestoes || {}) : {};
    var lista = fdsDia(d) ? ['ENTRADA', 'SAIDA'].concat(temExtra(d) ? EXTRAS : []) : (temExtra(d) ? TIPOS.concat(EXTRAS) : TIPOS);
    return lista.map(function (t) {
      var b = ativaDe(d, t), cls = 'c', hora, tag = 'div', attrs = '', dica = rotDia(d, t), ico = '';
      if (b) {
        hora = hhmm(b.momento);
        if (b.status === 'pendente' || b.remocao_status === 'pendente') cls += ' pend';
        else if (b.status === 'aprovada') cls += ' apr';
        if (b.origem === 'facial') { ico = FACE; dica += ' — registrada por reconhecimento facial'; } else if (b.origem === 'sistema') dica += ' — registrado automaticamente pelo sistema';
      } else if (sug[t]) { hora = sug[t]; cls += ' sug'; dica += ' — horário previsto'; }
      else hora = '—';
      if (!d.futuro) { tag = 'button'; cls += ' clic'; attrs = ' type="button" data-cel="' + d.data + '" data-tipo="' + t + '"'; }
      return '<' + tag + ' class="' + cls + '"' + attrs + ' title="' + esc(dica) + '">' + ico + '<small>' + (fdsDia(d) ? CURTO_FDS[t] : CURTO[t]) + '</small><b>' + hora + '</b></' + tag + '>';
    });
  }

  function semExp(d) { return !d.tem_regra || d.sem_obrigacao; }
  function temExtra(d) { return EXTRAS.some(function (t) { return ativaDe(d, t); }); }
  function extraProx(d) { // próximo registro do período de hora extra (depois do fim de expediente)
    if (!ativaDe(d, 'SAIDA')) return null;
    if (!ativaDe(d, 'EXTRA_ENTRADA')) return 'EXTRA_ENTRADA';
    if (!ativaDe(d, 'EXTRA_SAIDA')) return 'EXTRA_SAIDA';
    return null;
  }
  function opcoesExtra(d) {
    if (fdsDia(d)) { var p1 = ['ENTRADA', 'SAIDA'].filter(function (t) { return !ativaDe(d, t); }); if (p1.length) return p1; if (!ativaDe(d, 'EXTRA_ENTRADA')) return ['EXTRA_ENTRADA']; return ativaDe(d, 'EXTRA_SAIDA') ? [] : ['EXTRA_SAIDA']; }
    var o = (semExp(d) && !(ativaDe(d, 'ENTRADA') && ativaDe(d, 'SAIDA'))) ? TIPOS.filter(function (t) { return !ativaDe(d, t); }) : [];
    var x = extraProx(d); if (x) o.push(x);
    return o;
  }
  function msgFora() { return est.bloq ? '' : '<p class="peq">Este dia está fora do período de ajuste (folha atual e anterior). Fale com o supervisor.</p>'; }
  function podeExtra(d) { return !(d.pre_admissao || d.futuro || !d.editavel || !opcoesExtra(d).length); }
  function rotuloExtra(d) { return semExp(d) ? '+ Registro' : '+ Hora extra'; }
  function totaisDia(d) {
    var ab = abonoHtml(d);
    if (!d.previsto_min && !temAtiva(d)) return ab ? '<div class="tt">' + ab + '</div>' : '';
    var trab = d.futuro ? '—' : fmtMin(d.trab_parcial_min || 0);
    var h = '<div class="lh"><span>Previstas</span><b>' + fmtMin(d.previsto_min) + '</b></div>' +
      '<div class="lh"><span>Trabalhadas</span><b>' + trab + '</b></div>';
    if (d.extra_min > 0) {
      var n = (d.vidpols || []).length;
      h += '<button type="button" class="lh ' + (d.he_status === 'aprovada' ? 'pos' : 'amb') + '" data-he="' + d.data + '" title="Informar VIDPOL ou justificativa"><span>Hora extra' + (n ? ' · ' + n + ' VIDPOL' : (d.he_just ? ' · justificada' : '')) + heSt(d) + '</span><b>' + fmtMin(d.extra_min) + '</b></button>';
    }
    if (d.pagar_min > 0) h += '<div class="lh neg"><span>Hora a pagar</span><b>' + fmtMin(d.pagar_min) + '</b></div>';
    return '<div class="tt">' + h + ab + '</div>';
  }
  function temHist(d) {
    return d.batidas.some(function (b) { return b.origem !== 'facial' || b.status !== 'valida' || b.remocao_status; }) || (d.vidpols || []).length > 0;
  }

  function render() {
    var f = est.folha, p = f.ref.split('-');
    $('conteudo').classList.remove('hidden');
    var mes = MESES[+p[1] - 1]; $('f-titulo').textContent = mes.charAt(0).toUpperCase() + mes.slice(1) + ' de ' + p[0];
    $('f-periodo').textContent = dataBR(f.inicio) + ' a ' + dataBR(f.fim) + (f.ref === f.atual ? ' · folha atual' : '');
    $('f-prox').disabled = f.ref >= f.atual;
    $('f-ant').disabled = !!(f.ref_min && f.ref <= f.ref_min); // não volta além da folha em que o colaborador foi admitido
    var r = f.resumo;
    $('k-prev').textContent = fmtMin(r.previsto_min);
    $('k-trab').textContent = fmtMin(r.trabalhado_min);
    var ke = $('k-extra'); ke.textContent = fmtMin(r.extra_min); ke.className = r.extra_min ? 'pos' : ''; ke.title = r.extra_aguardando_min ? '+ ' + fmtMin(r.extra_aguardando_min) + ' de hora extra aguardando aprovação (ainda não computada)' : '';
    var kg = $('k-pagar'); kg.textContent = fmtMin(r.pagar_min); kg.className = r.pagar_min ? 'neg' : '';
    var kp = $('k-pend'); kp.textContent = r.dias_pendentes; kp.className = r.dias_pendentes ? 'neg' : 'pos';
    var ks = $('k-solic'); ks.textContent = r.solicitacoes; ks.className = r.solicitacoes ? 'amb' : '';
    renderBanco();
    $('f-dias').innerHTML = f.dias.map(function (d) {
      var cls = 'dia' + (d.futuro ? ' futuro' : '') + (d.hoje ? ' hoje' : '');
      var mostraCel = temAtiva(d) || (d.tem_regra && !d.sem_obrigacao && !d.futuro);
      var cel = mostraCel ? celulas(d) : [], pe = podeExtra(d), rot = rotuloExtra(d);
      var add = pe ? '<button type="button" class="c addx" data-extra="' + d.data + '" title="Incluir registro de hora extra">' + rot + '</button>' : '';
      return '<div class="' + cls + '"><div class="sd"><span class="ddw"><span class="dd">' + DIAS[d.dow] + ' ' + dataBR(d.data) + '</span>' +
        (temHist(d) ? '<button type="button" class="hbtn" data-hist="' + d.data + '" title="Histórico e auditoria do dia" aria-label="Histórico do dia"><svg aria-hidden="true"><use href="#ic-hist"/></svg></button>' : '') +
        (pe ? '<button type="button" class="mini dmob" data-extra="' + d.data + '">' + rot + '</button>' : '') +
        '</span>' + chipDia(d) + '</div>' +
        '<div class="cols' + (cel.length ? '' : ' soadd') + '" style="--n:' + (cel.length + (pe ? 1 : 0)) + '">' + cel.join('') + add + '</div><div class="tot">' + totaisDia(d) + '</div></div>';
    }).join('');
  }

  $('f-ant').onclick = function () { carregar(est.folha.anterior); };
  $('f-prox').onclick = function () { carregar(est.folha.proxima); };
  var bPdf = $('f-pdf'); if (bPdf) bPdf.onclick = async function () { // espelho de ponto da folha exibida (PDF de uma página); a Apuração não tem o botão
    var b = this, rot = b.textContent; b.disabled = true; b.textContent = 'Gerando PDF…';
    try {
      var a = 'ponto_espelho_pdf&ref=' + encodeURIComponent(est.folha.ref) + (MODO && est.colab ? '&alvo=' + encodeURIComponent(est.colab) : '');
      var r = await fetch('api_ponto.php?action=' + a, { credentials: 'same-origin' });
      if (r.status === 401) { location.href = 'index.html'; return; }
      if (!/pdf/.test(r.headers.get('Content-Type') || '')) { var j = {}; try { j = await r.json(); } catch (e) {} throw new Error(j.erro || 'Não foi possível gerar o espelho.'); }
      var blob = await r.blob(), u = URL.createObjectURL(blob), l = document.createElement('a'), p = est.folha.ref.split('-');
      l.href = u; l.download = 'folha-ponto-' + p[1] + '-' + p[0] + '.pdf'; document.body.appendChild(l); l.click(); l.remove();
      setTimeout(function () { URL.revokeObjectURL(u); }, 4000);
    } catch (e) { aviso(e.message, 'erro'); } finally { b.disabled = false; b.textContent = rot; }
  };

  /* ---------------- janela da batida ---------------- */
  function projecao(d, tipo, hora) {
    if (tipo.indexOf('EXTRA') === 0) return '';
    var m = {}, i; ativas(d).forEach(function (b) { m[b.tipo] = tmin(hhmm(b.momento)); });
    var h = tmin(hora); if (h == null) return '';
    m[tipo] = h;
    var sg = d.sugestoes || {};
    var dur = Math.max(30, (tmin(sg.RETORNO_ALMOCO) || 780) - (tmin(sg.SAIDA_ALMOCO) || 720));
    var e = m.ENTRADA, sa = m.SAIDA_ALMOCO, ra = m.RETORNO_ALMOCO, s = m.SAIDA, partes = [];
    if (tipo === 'SAIDA') {
      if (e != null && sa != null && ra != null) {
        var tr = (sa - e) + (s - ra), dif = tr - (d.previsto_min || PREVISTO);
        return 'Com este horário a jornada fica em ' + fmtMin(tr) + (Math.abs(dif) <= 5 ? ' (dentro da tolerância).' : dif > 0 ? ' (' + fmtMin(dif) + ' de hora extra).' : ' (' + fmtMin(-dif) + ' a pagar).');
      }
      return '';
    }
    if (e == null) return '';
    if (sa == null) sa = tipo === 'ENTRADA' ? (tmin(sg.SAIDA_ALMOCO) || 720) : null;
    if (sa == null) return '';
    if (ra == null) ra = sa + dur;
    if (s == null) s = ra + Math.max(0, (d.previsto_min || PREVISTO) - (sa - e));
    if (tipo === 'ENTRADA' || tipo === 'SAIDA_ALMOCO') partes.push('retorno do almoço ' + fmtMin(ra));
    partes.push('saída ' + fmtMin(s));
    return 'Para fechar ' + fmtMin(d.previsto_min || PREVISTO) + ' de jornada, o sistema vai sugerir: ' + partes.join(' e ') + '.';
  }
  function ativas(d) { return d.batidas.filter(function (b) { return ATIVAS.indexOf(b.status) >= 0; }); }

  function abrirBat(data, tipo) {
    var d = dia(data); if (!d) return;
    est.alvo = { data: data, tipo: tipo };
    var b = ativaDe(d, tipo), ano = data.substring(0, 4), h = '';
    var mortas = d.batidas.filter(function (x) { return x.tipo === tipo && ATIVAS.indexOf(x.status) < 0; });
    $('bat-tit').textContent = rotDia(d, tipo);
    $('bat-erro').classList.add('hidden');
    h += '<div class="mudo" style="font-size:14px;margin-bottom:10px">' + DIAS[d.dow] + ', ' + dataBR(data) + '/' + ano + '</div>';
    var hist = mortas.map(function (x) {
      var st = x.status === 'recusada' ? 'inclusão recusada' : x.status === 'cancelada' ? 'pedido cancelado' : 'removida';
      return '<div>• ' + hhmm(x.momento) + ' — ' + st + (x.status === 'recusada' && x.motivo_decisao ? ': ' + esc(x.motivo_decisao) : '') + '</div>';
    }).join('');
    var gest = est.gestor;
    if (!b) {
      var sug = (d.tem_regra && !d.sem_obrigacao && d.sugestoes) ? d.sugestoes[tipo] : '';
      h += '<div class="info-bat">Nenhuma batida registrada.</div>' + (hist ? '<div class="hist-bat">' + hist + '</div>' : '');
      if (d.editavel) {
        h += '<label class="rot">Horário<input id="bat-hora" type="time" value="' + esc(sug || '') + '"></label>' +
          '<div id="bat-proj" class="dica"></div>' +
          '<label class="rot" style="margin-top:14px">Justificativa (obrigatória)<textarea id="bat-just" rows="3" maxlength="500" placeholder="Explique por que a batida precisa ser incluída"></textarea></label>' +
          '<p class="peq">A inclusão só entra na folha depois que o supervisor aprovar.</p>' +
          '<button type="button" class="btn" data-act="incluir">Enviar para aprovação</button>';
      } else h += msgFora();
    } else {
      var origem = b.origem === 'facial' ? '<div class="linha">' + FACE + '<span>Registrada por reconhecimento facial</span></div>' : '<div class="linha"><span>Inclusão manual' + (b.status === 'aprovada' ? ' (aprovada)' : b.status === 'pendente' ? ' (aguardando aprovação)' : '') + '</span></div>';
      h += '<div class="info-bat"><div style="font-size:24px;font-weight:800">' + hhmm(b.momento) + '</div>' + origem +
        (b.justificativa ? '<div class="linha"><span>Justificativa: ' + esc(b.justificativa) + '</span></div>' : '') + '</div>';
      if (hist) h += '<div class="hist-bat">' + hist + '</div>';
      if (b.status === 'pendente') {
        h += '<div class="acoes">' + (d.editavel ? '<button type="button" class="btn verm" data-act="cancelar-inc">Cancelar pedido</button>' : '') +
          (gest ? '<button type="button" class="btn" data-act="aprovar-inc">Aprovar agora</button>' : '') + '</div>';
        if (!d.editavel && !gest) h += '<p class="peq">Aguardando aprovação do supervisor.</p>';
      } else if (b.remocao_status === 'pendente') {
        h += '<div class="info-bat"><b>Remoção solicitada:</b> ' + esc(b.remocao_justificativa) + '</div><div class="acoes">' +
          (d.editavel ? '<button type="button" class="btn verm" data-act="cancelar-rem">Cancelar pedido de remoção</button>' : '') +
          (gest ? '<button type="button" class="btn" data-act="aprovar-rem">Aprovar remoção agora</button>' : '') + '</div>';
      } else if (d.editavel) {
        if (b.remocao_status === 'recusada') h += '<div class="hist-bat">Remoção recusada anteriormente' + (b.remocao_motivo ? ': ' + esc(b.remocao_motivo) : '') + '</div>';
        h += '<label class="rot">Justificativa para remover (obrigatória)<textarea id="bat-just" rows="3" maxlength="500" placeholder="Explique por que esta batida deve ser removida"></textarea></label>' +
          '<p class="peq">A batida continua valendo até o supervisor aprovar a remoção.</p>' +
          '<button type="button" class="btn verm" data-act="remover">Solicitar remoção</button>';
      } else h += msgFora();
    }
    $('bat-corpo').innerHTML = h;
    var hi = $('bat-hora');
    if (hi) { var up = function () { $('bat-proj').textContent = projecao(d, tipo, hi.value); }; hi.oninput = up; hi.onchange = up; up(); }
    $('modal-bat').classList.remove('hidden');
  }
  $('bat-fechar').onclick = function () { $('modal-bat').classList.add('hidden'); };

  $('bat-corpo').addEventListener('click', async function (e) {
    var t = e.target.closest('button[data-act]'); if (!t) return;
    var act = t.getAttribute('data-act'), a = est.alvo, d = dia(a.data), b = ativaDe(d, a.tipo), er = $('bat-erro'), just = $('bat-just');
    er.classList.add('hidden'); t.disabled = true;
    try {
      var j, msg;
      if (act === 'incluir') { j = await api('ponto_manual', { data: a.data, tipo: a.tipo, hora: $('bat-hora').value, justificativa: just.value }); msg = 'Pedido de inclusão enviado para aprovação.'; }
      else if (act === 'remover') { j = await api('ponto_remover', { id: b.id, justificativa: just.value }); msg = 'Pedido de remoção enviado para aprovação.'; }
      else if (act === 'cancelar-inc') { j = await api('ponto_cancelar', { id: b.id, solic: 'inclusao' }); msg = 'Pedido cancelado.'; }
      else if (act === 'cancelar-rem') { j = await api('ponto_cancelar', { id: b.id, solic: 'remocao' }); msg = 'Pedido de remoção cancelado.'; }
      else if (act === 'aprovar-inc') { j = await api('ponto_admin_decidir', { id: b.id, solic: 'inclusao', decisao: 'aprovar' }); msg = 'Inclusão aprovada.'; }
      else if (act === 'aprovar-rem') { j = await api('ponto_admin_decidir', { id: b.id, solic: 'remocao', decisao: 'aprovar' }); msg = 'Remoção aprovada.'; }
      if (!j.sucesso) { er.textContent = j.erro; er.classList.remove('hidden'); return; }
      $('modal-bat').classList.add('hidden'); aviso(msg, 'ok'); await carregar(est.folha.ref);
    } catch (x) { er.textContent = x.message; er.classList.remove('hidden'); } finally { t.disabled = false; }
  });

  /* ---------------- hora extra / VIDPOL ---------------- */
  function abrirHE(data) {
    var d = dia(data); if (!d) return;
    est.alvo = { data: data };
    $('he-info').innerHTML = DIAS[d.dow] + ', ' + dataBR(data) + '/' + data.substring(0, 4) + '<br><b>Hora extra: ' + fmtMin(d.extra_min) + '</b>';
    var l = d.vidpols || [];
    $('he-lista').innerHTML = l.length ? l.map(function (v) {
      return '<div class="vp"><span><a href="https://videopolicia.atlassian.net/browse/VIDPOL-' + encodeURIComponent(v.vidpol) + '" target="_blank" rel="noopener noreferrer" title="Abrir o chamado no Jira" style="color:#9fc0f5;text-decoration:underline">VIDPOL-' + esc(v.vidpol) + '</a></span>' + (d.editavel ? '<button type="button" data-del="' + v.id + '" aria-label="Remover VIDPOL">&times;</button>' : '') + '</div>';
    }).join('') : '<div class="mudo" style="font-size:13px">Nenhum VIDPOL informado.</div>';
    $('he-novo').value = ''; $('he-erro').classList.add('hidden');
    var pode = (d.editavel || est.bloq) && d.he_liberado !== false; // só com a batida aprovada e a hora extra não recusada/cancelada
    $('he-just-ver').innerHTML = (d.he_liberado === false ? '<div class="mudo" style="font-size:13px;margin:8px 0">' + (d.he_status === 'aguardando_batida' ? 'Aguardando o gestor aprovar a batida deste dia. Depois disso você poderá informar o VIDPOL ou a justificativa da hora extra.' : 'A hora extra deste dia foi ' + (d.he_status === 'recusada' ? 'recusada' : 'cancelada') + ' pelo gestor.') + '</div>' : '') + (d.he_just && (l.length || !pode) ? '<div class="vp"><span>Justificativa: ' + esc(d.he_just) + '</span></div>' : '');
    $('he-just').value = d.he_just || '';
    $('he-form').style.display = pode ? '' : 'none';
    $('he-sem-box').style.display = l.length ? 'none' : 'flex'; // com VIDPOL informado não há a opção "sem VIDPOL"
    $('he-sem').checked = !l.length && !!d.he_just;
    heModo();
    $('modal-he').classList.remove('hidden');
  }
  $('he-fechar').onclick = function () { $('modal-he').classList.add('hidden'); };
  function heModo() { // um único botão: adicionar VIDPOL ou salvar a justificativa (quando não há VIDPOL)
    var sem = $('he-sem').checked;
    $('he-blk-vp').style.display = sem ? 'none' : ''; $('he-blk-just').style.display = sem ? '' : 'none';
    $('he-add').textContent = sem ? 'Salvar justificativa' : 'Adicionar VIDPOL';
    $('he-erro').classList.add('hidden');
  }
  $('he-sem').onchange = heModo;
  $('he-add').onclick = async function () {
    var b = this, er = $('he-erro'), sem = $('he-sem').checked; er.classList.add('hidden'); b.disabled = true;
    try {
      var r = sem ? await api('ponto_he_justificar', { data: est.alvo.data, justificativa: $('he-just').value })
                  : await api('ponto_vidpol_add', { data: est.alvo.data, vidpol: $('he-novo').value });
      if (!r.sucesso) { er.textContent = r.erro; er.classList.remove('hidden'); return; }
      await carregar(est.folha.ref); abrirHE(est.alvo.data);
      if (sem) aviso('Justificativa registrada. A hora extra segue para a aprovação do gestor.', 'ok');
    } catch (x) { er.textContent = x.message; er.classList.remove('hidden'); } finally { b.disabled = false; }
  };
  $('he-novo').addEventListener('keydown', function (e) { if (e.key === 'Enter') $('he-add').click(); });
  $('he-lista').addEventListener('click', async function (e) {
    var t = e.target.closest('button[data-del]'); if (!t) return;
    var j = await api('ponto_vidpol_del', { id: +t.getAttribute('data-del') });
    if (!j.sucesso) { $('he-erro').textContent = j.erro; $('he-erro').classList.remove('hidden'); return; }
    await carregar(est.folha.ref); abrirHE(est.alvo.data);
  });

  $('f-dias').addEventListener('click', function (e) {
    var t = e.target.closest('button'); if (!t) return;
    if (t.hasAttribute('data-cel')) return abrirBat(t.getAttribute('data-cel'), t.getAttribute('data-tipo'));
    if (t.hasAttribute('data-he')) return abrirHE(t.getAttribute('data-he'));
    if (t.hasAttribute('data-hist')) return abrirHist(t.getAttribute('data-hist'));
    if (t.hasAttribute('data-extra')) return abrirExtra(t.getAttribute('data-extra'));
  });

  /* ---------------- histórico / auditoria do dia ---------------- */
  var EVT = { FOLGA_SOLICITADA: 'Folga (banco de horas) solicitada', FOLGA_APROVADA: 'Folga (banco de horas) aprovada', FOLGA_RECUSADA: 'Folga (banco de horas) recusada', FOLGA_CANCELADA: 'Pedido de folga cancelado', FOLGA_APROVACAO_CANCELADA: 'Aprovação da folga cancelada', ALMOCO_AUTOMATICO: 'Almoço registrado automaticamente', BATIDA_FACIAL: 'Batida registrada (facial)', INCLUSAO_SOLICITADA: 'Inclusão solicitada', INCLUSAO_APROVADA: 'Inclusão aprovada', INCLUSAO_RECUSADA: 'Inclusão recusada',
    INCLUSAO_CANCELADA: 'Inclusão cancelada', INCLUSAO_APROVACAO_CANCELADA: 'Aprovação de inclusão cancelada', REMOCAO_APROVACAO_CANCELADA: 'Aprovação de remoção cancelada', ATESTADO_APROVACAO_CANCELADA: 'Aprovação de abono cancelada', EXTRA_APROVACAO_CANCELADA: 'Aprovação de hora extra cancelada', HE_CANCELADA_BATIDA: 'Batida removida (hora extra cancelada)', REMOCAO_SOLICITADA: 'Remoção solicitada', REMOCAO_APROVADA: 'Remoção aprovada', REMOCAO_RECUSADA: 'Remoção recusada',
    REMOCAO_CANCELADA: 'Remoção cancelada', VIDPOL_INCLUIDO: 'VIDPOL incluído', VIDPOL_REMOVIDO: 'VIDPOL removido', HE_JUSTIFICADA: 'Hora extra justificada (sem VIDPOL)' };
  var STAT = { valida: 'Válida', pendente: 'Aguardando aprovação', aprovada: 'Aprovada', recusada: 'Recusada', cancelada: 'Cancelada', removida: 'Removida' };
  function dh(ts) { if (!ts) return ''; var p = ts.split(' '), d = p[0].split('-'); return d[2] + '/' + d[1] + '/' + d[0] + ' ' + p[1]; }
  function ln(rot, v) { return v ? '<div>' + rot + ': <b>' + esc(v) + '</b></div>' : ''; }

  var ICO_FACIAL = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-3px;margin-right:6px;color:#34d399" role="img" aria-label="Reconhecimento facial"><path d="M4 8V6a2 2 0 0 1 2-2h2M16 4h2a2 2 0 0 1 2 2v2M20 16v2a2 2 0 0 1-2 2h-2M8 20H6a2 2 0 0 1-2-2v-2"/><path d="M9 10v1M15 10v1M9.5 15a3.5 3.5 0 0 0 5 0"/></svg>';
  function tipoAparelho(ua) { return ua ? (/Android|iPhone|iPad|iPod|Mobile/i.test(ua) ? 'Celular' : 'Computador') : ''; }
  function localMaps(det) { var m = /local\s+(-?\d+(?:\.\d+)?),\s*(-?\d+(?:\.\d+)?)/.exec(det || ''); return m ? { lat: m[1], lng: m[2] } : null; }

  async function abrirHist(data) {
    $('hist-tit').textContent = 'Histórico do dia ' + data.split('-').reverse().join('/');
    $('hist-corpo').innerHTML = '<div class="mudo">Carregando…</div>';
    $('modal-hist').classList.remove('hidden');
    var j;
    try { j = await api('ponto_dia_historico&data=' + data); } catch (e) { $('hist-corpo').textContent = e.message; return; }
    if (!j.sucesso) { $('hist-corpo').textContent = j.erro; return; }
    var h = '<div class="hsec" style="margin-top:0">Registros e solicitações (' + j.eventos.length + ')</div>';
    h += j.eventos.length ? j.eventos.map(function (e) {
      var cor = /SOLICITADA$/.test(e.evento) ? 'pend' : /(APROVADA|FACIAL)$/.test(e.evento) ? 'ok' : /(RECUSADA|CANCELADA|REMOVIDO)$/.test(e.evento) ? 'bad' : '';
      var r = '<div class="hreg"><h4><span>' + (e.evento === 'BATIDA_FACIAL' ? ICO_FACIAL : '') + (EVT[e.evento] || esc(e.evento)) + '</span><span class="chip ' + cor + '">' + dh(e.criado_em) + '</span></h4>';
      if (e.tipo) r += '<div>Registro: <b>' + esc(j.rotulos[e.tipo]) + ' · ' + hhmm(e.momento) + '</b></div>';
      r += '<div>Por: <b>' + esc(e.usuario || '—') + '</b></div>';
      if (j.auditoria) r += ln('IP', e.ip) + ln('Aparelho', tipoAparelho(e.agente));
      var loc = localMaps(e.detalhes), det = loc ? e.detalhes.replace(/;\s*local\s+-?\d+(?:\.\d+)?,\s*-?\d+(?:\.\d+)?/, '') : e.detalhes;
      if (loc) r += '<div>Local: <a href="https://www.google.com/maps?q=' + loc.lat + ',' + loc.lng + '" target="_blank" rel="noopener" title="Abrir no Google Maps" style="font-weight:700;color:#60a5fa;text-decoration:none">📍 Ver no mapa</a></div>';
      if (det) r += '<div>' + (/SOLICITADA$/.test(e.evento) ? 'Justificativa' : /(RECUSADA|APROVADA)$/.test(e.evento) ? 'Motivo' : 'Detalhes') + ': <b>' + esc(det) + '</b></div>';
      return r + '</div>';
    }).join('') : '<div class="mudo" style="font-size:13px">Nenhum registro.</div>';
    $('hist-corpo').innerHTML = h;
  }
  $('hist-fechar').onclick = function () { $('modal-hist').classList.add('hidden'); };

  /* ---------------- hora extra manual em dia sem expediente ---------------- */
  /* BANCO_HORAS_V1: saldo, horas a expirar e pedido de folga */
  function fmtSaldo(m) { return (m < 0 ? '−' : '') + fmtMin(Math.abs(m || 0)); }
  function renderBanco() {
    var kp = $('k-prev'); if (!kp) return;
    var grid = kp.parentNode.parentNode, bx = $('banco-box'), b = est.banco;
    if (!bx) { bx = document.createElement('div'); bx.id = 'banco-box'; bx.className = 'kpi'; bx.style.margin = '10px 0 12px'; grid.parentNode.insertBefore(bx, grid.nextSibling); }
    if (!b || !b.ativo) { bx.style.display = 'none'; return; }
    bx.style.display = '';
    var exp = (b.a_expirar || [])[0], pode = !MODO && b.disponivel_min >= b.minimo_folga;
    var h = '<div style="display:flex;flex-wrap:wrap;align-items:center;gap:14px;justify-content:space-between">' +
      '<div><small>Banco de horas</small><b class="' + (b.saldo_min < 0 ? 'neg' : b.saldo_min > 0 ? 'pos' : '') + '">' + fmtSaldo(b.saldo_min) + '</b></div>' +
      '<div style="flex:1;min-width:220px;font-size:12px;font-weight:700;color:#9fb0d6">Disponível para folga: <b style="color:inherit;font-size:12px;display:inline">' + fmtSaldo(b.disponivel_min) + '</b>' +
      (b.pendente_min ? ' · em análise: ' + fmtMin(b.pendente_min) : '') +
      (exp ? '<br>Expira em ' + dataBR(exp.data) + '/' + exp.data.substring(0, 4) + ': ' + fmtMin(exp.min) : '') +
      (b.expirado_min ? '<br>Já expirado: ' + fmtMin(b.expirado_min) : '') + '</div>' +
      (MODO ? '' : pode ? '<button type="button" class="mini" id="bh-folga">Solicitar folga</button>' :
        '<span style="font-size:12px;color:#9fb0d6">Com ' + fmtMin(b.minimo_folga) + ' ou mais você pode pedir folga.</span>') + '</div>';
    var fl = (b.folgas || []).filter(function (f) { return f.status === 'pendente' || f.status === 'aprovada'; });
    if (fl.length) h += '<div style="margin-top:8px;font-size:12px">' + fl.map(function (f) {
      return '<div style="padding:3px 0">' + (f.status === 'aprovada' ? '✅' : '⏳') + ' Folga em ' + dataBR(f.data) + ' (' + fmtMin(f.minutos) + ') — ' + (f.status === 'aprovada' ? 'aprovada' : 'em análise') +
        (!MODO && f.status === 'pendente' ? ' <button type="button" data-bh-cancel="' + f.id + '" style="background:none;border:0;color:#ffb3b8;font-weight:800;cursor:pointer">cancelar</button>' : '') + '</div>';
    }).join('') + '</div>';
    bx.innerHTML = h;
    var bf = $('bh-folga'); if (bf) bf.onclick = abrirFolga;
    Array.prototype.forEach.call(bx.querySelectorAll('[data-bh-cancel]'), function (x) {
      x.onclick = async function () {
        if (!confirm('Cancelar o pedido de folga?')) return;
        var j = await api('ponto_banco_folga_cancelar', { id: +x.getAttribute('data-bh-cancel') });
        if (!j.sucesso) return aviso(j.erro, 'erro');
        aviso('Pedido de folga cancelado.', 'ok'); await carregar(est.folha.ref);
      };
    });
  }
  function abrirFolga() {
    var b = est.banco, d0 = new Date(), hj = d0.getFullYear() + '-' + String(d0.getMonth() + 1).padStart(2, '0') + '-' + String(d0.getDate()).padStart(2, '0');
    var ov = document.createElement('div'); ov.className = 'modal';
    ov.innerHTML = '<div class="modal-box"><div class="modal-topo"><span>Solicitar folga — banco de horas</span><button type="button" class="x" data-x aria-label="Fechar">&times;</button></div>' +
      '<div class="info-bat">Disponível no banco: <b>' + fmtSaldo(b.disponivel_min) + '</b>. A folga abate do banco a jornada prevista do dia escolhido (ex.: 09:00; na sexta, 08:00) e só vale depois da aprovação do gestor.</div>' +
      '<label class="rot" style="margin-top:12px">Dia da folga<input id="bh-data" type="date" min="' + hj + '"></label>' +
      '<label class="rot" style="margin-top:12px">Observação (opcional)<textarea id="bh-just" rows="2" maxlength="300"></textarea></label>' +
      '<div id="bh-erro" class="err hidden"></div><button type="button" class="btn" id="bh-enviar" style="margin-top:12px">Enviar para aprovação</button></div>';
    document.body.appendChild(ov);
    var fechar = function () { ov.remove(); };
    ov.querySelector('[data-x]').onclick = fechar;
    ov.addEventListener('click', function (e) { if (e.target === ov) fechar(); });
    $('bh-enviar').onclick = async function () {
      var bt = this, er = $('bh-erro'); er.classList.add('hidden'); bt.disabled = true;
      try {
        var j = await api('ponto_banco_folga', { data: $('bh-data').value, justificativa: $('bh-just').value });
        if (!j.sucesso) { er.textContent = j.erro; er.classList.remove('hidden'); return; }
        fechar(); aviso('Pedido de folga enviado. Ele vale depois da aprovação do gestor.', 'ok'); await carregar(est.folha.ref);
      } catch (x) { er.textContent = x.message; er.classList.remove('hidden'); } finally { bt.disabled = false; }
    };
  }
  function abrirExtra(data) {
    var d = dia(data); if (!d) return;
    est.alvo = { data: data };
    var tem = ativas(d).map(function (b) { return b.tipo + ' ' + hhmm(b.momento); });
    var faltam = opcoesExtra(d);
    var h = '<div class="mudo" style="font-size:14px;margin-bottom:8px">' + DIAS[d.dow] + ', ' + dataBR(data) + '/' + data.substring(0, 4) + '</div>' +
      '<div class="info-bat">' + (fdsDia(d) ? 'Dia sem expediente: registre a <b>entrada</b> e a <b>saída</b>. Se houve pausa (ex.: almoço), inclua depois mais uma entrada e uma saída (2º período). ' : semExp(d) ? 'Dia sem expediente. ' : 'Hora extra depois do fim de expediente. ') + 'Inclua <b>um registro por vez</b>: se você já bateu o ponto pelo facial, inclua só o que falta (por exemplo, o fim da hora extra). Depois da aprovação do supervisor ele entra como hora extra; em seguida você informa os VIDPOL atendidos.' +
      (tem.length ? '<br>Já registrado: ' + tem.map(function (x) { var p = x.split(' '); return rotDia(d, p[0]) + ' ' + p[1]; }).join(' · ') : '') + '</div>';
    h += '<label class="rot">Registro<select id="ex-tipo">' + faltam.map(function (t) { return '<option value="' + t + '">' + esc(rotDia(d, t)) + '</option>'; }).join('') + '</select></label>' +
      '<label class="rot" style="margin-top:12px">Horário<input id="ex-hora" type="time"></label>' +
      '<label class="rot" style="margin-top:14px">Justificativa (obrigatória)<textarea id="ex-just" rows="3" maxlength="500" placeholder="Ex.: acionamento de incidente no fim de semana"></textarea></label>' +
      '<button type="button" class="btn" data-act="extra-enviar" style="margin-top:12px">Enviar para aprovação</button>';
    $('ex-corpo').innerHTML = h; $('ex-erro').classList.add('hidden');
    $('modal-extra').classList.remove('hidden');
  }
  $('ex-fechar').onclick = function () { $('modal-extra').classList.add('hidden'); };
  $('ex-corpo').addEventListener('click', async function (e) {
    var t = e.target.closest('button[data-act="extra-enviar"]'); if (!t) return;
    var er = $('ex-erro'); er.classList.add('hidden'); t.disabled = true;
    try {
      var j = await api('ponto_manual', { data: est.alvo.data, tipo: $('ex-tipo').value, hora: $('ex-hora').value, justificativa: $('ex-just').value });
      if (!j.sucesso) { er.textContent = j.erro; er.classList.remove('hidden'); return; }
      $('modal-extra').classList.add('hidden');
      await carregar(est.folha.ref);
      var d = dia(est.alvo.data);
      var aberto = d && ((ativaDe(d, 'EXTRA_ENTRADA') && !ativaDe(d, 'EXTRA_SAIDA')) || (semExp(d) && !!ativaDe(d, 'ENTRADA') !== !!ativaDe(d, 'SAIDA')));
      if (d && d.extra_min > 0 && !aberto) { aviso('Registro enviado para aprovação. Depois que o gestor aprovar a batida, informe o VIDPOL (ou a justificativa, se não houver): a hora extra só é computada após a aprovação do gestor.', 'ok'); abrirHE(est.alvo.data); }
      else aviso('Registro enviado para aprovação. Se faltar o início ou o fim da hora extra, adicione o outro registro.', 'ok');
    } catch (x) { er.textContent = x.message; er.classList.remove('hidden'); } finally { t.disabled = false; }
  });

  $('btn-sair').onclick = async function () { await api('ponto_logout', {}); location.href = 'index.html'; };

  /* ---------------- Apuração de Batida (gestor) ---------------- */
  function semAcento(t) { return String(t || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase(); }
  var ap = { lista: [], pend: [] };
  async function carregarPend() {
    var j = await api('ponto_admin_pendencias');
    ap.pend = j.sucesso ? j.itens : [];
    var cont = {}; ap.pend.forEach(function (i) { cont[i.nome] = (cont[i.nome] || 0) + 1; });
    var nomes = Object.keys(cont).sort();
    $('ap-fila').innerHTML = nomes.length ? '<span class="mudo">Aguardando aprovação:</span> ' + nomes.map(function (n) {
      return '<button type="button" class="mini" data-pick="' + esc(n) + '">' + esc(n) + ' <b class="nb">' + cont[n] + '</b></button>';
    }).join(' ') : '<span class="mudo">Nenhuma solicitação aguardando aprovação.</span>';
    return cont;
  }
  async function painelPendentes() {
    await carregarPend();
    var l = ap.pend.filter(function (i) { return i.nome === est.colab; }), box = $('pend-painel');
    if (!l.length) { box.classList.add('hidden'); box.innerHTML = ''; return; }
    box.classList.remove('hidden');
    var pode = l.filter(function (i) { return !i.propria; });
    box.innerHTML = '<div class="pp-tit"><b>Aguardando aprovação (' + l.length + ')</b>' +
      (pode.length > 1 ? '<span><button type="button" class="mini ok" data-pp="aprovar-todas">Aprovar todas</button> <button type="button" class="mini ruim" data-pp="recusar-todas">Recusar todas</button></span>' : '') + '</div>' +
      l.map(function (i) {
        var rem = i.solic === 'remocao', ate = i.solic === 'atestado' || i.solic === 'extra' || i.solic === 'folga_banco', dt = i.data.split('-');
        return '<div class="pp-item"><div class="pp-info"><span class="chip ' + (ate ? 'pend' : rem ? 'bad' : 'ok') + '">' + (i.solic === 'folga_banco' ? 'Folga (banco)' : i.solic === 'extra' ? 'Hora extra' : ate ? 'Abono' : rem ? 'Remoção' : 'Inclusão') + '</span> ' +
          '<b>' + dt[2] + '/' + dt[1] + '/' + dt[0] + ' · ' + esc(i.rotulo) + ' · ' + esc(i.hora) + '</b>' +
          '<div class="mudo">' + (i.solic === 'extra' ? vpLinks(i) : esc(i.justificativa || '')) + (ate && i.tem_anexo ? ' <a href="api_ponto.php?action=ponto_atestado_arquivo&id=' + i.id + '" target="_blank" rel="noopener" style="color:#9fc0f5">📎 Ver anexo</a>' : '') + '</div></div>' +
          (i.propria ? '<span class="mudo">Pedido seu — outro gestor decide</span>' :
            '<div class="pp-acoes"><button type="button" class="mini ok" data-pp="aprovar" data-id="' + i.id + '" data-solic="' + i.solic + '">Aprovar</button> ' +
            '<button type="button" class="mini ruim" data-pp="recusar" data-id="' + i.id + '" data-solic="' + i.solic + '">Recusar</button></div>') + '</div>';
      }).join('');
  }
  async function decidir(i, dec, motivo) { return api('ponto_admin_decidir', { id: +i.id, solic: i.solic, decisao: dec, motivo: motivo || '' }); }
  if (MODO) {
    $('pend-painel').addEventListener('click', async function (e) {
      var t = e.target.closest('button[data-pp]'); if (!t) return;
      var acao = t.getAttribute('data-pp'), alvo, dec, motivo = '', msg;
      var meus = ap.pend.filter(function (i) { return i.nome === est.colab && !i.propria; });
      if (acao === 'aprovar-todas' || acao === 'recusar-todas') { alvo = meus; dec = acao === 'aprovar-todas' ? 'aprovar' : 'recusar'; }
      else { alvo = [{ id: t.getAttribute('data-id'), solic: t.getAttribute('data-solic') }]; dec = acao; }
      if (dec === 'recusar') { motivo = prompt('Motivo da recusa (obrigatório, mínimo 5 caracteres):') || ''; if (motivo.trim().length < 5) return aviso('Informe o motivo da recusa.', 'erro'); }
      else if (alvo.length > 1 && !confirm('Aprovar ' + alvo.length + ' solicitações de ' + est.colab + '?')) return;
      t.disabled = true; var ok = 0, falha = null;
      for (var k = 0; k < alvo.length; k++) { try { var r = await decidir(alvo[k], dec, motivo); if (r.sucesso) ok++; else falha = r.erro; } catch (x) { falha = x.message; } }
      aviso(falha ? ok + ' decidida(s); erro: ' + falha : (dec === 'aprovar' ? ok + ' solicitação(ões) aprovada(s).' : ok + ' solicitação(ões) recusada(s).'), falha ? 'erro' : 'ok');
      await carregar(est.folha.ref);
    });
    $('ap-fila').addEventListener('click', function (e) { var t = e.target.closest('button[data-pick]'); if (t) escolherColab(t.getAttribute('data-pick')); });
  }
  /* Notificar o colaborador por e-mail com as datas da folha que precisam ser regularizadas */
  function botaoNotificar() {
    var b = document.createElement('button'); b.id = 'ap-notif'; b.type = 'button'; b.className = 'mini'; b.style.marginLeft = '10px';
    b.textContent = '✉ Notificar por e-mail'; b.title = 'Envia ao colaborador um e-mail com as datas desta folha que precisam ser regularizadas';
    b.onclick = notificarColab; $('ap-sel').appendChild(b);
  }
  async function notificarColab() {
    if (!est.colab || !est.folha) return;
    var b = $('ap-notif'); b.disabled = true;
    try {
      var p = await api('ponto_admin_notificar', { nome: est.colab, ref: est.folha.ref, previa: true });
      if (!p.sucesso) return aviso(p.erro, 'erro');
      if (!p.datas.length) return aviso('Nenhuma data a regularizar na folha de ' + est.colab + '. Nada foi enviado.', 'ok');
      if (!p.smtp) return aviso('O servidor de e-mail (SMTP) ainda não foi configurado. Configure em Escala → Configurações → E-mail (SMTP).', 'erro');
      if (!p.email) return aviso(est.colab + ' não tem e-mail cadastrado. Cadastre em Administração → Colaboradores.', 'erro');
      if (!confirm('Enviar e-mail para ' + est.colab + ' (' + p.email + ') com ' + p.datas.length + ' data(s) a regularizar?\n\n' + p.datas.join(', '))) return;
      var r = await api('ponto_admin_notificar', { nome: est.colab, ref: est.folha.ref });
      if (!r.sucesso) return aviso(r.erro, 'erro');
      aviso('E-mail enviado para ' + p.email + ' com ' + r.datas + ' data(s) a regularizar.', 'ok');
    } catch (e) { aviso(e.message, 'erro'); } finally { b.disabled = false; }
  }
  async function escolherColab(nome) {
    est.colab = nome; $('ap-colab').value = nome; $('ap-lista').classList.add('hidden');
    try { history.replaceState(null, '', '?colab=' + encodeURIComponent(nome)); } catch (e) {}
    $('ap-sel').textContent = 'Folha de ' + nome; botaoNotificar();
    $('conteudo').classList.remove('hidden'); aviso('');
    est.folha = null; await carregar();
  }
  async function iniciarApuracao() {
    /* APURACAO_FIX_V1 */
    var j = {};
    try { j = await api('ponto_admin_colaboradores'); } catch (e) { aviso('Não foi possível carregar a lista de colaboradores: ' + e.message, 'erro'); }
    if (j && j.sucesso === false && j.erro) aviso(j.erro, 'erro');
    ap.lista = (j && j.sucesso) ? j.itens : [];
    var cont = {};
    try { cont = await carregarPend(); } catch (e) { $('ap-fila').innerHTML = ''; }
    var inp = $('ap-colab'), box = $('ap-lista'), idx = -1;
    function mostrar() {
      var q = semAcento(inp.value.trim()); idx = -1;
      var l = ap.lista.filter(function (c) { return !q || semAcento(c.nome).indexOf(q) >= 0 || semAcento(c.nome_completo).indexOf(q) >= 0; }).slice(0, 60);
      box.innerHTML = l.length ? l.map(function (c) {
        return '<button type="button" data-n="' + esc(c.nome) + '"><span>' + esc(c.nome) + (c.setor ? ' <small>' + esc(c.setor) + '</small>' : '') + '</span>' + (cont[c.nome] ? '<b class="nb">' + cont[c.nome] + '</b>' : '') + '</button>';
      }).join('') : '<div class="vz">Nenhum colaborador encontrado.</div>';
      box.classList.remove('hidden');
      Array.prototype.forEach.call(box.querySelectorAll('[data-n]'), function (b) { b.onmousedown = function (ev) { ev.preventDefault(); escolherColab(b.getAttribute('data-n')); }; });
    }
    inp.addEventListener('focus', function () { inp.select(); mostrar(); });
    inp.addEventListener('input', mostrar);
    inp.addEventListener('blur', function () { setTimeout(function () { box.classList.add('hidden'); }, 120); });
    inp.addEventListener('keydown', function (ev) {
      var bs = box.querySelectorAll('[data-n]');
      if (ev.key === 'ArrowDown' || ev.key === 'ArrowUp') { ev.preventDefault(); if (box.classList.contains('hidden')) mostrar(); bs = box.querySelectorAll('[data-n]'); if (!bs.length) return;
        idx = (idx + (ev.key === 'ArrowDown' ? 1 : -1) + bs.length) % bs.length; Array.prototype.forEach.call(bs, function (b, i) { b.classList.toggle('on', i === idx); }); bs[idx].scrollIntoView({ block: 'nearest' }); }
      else if (ev.key === 'Enter') { var alvo = idx >= 0 ? bs[idx] : (bs.length === 1 ? bs[0] : null); if (alvo) { ev.preventDefault(); escolherColab(alvo.getAttribute('data-n')); } }
      else if (ev.key === 'Escape') box.classList.add('hidden');
    });
    var pre = new URLSearchParams(location.search).get('colab');
    if (pre && ap.lista.some(function (c) { return c.nome === pre; })) await escolherColab(pre);
  }

  (async function () {
    try {
      var me = await api('ponto_me');
      if (!me.sucesso) { location.href = 'index.html'; return; }
      est.gestor = !!me.gestor;
      if (window.ptNav) ptNav.aplicar(me);
      if (MODO) { if (!me.gestor) { location.href = 'folha.html'; return; } await iniciarApuracao(); return; }
      if (!me.vinculado) { aviso('Seu login não está vinculado a um colaborador da escala, então não há folha de ponto para mostrar.', 'erro'); return; }
      await carregar();
    } catch (e) { aviso(e.message, 'erro'); }
  })();
})();
