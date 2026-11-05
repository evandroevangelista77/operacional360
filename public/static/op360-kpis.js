/* Aba "KPI's" do Operacional 360: indicadores executivos (SLA, volumetria, tempos, alertas, responsáveis,
   chamados mais longos e pendências) e relatório em PDF (paisagem) pela impressão do navegador. */
(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const nf = new Intl.NumberFormat('pt-BR');
  const nf1 = new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 });
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const SEV = ['Não classificada', 'Informação', 'Atenção', 'Média', 'Alta', 'Desastre'];
  const dur = (sec) => {
    if (sec == null || Number.isNaN(sec)) return '—';
    sec = Math.max(0, Math.round(sec));
    const d = Math.floor(sec / 86400), h = Math.floor((sec % 86400) / 3600), m = Math.floor((sec % 3600) / 60);
    if (d) return `${d}d ${h}h`;
    if (h) return `${h}h ${String(m).padStart(2, '0')}m`;
    if (m) return `${m}m`;
    return `${sec}s`;
  };
  const pctTxt = (v) => (v == null ? '—' : `${nf1.format(v)}%`);
  const slaColor = (p) => (p == null ? 'var(--muted)' : p >= 95 ? 'var(--good)' : p >= 85 ? 'var(--warning)' : 'var(--critical, #ef4444)');
  const dayLbl = (d) => `${d.slice(8, 10)}/${d.slice(5, 7)}`;
  const fdate = (s) => (s ? `${s.slice(8, 10)}/${s.slice(5, 7)}/${s.slice(0, 4)}` : '');

  let last = null;

  /* ---------------- componentes */
  const tile = (label, value, sub, color, icon) => `
    <div class="kt" style="--c:${color}"><span class="kt-l">${esc(label)}</span><b class="kt-v">${value}</b><span class="kt-s">${sub}</span></div>`;

  /** Rosca de percentual (SLA). */
  function donut(pct, color, center, sub) {
    const r = 54, c = 2 * Math.PI * r, p = Math.max(0, Math.min(100, pct ?? 0));
    return `<svg viewBox="0 0 140 140" class="donut" role="img" aria-label="${esc(center)}">
      <circle cx="70" cy="70" r="${r}" fill="none" stroke="var(--grid)" stroke-width="14"/>
      <circle cx="70" cy="70" r="${r}" fill="none" stroke="${color}" stroke-width="14" stroke-linecap="round"
        stroke-dasharray="${(c * p / 100).toFixed(1)} ${c.toFixed(1)}" transform="rotate(-90 70 70)"/>
      <text x="70" y="68" text-anchor="middle" class="dn-v">${esc(center)}</text>
      <text x="70" y="88" text-anchor="middle" class="dn-s">${esc(sub)}</text></svg>`;
  }

  /** Barras horizontais: rows = [{name, value, label?, color?}]. */
  function hbars(rows, { max, color = 'var(--series-1)', empty = 'Sem dados no período.' } = {}) {
    if (!rows.length) return `<div class="k-empty">${empty}</div>`;
    const m = max ?? Math.max(...rows.map((r) => r.value), 1);
    return `<div class="hb">${rows.map((r) => `
      <div class="hb-r"><span class="hb-n" title="${esc(r.name)}">${esc(r.name)}</span>
        <span class="hb-t"><i style="width:${Math.max(2, (r.value / m) * 100).toFixed(1)}%;background:${r.color || color}"></i></span>
        <b class="hb-v">${esc(r.label ?? nf.format(r.value))}</b></div>`).join('')}</div>`;
  }

  /** Colunas por dia (chamados) com linha de alertas. */
  function dayChart(tl) {
    if (!tl.length) return '<div class="k-empty">Sem dados no período.</div>';
    const W = 640, H = 210, L = 34, B = 24, T = 12, R = 8, iw = W - L - R, ih = H - T - B;
    const max = Math.max(1, ...tl.map((d) => Math.max(d.tickets, d.alerts)));
    const nice = (() => { const p = Math.pow(10, Math.floor(Math.log10(max))); return Math.ceil(max / p) * p; })();
    const bw = iw / tl.length, x = (i) => L + i * bw + bw / 2, y = (v) => T + ih - (v / nice) * ih;
    const grid = [0, 0.5, 1].map((f) => `<line x1="${L}" x2="${W - R}" y1="${y(nice * f)}" y2="${y(nice * f)}" class="gl"/><text x="${L - 6}" y="${y(nice * f) + 4}" text-anchor="end" class="ax">${nf.format(Math.round(nice * f))}</text>`).join('');
    const bars = tl.map((d, i) => `<rect x="${(x(i) - Math.max(1, bw * 0.34)).toFixed(1)}" y="${y(d.tickets).toFixed(1)}" width="${Math.max(2, bw * 0.68).toFixed(1)}" height="${(ih - (y(d.tickets) - T)).toFixed(1)}" rx="2" fill="var(--series-2)"><title>${dayLbl(d.day)}: ${d.tickets} chamados</title></rect>`).join('');
    const line = tl.map((d, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(d.alerts).toFixed(1)}`).join('');
    const step = Math.ceil(tl.length / 12);
    const lbl = tl.map((d, i) => (i % step === 0 ? `<text x="${x(i)}" y="${H - 6}" text-anchor="middle" class="ax">${dayLbl(d.day)}</text>` : '')).join('');
    return `<svg viewBox="0 0 ${W} ${H}" class="kx-chart" role="img" aria-label="Chamados e alertas por dia">${grid}${bars}
      <path d="${line}" fill="none" stroke="var(--series-1)" stroke-width="2.4" stroke-linejoin="round"/>
      ${tl.length <= 45 ? tl.map((d, i) => `<circle cx="${x(i).toFixed(1)}" cy="${y(d.alerts).toFixed(1)}" r="2.6" fill="var(--series-1)"/>`).join('') : ''}${lbl}</svg>
      <div class="lg"><span><i style="background:var(--series-2)"></i>Chamados abertos</span><span><i style="background:var(--series-1)"></i>Alertas do Zabbix</span></div>`;
  }

  /** Linha da tendência do SLA (% por dia de solução) com a meta de 95%. */
  function slaLine(tr) {
    const pts = tr.filter((t) => t.pct != null);
    if (pts.length < 2) return '<div class="k-empty">Dados insuficientes para a tendência.</div>';
    const W = 1240, H = 200, L = 38, B = 24, T = 12, R = 8, iw = W - L - R, ih = H - T - B;
    const lo = Math.max(0, Math.floor(Math.min(...pts.map((p) => p.pct), 90) / 10) * 10);
    const x = (i) => L + (pts.length === 1 ? iw / 2 : (i / (pts.length - 1)) * iw), y = (v) => T + ih - ((v - lo) / (100 - lo)) * ih;
    const grid = [lo, (lo + 100) / 2, 100].map((v) => `<line x1="${L}" x2="${W - R}" y1="${y(v)}" y2="${y(v)}" class="gl"/><text x="${L - 6}" y="${y(v) + 4}" text-anchor="end" class="ax">${Math.round(v)}%</text>`).join('');
    const d = pts.map((p, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${y(p.pct).toFixed(1)}`).join('');
    const step = Math.ceil(pts.length / 10);
    return `<svg viewBox="0 0 ${W} ${H}" class="kx-chart" role="img" aria-label="Tendência do SLA">${grid}
      <line x1="${L}" x2="${W - R}" y1="${y(95)}" y2="${y(95)}" stroke="var(--good)" stroke-dasharray="5 4" stroke-width="1.4"/>
      <text x="${W - R}" y="${y(95) - 4}" text-anchor="end" class="ax" fill="var(--good)">meta 95%</text>
      <path d="${d}" fill="none" stroke="var(--series-1)" stroke-width="2.4" stroke-linejoin="round"/>
      ${pts.map((p, i) => `<circle cx="${x(i).toFixed(1)}" cy="${y(p.pct).toFixed(1)}" r="3" fill="${slaColor(p.pct)}"><title>${dayLbl(p.day)}: ${pctTxt(p.pct)} (${p.n} chamados)</title></circle>`).join('')}
      ${pts.map((p, i) => (i % step === 0 ? `<text x="${x(i)}" y="${H - 6}" text-anchor="middle" class="ax">${dayLbl(p.day)}</text>` : '')).join('')}</svg>`;
  }

  /** Colunas simples (faixas de tempo de resolução). */
  function columns(rows) {
    const W = 520, H = 190, L = 30, B = 34, T = 18, R = 8, iw = W - L - R, ih = H - T - B, max = Math.max(1, ...rows.map((r) => r.n));
    const bw = iw / rows.length;
    return `<svg viewBox="0 0 ${W} ${H}" class="kx-chart" role="img" aria-label="Tempo de resolução por faixa">
      <line x1="${L}" x2="${W - R}" y1="${T + ih}" y2="${T + ih}" class="gl"/>
      ${rows.map((r, i) => { const h = (r.n / max) * ih, cx = L + i * bw + bw / 2; return `<rect x="${(cx - bw * 0.3).toFixed(1)}" y="${(T + ih - h).toFixed(1)}" width="${(bw * 0.6).toFixed(1)}" height="${h.toFixed(1)}" rx="3" fill="var(--accent-text)"/>
        <text x="${cx}" y="${(T + ih - h - 5).toFixed(1)}" text-anchor="middle" class="ax b">${nf.format(r.n)}</text>
        <text x="${cx}" y="${H - 12}" text-anchor="middle" class="ax">${esc(r.label)}</text>`; }).join('')}</svg>`;
  }

  /** Tabela de chamados (mais longos / mais pendentes). */
  function ticketTable(rows, link, unit) {
    if (!rows.length) return '<div class="k-empty">Nenhum chamado no período.</div>';
    return `<table class="kt-tbl"><thead><tr><th>Nº</th><th>Título</th><th>Cliente</th><th>Responsável</th><th class="num">${unit}</th></tr></thead><tbody>${rows.map((t) => `
      <tr><td><a href="${esc(link)}/front/ticket.form.php?id=${t.id}" target="_blank" rel="noopener">#${t.id}</a></td>
        <td class="ell" title="${esc(t.name)}">${esc(t.name)}</td><td class="ell">${esc(t.client)}</td><td class="ell">${esc(t.tech)}</td>
        <td class="num"><b>${dur(t.seconds)}</b>${t.open ? ' <em>em aberto</em>' : ''}</td></tr>`).join('')}</tbody></table>`;
  }

  /* ---------------- relatório */
  function render(d) {
    const host = $('#kpiBody');
    if (!d || !d.exec) { host.innerHTML = '<div class="k-empty">Carregando indicadores…</div>'; return; }
    const k = d.kpi, e = d.exec, f = d.filters;
    const links = (d.links && d.links.glpi) || '';
    const clients = f.clients && f.clients.length ? f.clients.join(', ') : 'Todos os clientes';
    const inUse = (d.groups_in_use || []).length;
    const gsel = f.groups && f.groups.length ? `${f.groups.length} host group(s) escolhido(s)` : `${inUse} host groups (automático)`;
    $('#kpiMeta').innerHTML = `<b>Período:</b> ${fdate(f.from)} a ${fdate(f.to)} · <b>Clientes:</b> ${esc(clients)} · <b>Zabbix:</b> ${esc(gsel)} · <b>Gerado em:</b> ${new Date(d.generated_at * 1000).toLocaleString('pt-BR')}`;
    const sevs = (d.severities || []).slice().sort((a, b) => b - a);
    const sevRows = sevs.map((s) => ({ name: SEV[s], value: k.alerts_by_sev[s] || 0, color: `var(--sev-${s})` }));
    const sl = e.sla;
    const prioRows = sl.by_priority.map((p) => ({ name: `${p.priority} (${p.n})`, value: p.pct ?? 0, label: pctTxt(p.pct), color: slaColor(p.pct) }));
    const cliSla = sl.by_client.map((c) => ({ name: `${c.name} (${c.n})`, value: c.pct ?? 0, label: pctTxt(c.pct), color: slaColor(c.pct) }));
    const techRows = e.techs;
    const maxT = Math.max(1, ...techRows.map((t) => t.tickets));
    const pend = e.pending;

    // ---- resumo executivo: leitura automática dos números (destaques e pontos de atenção)
    const bullets = [];
    const first = (arr) => (arr && arr.length ? arr[0] : null);
    if (sl.pct != null) bullets.push(`<b>SLA de ${pctTxt(sl.pct)}</b> (${nf.format(sl.ok)} de ${nf.format(sl.n)} chamados resolvidos no prazo) — ${sl.pct >= 95 ? 'dentro da meta de 95%' : `${nf1.format(95 - sl.pct)} p.p. abaixo da meta de 95%`}.`);
    bullets.push(`<b>${nf.format(k.tickets)} chamados</b> abertos no período; <b>${nf.format(e.volume.resolved)}</b> já resolvidos e <b>${nf.format(k.backlog)}</b> em aberto agora${k.overdue ? `, dos quais <b>${nf.format(k.overdue)}</b> com o prazo vencido` : ''}.`);
    const topCli = first(e.volume.by_client);
    if (topCli) bullets.push(`Maior volume de chamados: <b>${esc(topCli.name)}</b> (${nf.format(topCli.n)}, ${nf1.format(topCli.n * 100 / Math.max(1, k.tickets))}% do total).`);
    const worstCli = sl.by_client.filter((c) => c.n >= 3 && c.pct != null).sort((x, y) => x.pct - y.pct)[0];
    if (worstCli && worstCli.pct < 95) bullets.push(`Menor SLA entre os clientes: <b>${esc(worstCli.name)}</b> com ${pctTxt(worstCli.pct)} (${nf.format(worstCli.n)} chamados).`);
    bullets.push(`<b>TME ${dur(k.mttd_glpi)}</b> (primeiro atendimento) e <b>TMA ${dur(k.mttr_glpi)}</b> (resolução) nos chamados do GLPI.`);
    bullets.push(`<b>${nf.format(k.alerts)} alertas</b> no Zabbix no período; ${nf.format(k.active)} ativos agora, ${nf.format(k.unacked)} sem reconhecimento. <b>MTTD ${dur(k.mttd_zbx)}</b> e <b>MTTR ${dur(k.mttr_zbx)}</b>.`);
    const topTech = e.techs.filter((t) => t.name !== 'Sem responsável')[0];
    if (topTech) bullets.push(`Responsável com mais chamados: <b>${esc(topTech.name)}</b> (${nf.format(topTech.tickets)}; SLA ${pctTxt(topTech.sla_pct)}; TMA ${dur(topTech.tma)}).`);
    const longest = first(e.longest);
    if (longest) bullets.push(`Chamado que mais durou: <b>#${longest.id}</b> (${esc(longest.client)}) com <b>${dur(longest.seconds)}</b>${longest.open ? ', ainda em aberto' : ''}.`);
    const waitTech = first(pend.by_tech);
    if (waitTech) bullets.push(`Maior tempo pendente com <b>${esc(waitTech.name)}</b>: ${dur(waitTech.wait_seconds)} em ${nf.format(waitTech.wait_tickets)} chamado(s); total pendente no período ${dur(pend.total_seconds)}.`);
    const atencao = [];
    if (sl.pct != null && sl.pct < 95) atencao.push('SLA abaixo da meta');
    if (k.overdue) atencao.push(`${nf.format(k.overdue)} chamado(s) vencido(s)`);
    if (k.unacked) atencao.push(`${nf.format(k.unacked)} alerta(s) ativo(s) sem ACK`);
    if (e.techs.some((t) => t.name === 'Sem responsável' && t.open)) atencao.push('chamados em aberto sem responsável');
    const sevMax = sevs.length ? sevs[0] : null;
    if (sevMax != null && (k.active_by_sev[sevMax] || 0) > 0) atencao.push(`${nf.format(k.active_by_sev[sevMax])} alerta(s) ${SEV[sevMax]} ativo(s)`);

    // alertas: hosts e clientes
    const hostRows = (d.top_hosts || []).slice(0, 8).map((h) => ({ name: h.host, value: h.alerts, color: 'var(--series-1)' }));
    const alCli = (d.clients || []).filter((c) => c.alerts).sort((x, y) => y.alerts - x.alerts).slice(0, 8).map((c) => ({ name: c.name, value: c.alerts, color: 'var(--series-1)' }));
    const N = 5;
    const foot = (n) => `<footer class="kfooter"><span>OAKMONT GROUP · Documento confidencial · ${esc(clients)} · ${fdate(f.from)} a ${fdate(f.to)}</span><span>Página ${n} de ${N}</span></footer>`;
    const head = (t) => `<header class="kphead"><i class="kp-logo" role="img" aria-label="OAKMONT Group"></i><div><b>${esc(t)}</b><span>${esc(clients)} · ${fdate(f.from)} a ${fdate(f.to)}</span></div></header>`;
    const cover = `<section class="kcover" aria-hidden="true">
      <i class="kp-logo kc-logo"></i>
      <div class="kc-main">
        <span class="kc-tag">Relatório de entrega de serviços</span>
        <h1>Indicadores de Operação<br>e Nível de Serviço</h1>
        <p class="kc-sub">SLA · Volumetria · Tempos de atendimento e resolução · Monitoramento e alertas · Equipe responsável</p>
        <dl class="kc-meta">
          <div><dt>Cliente(s)</dt><dd>${esc(clients)}</dd></div>
          <div><dt>Período</dt><dd>${fdate(f.from)} a ${fdate(f.to)}</dd></div>
          <div><dt>Emitido em</dt><dd>${new Date(d.generated_at * 1000).toLocaleDateString('pt-BR')}</dd></div>
        </dl>
      </div>
      <ol class="kc-index"><li>Resumo executivo</li><li>SLA e tempo de resolução</li><li>Volumetria e alertas</li><li>Equipe, chamados mais longos e pendências</li></ol>
      <div class="kc-foot"><span>OAKMONT GROUP · Documento confidencial</span><span>Fonte: GLPI (chamados) e Zabbix (monitoramento) · Operacional 360</span></div>
    </section>`;

    host.innerHTML = cover + `
    <section class="kpage">
      ${head('Resumo executivo')}
      <div class="kx-tiles">
        ${tile('SLA cumprido', pctTxt(sl.pct), `${nf.format(sl.ok)} de ${nf.format(sl.n)} resolvidos no prazo`, slaColor(sl.pct))}
        ${tile('Chamados no período', nf.format(k.tickets), `${nf.format(e.volume.resolved)} resolvidos · ${nf.format(k.backlog)} em aberto`, 'var(--series-2)')}
        ${tile('TME (1º atendimento)', dur(k.mttd_glpi), `${nf.format(k.mttd_glpi_n)} chamados`, 'var(--accent-text)')}
        ${tile('TMA (resolução)', dur(k.mttr_glpi), `${nf.format(k.mttr_glpi_n)} chamados`, 'var(--accent-text)')}
        ${tile('Alertas Zabbix', nf.format(k.alerts), `${nf.format(k.active)} ativos agora · ${nf.format(k.unacked)} sem ACK`, 'var(--series-1)')}
        ${tile('MTTD Zabbix', dur(k.mttd_zbx), `${nf.format(k.mttd_zbx_n)} alertas · até o ACK`, 'var(--series-1)')}
        ${tile('MTTR Zabbix', dur(k.mttr_zbx), `${nf.format(k.mttr_zbx_n)} alertas · até a normalização`, 'var(--series-1)')}
        ${tile('Pendente (GLPI)', dur(pend.total_seconds), `${nf.format(pend.now)} pendentes agora · ${nf.format(k.overdue)} atrasados`, 'var(--warning)')}
      </div>
      <div class="kgrid">
        <article class="kcard k8"><h3>Resumo executivo</h3><ul class="kbul">${bullets.map((b) => `<li>${b}</li>`).join('')}</ul>
          ${atencao.length ? `<p class="katt"><b>Pontos de atenção:</b> ${atencao.join(' · ')}.</p>` : '<p class="katt ok"><b>Sem pontos críticos</b> nos indicadores do período.</p>'}</article>
        <article class="kcard k4"><h3>SLA geral</h3><div class="kdonut">${donut(sl.pct, slaColor(sl.pct), pctTxt(sl.pct), 'meta 95%')}</div>
          <p class="kfoot">${nf.format(sl.open_late)} chamado(s) em aberto já estouraram o prazo.</p></article>
      </div>
      ${foot(5)}
    </section>

    <section class="kpage">
      ${head('SLA e tempo de resolução')}
      <div class="kgrid">
        <article class="kcard k6"><h3>SLA por prioridade <small>chamados resolvidos</small></h3>${hbars(prioRows, { max: 100 })}</article>
        <article class="kcard k6"><h3>SLA por cliente <small>10 maiores volumes</small></h3>${hbars(cliSla, { max: 100 })}</article>
        <article class="kcard k12"><h3>Tendência do SLA <small>por dia de solução · meta de 95%</small></h3>${slaLine(sl.trend)}</article>
        <article class="kcard k12"><h3>Tempo de resolução <small>chamados resolvidos, por faixa · TMA ${dur(k.mttr_glpi)} · TME ${dur(k.mttd_glpi)}</small></h3>${columns(e.resolution.distribution)}</article>
      </div>
      ${foot(5)}
    </section>

    <section class="kpage">
      ${head('Volumetria e alertas')}
      <div class="kgrid">
        <article class="kcard k8"><h3>Volumetria <small>chamados abertos × alertas por dia</small></h3>${dayChart(d.timeline)}</article>
        <article class="kcard k4"><h3>Alertas por severidade <small>(Zabbix)</small></h3>${hbars(sevRows, { empty: 'Sem alertas no período.' })}
          <p class="kfoot">Total no período: <b>${nf.format(k.alerts)}</b></p></article>
        <article class="kcard k4"><h3>Chamados por cliente</h3>${hbars(e.volume.by_client.map((c) => ({ name: c.name, value: c.n })), { color: 'var(--series-2)' })}</article>
        <article class="kcard k4"><h3>Chamados por categoria</h3>${hbars(e.volume.by_category.map((c) => ({ name: c.name, value: c.n })), { color: 'var(--series-2)' })}
          <h3 class="sub">Por prioridade</h3>${hbars(e.volume.by_priority.map((c) => ({ name: c.name, value: c.n })), { color: 'var(--series-2)' })}</article>
        <article class="kcard k4"><h3>Alertas por cliente</h3>${hbars(alCli, { empty: 'Sem alertas no período.' })}
          <h3 class="sub">Hosts com mais alertas</h3>${hbars(hostRows, { empty: 'Sem alertas no período.' })}</article>
      </div>
      ${foot(5)}
    </section>

    <section class="kpage">
      ${head('Equipe, chamados mais longos e pendências')}
      <div class="kgrid">
        <article class="kcard k12"><h3>Técnicos responsáveis <small>chamados do período + em aberto</small></h3>
          ${techRows.length ? `<table class="kt-tbl tech"><thead><tr><th>Responsável</th><th>Volume</th><th class="num">Resolvidos</th><th class="num">Em aberto</th><th class="num">SLA</th><th class="num">TMA</th><th class="num">TME</th><th class="num">Pendente</th></tr></thead><tbody>
            ${techRows.slice(0, 6).map((t) => `<tr><td class="ell" title="${esc(t.name)}">${esc(t.name)}</td>
              <td><span class="hb-t"><i style="width:${Math.max(3, t.tickets / maxT * 100).toFixed(1)}%;background:var(--series-2)"></i></span><b class="mini">${nf.format(t.tickets)}</b></td>
              <td class="num">${nf.format(t.resolved)}</td><td class="num">${nf.format(t.open)}</td>
              <td class="num" style="color:${slaColor(t.sla_pct)}"><b>${pctTxt(t.sla_pct)}</b></td><td class="num">${dur(t.tma)}</td><td class="num">${dur(t.tme)}</td>
              <td class="num">${t.wait_seconds ? dur(t.wait_seconds) : '—'}</td></tr>`).join('')}</tbody></table>` : '<div class="k-empty">Sem chamados no período.</div>'}</article>
        <article class="kcard k6"><h3>Chamados que mais duraram</h3>${ticketTable(e.longest.slice(0, 4), links, 'Duração')}</article>
        <article class="kcard k6"><h3>Chamados que mais ficaram pendentes</h3>${ticketTable(pend.top_tickets.slice(0, 4), links, 'Pendente')}</article>
        <article class="kcard k12"><h3>Com quem ficou mais tempo pendente <small>soma do tempo de espera por responsável</small></h3>
          ${hbars(pend.by_tech.slice(0, 5).map((t) => ({ name: `${t.name} (${t.wait_tickets} chamados)`, value: t.wait_seconds, label: dur(t.wait_seconds) })), { color: 'var(--warning)', empty: 'Nenhum chamado ficou pendente no período.' })}
          <p class="kfoot">Total pendente: <b>${dur(pend.total_seconds)}</b> · ${nf.format(pend.now)} pendente(s) agora.</p></article>
      </div>
      ${foot(5)}
    </section>`;
  }

  /* ---------------- abas */
  function setView(v) {
    document.body.classList.toggle('view-kpi', v === 'kpi');
    $$('#viewTabs button').forEach((b) => { const on = b.dataset.view === v; b.classList.toggle('on', on); b.setAttribute('aria-selected', String(on)); });
    $('#kpiView').hidden = v !== 'kpi';
    if (v === 'kpi') {
      // os indicadores são mensais: ao abrir, sem período escolhido (padrão de 7 dias), vai para o mês atual
      const cur = $('.presets button.on');
      if (cur && cur.dataset.preset === '7' && !window.__kpiMonthSet) { window.__kpiMonthSet = true; const m = $('.presets button[data-preset="month"]'); if (m) m.click(); }
      render(last);
    }
  }
  $$('#viewTabs button').forEach((b) => b.addEventListener('click', () => setView(b.dataset.view)));
  document.addEventListener('op360:data', (ev) => { last = ev.detail; if (document.body.classList.contains('view-kpi')) render(last); });
  /* ---------------- PDF (gerado no servidor, sempre em paisagem) e assinatura */
  const OPC = () => window.OP360 || {};
  const filterQs = () => {
    const f = (last && last.filters) || {};
    const q = new URLSearchParams({ from: f.from || '', to: f.to || '' });
    if (f.clients && f.clients.length) q.set('client', f.clients.join('|'));
    if (f.groups && f.groups.length) q.set('group', f.groups.join('|'));
    return q;
  };
  const call = async (action, extra = {}) => {
    const q = filterQs(); q.set('action', action); q.set('t', OPC().t || '');
    Object.entries(extra).forEach(([k, v]) => q.set(k, v));
    const r = await fetch(`${OPC().api || '/api'}?${q}`, { cache: 'no-store' });
    const j = await r.json().catch(() => ({}));
    if (!r.ok || j.error) throw new Error(j.error || `HTTP ${r.status}`);
    return j;
  };
  $('#kpiPdf').addEventListener('click', () => {
    if (!last) return;
    const a = document.createElement('a');
    a.href = `${OPC().pdf || '/pdf'}?${filterQs()}`;
    a.download = ''; document.body.appendChild(a); a.click(); a.remove();
  });
  const msg = (t, kind = '') => { const el = $('#kpiSignMsg'); el.className = `ks-msg ${kind}`; el.innerHTML = t; };
  const badge = (s) => ({ pending: 'warn', signed: 'warn', sent: 'ok', canceled: '' }[s] || '');
  async function loadSign() {
    try {
      const { items } = await call('sign_list');
      $('#kpiSignList').innerHTML = items.length ? `<table class="kt-tbl ks"><thead><tr><th>Relatório</th><th>E-mail do cliente</th><th>Situação</th><th></th></tr></thead><tbody>${items.map((r) => `
        <tr><td class="ell" title="${esc(r.title)}">${esc(r.clients)} · ${esc(r.period)}</td><td class="ell">${esc(r.client_email)}</td>
          <td><span class="pill ${badge(r.status)}">${esc(r.label)}</span>${r.error ? `<small class="err" title="${esc(r.error)}">${esc(r.error)}</small>` : ''}</td>
          <td class="act">${r.status === 'pending' ? `<a href="${esc(r.sign_url)}" target="_blank" rel="noopener">Abrir LibreSign</a><button type="button" data-act="sync" data-id="${r.id}">Verificar</button><button type="button" data-act="cancel" data-id="${r.id}">Cancelar</button>` : ''}
            ${r.status === 'signed' ? `<button type="button" data-act="resend" data-id="${r.id}">Reenviar e-mail</button>` : ''}</td></tr>`).join('')}</tbody></table>`
        : '<div class="k-empty">Nenhum relatório enviado para assinatura ainda.</div>';
      if (items.some((r) => r.status === 'pending') && !$('#kpiSign').hidden) { clearTimeout(loadSign.t); loadSign.t = setTimeout(loadSign, 30000); }
    } catch (err) { $('#kpiSignList').innerHTML = `<div class="k-empty">${esc(err.message)}</div>`; }
  }
  $('#kpiSignBtn').addEventListener('click', () => {
    const open = $('#kpiSign').hidden;
    $('#kpiSign').hidden = !open; $('#kpiSignBtn').setAttribute('aria-expanded', String(open));
    if (open) { loadSign(); $('#kpiClientMail').focus(); }
  });
  $('#kpiSignForm').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    if (!last) { msg('Aguarde os indicadores carregarem.', 'err'); return; }
    const email = $('#kpiClientMail').value.trim();
    $('#kpiSignGo').disabled = true; msg('Gerando o PDF e enviando ao LibreSign…');
    try {
      const r = await call('sign_send', { email });
      msg(`Enviado para assinatura. O LibreSign avisou o gestor em <b>${esc(r.signer_email)}</b> (o e-mail traz o link para assinar; também dá para <a href="${esc(r.sign_url)}" target="_blank" rel="noopener">abrir o LibreSign</a>). Assim que a assinatura for concluída, o PDF assinado segue para <b>${esc(email)}</b>.${r.warning ? `<br><small>${esc(r.warning)}</small>` : ''}`, 'ok');
      $('#kpiClientMail').value = '';
      loadSign();
    } catch (err) { msg(esc(err.message), 'err'); } finally { $('#kpiSignGo').disabled = false; }
  });
  $('#kpiSignList').addEventListener('click', async (ev) => {
    const b = ev.target.closest('button[data-act]'); if (!b) return;
    b.disabled = true;
    try {
      const r = await call(`sign_${b.dataset.act}`, { id: b.dataset.id });
      if (b.dataset.act === 'sync') msg(r.status === 'sent' ? 'Assinado e enviado ao cliente.' : r.status === 'signed' ? `Assinado, mas o e-mail não saiu: ${esc(r.error || '')}` : 'Ainda aguardando a assinatura do gestor.', r.status === 'sent' ? 'ok' : '');
      if (b.dataset.act === 'resend') msg(r.status === 'sent' ? 'E-mail enviado ao cliente.' : `E-mail não enviado: ${esc(r.error || '')}`, r.status === 'sent' ? 'ok' : 'err');
    } catch (err) { msg(esc(err.message), 'err'); }
    loadSign();
  });
  if (new URLSearchParams(location.search).get('view') === 'kpi') setView('kpi');
})();
