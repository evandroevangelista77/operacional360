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

    host.innerHTML = `
    <section class="kpage">
      <div class="kx-tiles">
        ${tile('SLA cumprido', pctTxt(sl.pct), `${nf.format(sl.ok)} de ${nf.format(sl.n)} resolvidos no prazo`, slaColor(sl.pct))}
        ${tile('Chamados no período', nf.format(k.tickets), `${nf.format(e.volume.resolved)} resolvidos · ${nf.format(k.backlog)} em aberto`, 'var(--series-2)')}
        ${tile('TME (1º atendimento)', dur(k.mttd_glpi), `${nf.format(k.mttd_glpi_n)} chamados`, 'var(--accent-text)')}
        ${tile('TMA (resolução)', dur(k.mttr_glpi), `${nf.format(k.mttr_glpi_n)} chamados`, 'var(--accent-text)')}
        ${tile('Alertas Zabbix', nf.format(k.alerts), `${nf.format(k.active)} ativos agora · ${nf.format(k.unacked)} sem ACK`, 'var(--series-1)')}
        ${tile('MTTD Zabbix', dur(k.mttd_zbx), `${nf.format(k.mttd_zbx_n)} alertas · até o ACK`, 'var(--series-1)')}
        ${tile('MTTR Zabbix', dur(k.mttr_zbx), `${nf.format(k.mttr_zbx_n)} alertas · até a normalização`, 'var(--series-1)')}
        ${tile('Pendente (GLPI)', dur(pend.total_seconds), `${nf.format(pend.now)} chamados pendentes agora · ${nf.format(k.overdue)} atrasados`, 'var(--warning)')}
      </div>
      <div class="kgrid">
        <article class="kcard k4"><h3>SLA geral</h3><div class="kdonut">${donut(sl.pct, slaColor(sl.pct), pctTxt(sl.pct), 'meta 95%')}</div>
          <p class="kfoot">${nf.format(sl.open_late)} chamado(s) em aberto já estouraram o prazo.</p></article>
        <article class="kcard k4"><h3>SLA por prioridade</h3>${hbars(prioRows, { max: 100 })}</article>
        <article class="kcard k4"><h3>SLA por cliente <small>(10 maiores)</small></h3>${hbars(cliSla, { max: 100 })}</article>
        <article class="kcard k12"><h3>Tendência do SLA <small>por dia de solução</small></h3>${slaLine(sl.trend)}</article>
      </div>
      <footer class="kfooter"><span>Operacional 360 · Relatório executivo de KPI's</span><span>Página 1 de 3</span></footer>
    </section>

    <section class="kpage">
      <div class="kgrid">
        <article class="kcard k8"><h3>Volumetria <small>chamados abertos × alertas por dia</small></h3>${dayChart(d.timeline)}</article>
        <article class="kcard k4"><h3>Alertas por severidade <small>(Zabbix)</small></h3>${hbars(sevRows, { empty: 'Sem alertas no período.' })}
          <p class="kfoot">Total no período: <b>${nf.format(k.alerts)}</b></p></article>
        <article class="kcard k4"><h3>Chamados por cliente</h3>${hbars(e.volume.by_client.map((c) => ({ name: c.name, value: c.n })), { color: 'var(--series-2)' })}</article>
        <article class="kcard k4"><h3>Chamados por categoria</h3>${hbars(e.volume.by_category.map((c) => ({ name: c.name, value: c.n })), { color: 'var(--series-2)' })}</article>
        <article class="kcard k4"><h3>Chamados por prioridade</h3>${hbars(e.volume.by_priority.map((c) => ({ name: c.name, value: c.n })), { color: 'var(--series-2)' })}</article>
        <article class="kcard k6"><h3>Tempo de resolução <small>chamados resolvidos, por faixa</small></h3>${columns(e.resolution.distribution)}
          <p class="kfoot">TMA: <b>${dur(k.mttr_glpi)}</b> · TME: <b>${dur(k.mttd_glpi)}</b></p></article>
        <article class="kcard k6"><h3>Chamados que mais duraram</h3>${ticketTable(e.longest.slice(0, 5), links, 'Duração')}</article>
      </div>
      <footer class="kfooter"><span>Operacional 360 · Relatório executivo de KPI's</span><span>Página 2 de 3</span></footer>
    </section>

    <section class="kpage">
      <div class="kgrid">
        <article class="kcard k12"><h3>Técnicos responsáveis <small>chamados do período + em aberto</small></h3>
          ${techRows.length ? `<table class="kt-tbl tech"><thead><tr><th>Responsável</th><th>Volume</th><th class="num">Resolvidos</th><th class="num">Em aberto</th><th class="num">SLA</th><th class="num">TMA</th><th class="num">TME</th><th class="num">Pendente</th></tr></thead><tbody>
            ${techRows.slice(0, 10).map((t) => `<tr><td class="ell" title="${esc(t.name)}">${esc(t.name)}</td>
              <td><span class="hb-t"><i style="width:${Math.max(3, t.tickets / maxT * 100).toFixed(1)}%;background:var(--series-2)"></i></span><b class="mini">${nf.format(t.tickets)}</b></td>
              <td class="num">${nf.format(t.resolved)}</td><td class="num">${nf.format(t.open)}</td>
              <td class="num" style="color:${slaColor(t.sla_pct)}"><b>${pctTxt(t.sla_pct)}</b></td><td class="num">${dur(t.tma)}</td><td class="num">${dur(t.tme)}</td>
              <td class="num">${t.wait_seconds ? dur(t.wait_seconds) : '—'}</td></tr>`).join('')}</tbody></table>` : '<div class="k-empty">Sem chamados no período.</div>'}</article>
        <article class="kcard k6"><h3>Chamados que mais ficaram pendentes</h3>${ticketTable(pend.top_tickets.slice(0, 5), links, 'Pendente')}</article>
        <article class="kcard k6"><h3>Com quem ficou mais tempo pendente <small>soma do tempo de espera</small></h3>
          ${hbars(pend.by_tech.map((t) => ({ name: `${t.name} (${t.wait_tickets})`, value: t.wait_seconds, label: dur(t.wait_seconds) })), { color: 'var(--warning)', empty: 'Nenhum chamado ficou pendente no período.' })}
          <p class="kfoot">Total pendente: <b>${dur(pend.total_seconds)}</b> · ${nf.format(pend.now)} pendente(s) agora.</p></article>
      </div>
      <footer class="kfooter"><span>Operacional 360 · Relatório executivo de KPI's</span><span>Página 3 de 3</span></footer>
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
  $('#kpiPdf').addEventListener('click', () => {
    if (!last) return;
    const t = document.title; document.title = `Operacional 360 - KPIs - ${(last.filters.from || '')} a ${(last.filters.to || '')}`;
    window.print(); setTimeout(() => { document.title = t; }, 1500);
  });
  if (new URLSearchParams(location.search).get('view') === 'kpi') setView('kpi');
})();
