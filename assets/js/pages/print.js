/* Printable reports: activity results, overall standings, access code slips. */
(async function () {
  'use strict';
  const me = await App.boot({ roles: ['admin', 'program_head', 'facilitator'], layout: 'bare' });
  const { esc, num } = App;
  const view = App.$('#view');
  const type = App.param('type');
  const id = Number(App.param('id') || 0);

  const head = (title, subtitle) => `
    <div class="print-actions no-print">
      <button class="btn" onclick="history.length > 1 ? history.back() : window.close()">${App.icon('chevronLeft')} Back</button>
      <button class="btn btn-primary" onclick="window.print()">${App.icon('print')} Print</button>
    </div>
    <header class="print-head">
      <div class="brand-lockup">
        <img class="brand-logo" src="${App.url('assets/images/app-icon.png')}" alt="">
        <span><strong>${esc(me.app.school)}</strong><small>${esc(me.app.name)}</small></span>
      </div>
      <div class="text-right"><h1>${esc(title)}</h1><div class="muted small">${subtitle}</div></div>
    </header>`;

  const signatures = (names) => `<div class="sign-grid">${names.map((n) => `<div>${esc(n)}</div>`).join('')}</div>`;

  try {
    if (type === 'board') {
      const d = await App.get('competition.get', { id });
      const a = d.activity;
      const ev = await App.get('events.get', { id: a.event_id });
      document.title = a.title + ' — Results';
      const fmt = Forms.FORMATS[a.format];
      view.innerHTML = `
        ${head(a.title + ' — ' + fmt.label, `${esc(ev.event.title)} · ${a.status === 'closed' ? 'Final' : '<strong>UNOFFICIAL — not finalized</strong>'} · Generated ${esc(App.fmtDateTime(new Date().toISOString()))}`)}
        <div id="board"></div>
        ${signatures(['Tabulator', 'Facilitator', 'Program Head'])}`;
      await Competition.mount(App.$('#board', view), { activityId: id, canManage: false });
      App.$$('.no-print-board, [data-regenerate]', view).forEach((el) => el.remove());
    } else if (type === 'activity') {
      const drafts = App.param('drafts') === '1';
      const { result: r } = await App.get('results.activity', { id, drafts: drafts ? 1 : 0 });
      document.title = r.activity.title + ' — Results';
      const judges = r.judges.filter((j) => r.counted_judges.includes(j.id));
      view.innerHTML = `
        ${head(r.activity.title + ' — Official Results', `${esc(r.activity.event_title)} · ${r.activity.status === 'closed' ? 'Final' : '<strong>UNOFFICIAL — scoring not closed</strong>'}${drafts ? ' · includes unsubmitted scores' : ''} · Generated ${esc(App.fmtDateTime(r.generated_at))}`)}
        ${Results.activityTable(r)}
        <h3 style="margin-top:22px">Criteria</h3>
        <p class="small">${r.criteria.map((c) => `${esc(c.name)} (${num(c.max_score)})`).join(' · ')}</p>
        ${signatures([...judges.map((j) => j.name), 'Tabulator', 'Facilitator', 'Program Head'])}`;
    } else if (type === 'overall') {
      const { result: r } = await App.get('results.overall', { id, final_only: App.param('final_only') === '1' ? 1 : 0 });
      // an event without teams (solo entries only) prints its participants' ranking
      const title = r.standings.length ? 'Overall Standings' : 'Official Standings';
      document.title = r.event.title + ' — ' + title;
      view.innerHTML = `
        ${head(title, `${esc(r.event.title)}${r.event.start_date ? ' · ' + esc(App.dateRange(r.event.start_date, r.event.end_date)) : ''} · Generated ${esc(App.fmtDateTime(r.generated_at))}`)}
        ${Results.overall(r)}
        ${signatures(['Tabulator', 'Program Head', 'Administrator'])}`;
    } else if (type === 'codes') {
      const data = await App.get('events.get', { id });
      if (!data.can_configure) throw new Error('Only staff can print access codes.');
      const titles = Object.fromEntries(data.activities.map((a) => [a.id, a.title]));
      document.title = data.event.title + ' — Access codes';
      view.innerHTML = `
        ${head('Access Code Slips', esc(data.event.title) + ' · Cut along the dashed lines')}
        <div class="slips">
          ${data.codes.filter((c) => Number(c.is_active)).map((c) => `
            <div class="slip ${c.role}">
              <span class="role">${esc(App.roleLabel(c.role))}</span>
              <div style="font-weight:700;margin-top:8px">${esc(c.name)}</div>
              <div class="code-value">${esc(c.display_code)}</div>
              ${c.role === 'judge' && c.activity_ids.length ? `<div class="small muted">${c.activity_ids.map((a) => esc(titles[a] || '')).join(', ')}</div>` : ''}
              <div class="small" style="margin-top:8px">Sign in on the <a href="${App.url('index.html')}"><strong>Tabulation System homepage</strong></a></div>
            </div>`).join('') || App.empty('No active access codes', '', 'key')}
        </div>`;
    } else {
      throw new Error('Unknown report.');
    }
  } catch (err) {
    view.innerHTML = `<div class="alert alert-error">${esc(err.message)}</div>`;
  }
})();
