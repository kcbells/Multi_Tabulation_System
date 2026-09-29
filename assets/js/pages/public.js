/* Public results page (no sign-in): published events → activities → standings.
   Score-based activities show their standings only once final; judges' scores are never shown. */
(async function () {
  'use strict';
  await App.boot({ roles: 'public' });
  const { esc, num } = App;
  const view = App.$('#view');

  const FORMAT = { score: 'Judged', bracket: 'Bracket', round_robin: 'Round robin', ranking: 'Ranking' };
  const FORMAT_ICON = { score: 'list', bracket: 'trophy', round_robin: 'grid', ranking: 'flow' };
  const STATE = { pending: ['pending', 'Upcoming'], open: ['open', 'Live'], closed: ['closed', 'Final'] };
  const ordinal = (n) => n + (['th', 'st', 'nd', 'rd'][(n % 100 - 20) % 10] || ['th', 'st', 'nd', 'rd'][n % 100] || 'th');
  const rankPill = (rank) => (rank ? `<span class="rank-pill ${rank <= 3 ? 'r' + rank : ''}">${rank}</span>` : '<span class="muted">—</span>');
  const sub = (e) => (e.team && e.team.trim().toLowerCase() !== e.name.trim().toLowerCase() ? e.team : '');
  const stateBadge = (status) => App.badge(...(STATE[status] || STATE.pending));
  // results already coming in count as live, even if nobody switched the activity on
  const liveStatus = (status, hasResults) => (status === 'pending' && hasResults ? 'open' : status);
  const when = (e) => (e.start_at ? App.dateTimeRange(e.start_at, e.end_at) : App.dateRange(e.start_date, e.end_date));

  const eventId = Number(App.param('event') || 0);
  const activityId = Number(App.param('activity') || 0);
  let tab = location.hash.slice(1) || 'activities';
  let scope = eventId; // event whose changes trigger a redraw (0 = the events list)

  /* ------------------------------------------------------------------ events list */

  async function renderEvents() {
    const { events } = await App.get('public.events');
    document.title = 'Live results · PHINMA COC Tabulation';
    view.innerHTML = `
      <section class="page-head">
        <div>
          <div class="eyebrow">PHINMA COC</div>
          <h1>Live results &amp; standings</h1>
          <p>Follow the events as they happen: standings, brackets and winners update by themselves.</p>
        </div>
      </section>
      ${events.length ? `<div class="grid-cards">${events.map((e) => `
        <a class="card pub-card" href="?event=${e.id}">
          <div class="card-body">
            <div class="row-between">${App.badge(e.status === 'ongoing' ? 'open' : e.status, e.status === 'ongoing' ? 'Happening now' : null)}
              ${Number(e.live_activities) ? `<span class="pub-livecount">${Number(e.live_activities)} live</span>` : ''}</div>
            <h3>${esc(e.title)}</h3>
            <div class="pub-meta">
              ${when(e) ? `<span>${App.icon('calendar')} ${esc(when(e))}</span>` : ''}
              ${e.venue ? `<span>${App.icon('flow')} ${esc(e.venue)}</span>` : ''}
            </div>
            <div class="pub-stats">
              <span><strong>${Number(e.activities)}</strong> activit${Number(e.activities) === 1 ? 'y' : 'ies'}</span>
              <span><strong>${Number(e.final_activities)}</strong> final</span>
              ${Number(e.teams) ? `<span><strong>${Number(e.teams)}</strong> teams</span>` : ''}
            </div>
          </div>
        </a>`).join('')}</div>`
        : `<div class="card">${App.empty('No results published yet', 'Results appear here once the organizers publish an event.', 'globe')}</div>`}`;
  }

  /* ------------------------------------------------------------------ one event */

  async function renderEvent() {
    const { event, activities, overall } = await App.get('public.event', { id: eventId });
    document.title = event.title + ' · Live results';
    const hasTeams = overall.standings.length > 0;
    view.innerHTML = `
      ${App.pathBar({ back: { href: 'public.html', label: 'all events' }, trail: [{ label: 'All events', href: 'public.html' }, { label: event.title }] })}
      <section class="page-head">
        <div>
          <div class="eyebrow">${event.status === 'ongoing' ? 'Happening now' : esc(event.status)}</div>
          <h1>${esc(event.title)}</h1>
          <div class="meta">
            ${when(event) ? `<span>${App.icon('calendar')} ${esc(when(event))}</span>` : ''}
            ${event.venue ? `<span>${esc(event.venue)}</span>` : ''}
          </div>
        </div>
      </section>
      <div class="tabs" role="tablist">
        <button data-tab="activities">${event.structure === 'single' ? 'Competition' : `Activities<span class="count">${activities.length}</span>`}</button>
        ${hasTeams ? `<button data-tab="overall">${event.structure === 'single' ? 'Team standings' : 'Overall standings'}</button>` : ''}
      </div>
      <section data-panel="activities">${activitiesHtml(activities)}</section>
      ${hasTeams ? `<section data-panel="overall" hidden>${overallHtml(overall)}</section>` : ''}`;
    App.tabs(view, (t) => (tab = t));
    Charts.bind(view);
  }

  function activitiesHtml(activities) {
    if (!activities.length) return `<div class="card">${App.empty('No activities yet', 'Activities appear here once they are scheduled.', 'calendar')}</div>`;
    return `<div class="grid-cards">${activities.map((a) => {
      const winners = [1, 2, 3].map((n) => a.winners.filter((w) => w.rank === n)).filter((w) => w.length);
      const body = a.format === 'score' && !a.published
        ? `<p class="pub-wait">${App.icon(a.status === 'open' ? 'clock' : 'calendar')} ${a.status === 'open' ? 'Judging in progress — results are announced when final.' : 'Results are announced when judging is final.'}</p>`
        : winners.length
          ? `<ol class="pub-winners">${winners.map((ws) => ws.map((w) => `<li>${rankPill(w.rank)}${App.entry(w, sub(w), 'xs')}</li>`).join('')).join('')}</ol>`
          : `<p class="pub-wait">${App.icon('clock')} ${a.status === 'pending' ? 'Not started yet.' : 'Waiting for the first results.'}</p>`;
      return `
        <a class="card pub-card" href="?activity=${a.id}">
          <div class="card-body">
            <div class="row-between">${a.certified ? App.activityBadge(a) : stateBadge(liveStatus(a.status, a.winners.length > 0))}<span class="pub-format">${App.icon(FORMAT_ICON[a.format] || 'list')} ${FORMAT[a.format] || ''}</span></div>
            <h3>${esc(a.title)}</h3>
            <div class="pub-meta">
              ${a.schedule_at ? `<span>${App.icon('clock')} ${esc(App.fmtDateTime(a.schedule_at))}</span>` : ''}
              ${a.venue ? `<span>${esc(a.venue)}</span>` : ''}
              ${a.contestants ? `<span>${App.icon('users')} ${a.contestants}</span>` : ''}
            </div>
            ${body}
          </div>
        </a>`;
    }).join('')}</div>`;
  }

  function overallHtml(o) {
    const s = o.standings;
    const anyPoints = s.some((t) => t.total > 0);
    const counted = o.activities.filter((a) => a.included && a.scored).length;
    const waiting = o.activities.filter((a) => a.hidden).length;
    if (!anyPoints) {
      return `<div class="card">${App.empty('No points yet', 'Team standings fill in as soon as activities have winners.', 'trophy')}</div>`;
    }
    const top = s.filter((t) => t.total > 0 && t.rank <= 3).slice(0, 3);
    return `
      <div class="standings-note">${App.icon('trophy')}<span>Points from <strong>${counted}</strong> activit${counted === 1 ? 'y' : 'ies'} with results${waiting ? ` · ${waiting} judged activit${waiting === 1 ? 'y counts' : 'ies count'} once final` : ''}. Points: ${o.placement_points.map((p, i) => ordinal(i + 1) + ' = ' + num(p)).join(', ')}${o.participation_points ? ', others = ' + num(o.participation_points) : ''}.</span></div>
      ${Charts.podium(top.map((t) => ({ rank: t.rank, name: t.name, value: App.pts(t.total), color: t.color, photo: t.photo })))}
      <div class="card"><div class="card-body">${Charts.bars(s.map((t) => ({ label: t.name, value: t.total, display: App.pts(t.total), rank: t.rank, medal: t.total > 0, look: t })), { title: 'Overall points' })}</div></div>
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Rank</th><th>Group</th><th class="num" title="1st / 2nd / 3rd places">Places<br><span class="muted">1st/2nd/3rd</span></th><th class="num">Points</th></tr></thead>
          <tbody>${s.map((t) => `<tr class="${t.total > 0 && t.rank <= 3 ? 'rank-' + t.rank : ''}">
            <td>${t.total > 0 ? rankPill(t.rank) : '<span class="muted">—</span>'}</td>
            <td class="name-col">${App.entry(t)}</td>
            <td class="num nowrap">${t.medals.join(' / ')}</td>
            <td class="num"><strong>${num(t.total)}</strong></td></tr>`).join('')}</tbody>
        </table></div>
      </div>`;
  }

  /* ------------------------------------------------------------------ one activity */

  async function renderActivity() {
    const d = await App.get('public.activity', { id: activityId });
    const a = d.activity;
    scope = a.event_id;
    document.title = a.title + ' · Live results';
    view.innerHTML = `
      ${App.pathBar({ back: { href: '?event=' + d.event.id, label: d.event.title }, trail: [{ label: 'All events', href: 'public.html' }, { label: d.event.title, href: '?event=' + d.event.id }, { label: a.title }] })}
      <section class="page-head">
        <div>
          <div class="eyebrow">${esc(FORMAT[a.format] || '')}${a.nature ? ' · ' + esc(a.nature) : ''}</div>
          <h1>${esc(a.title)}</h1>
          <div class="meta">
            ${a.certified ? App.activityBadge(a) : stateBadge(liveStatus(a.status, d.rows.some((r) => r.rank)))}
            ${a.schedule_at ? `<span>${App.icon('clock')} ${esc(App.fmtDateTime(a.schedule_at))}</span>` : ''}
            ${a.venue ? `<span>${esc(a.venue)}</span>` : ''}
          </div>
        </div>
      </section>
      ${d.visible ? standingsHtml(d) : waitingHtml(d)}`;
    Charts.bind(view);
  }

  function waitingHtml(d) {
    const open = d.activity.status === 'open';
    return `
      <div class="pub-announce">
        ${App.icon(open ? 'clock' : 'calendar')}
        <div><strong>${open ? 'Judging in progress' : 'Results not announced yet'}</strong>
          <span>The standings of this judged activity are published once the judges’ scores are final.</span></div>
      </div>
      ${d.lineup.length ? `<div class="card"><div class="card-head"><h3>Contestants</h3><span class="muted small">${d.lineup.length}</span></div>
        <div class="card-body"><div class="pub-lineup">${d.lineup.map((c) => `
          <div class="pub-entry">${App.avatar(c, 'lg')}<strong>${esc(c.name)}</strong>${sub(c) ? `<small>${esc(sub(c))}</small>` : ''}${c.number ? `<span class="pub-no">No. ${c.number}</span>` : ''}</div>`).join('')}
        </div></div></div>` : ''}`;
  }

  function standingsHtml(d) {
    const a = d.activity;
    const ranked = d.rows.filter((r) => r.rank);
    const podium = (a.format !== 'bracket' || d.complete) && ranked.length
      ? Charts.podium(ranked.map((r) => ({ rank: r.rank, name: r.name, sub: sub(r), value: r.display, color: r.color, photo: r.photo })))
      : '';
    const official = (a.certified
      ? `<div class="standings-note certified">${App.icon('shield')}<span><strong>Certified official results.</strong> Signed off by the organizers.</span></div>`
      : a.status === 'closed'
        ? `<div class="standings-note">${App.icon('check')}<span><strong>Final results.</strong></span></div>`
        : `<div class="standings-note">${App.icon('clock')}<span><strong>Live</strong> — updates by itself as results come in.</span></div>`)
      + (d.awards && d.awards.length ? `<div class="card"><div class="card-head"><h3>${App.icon('star')} Special awards</h3></div>
          <ul class="award-list">${d.awards.map((w) => `<li><span class="award-icon">${App.icon('star')}</span><div><strong>${esc(w.name)}</strong></div>
            <div class="award-winners">${w.winners.map((x) => `<div>${App.entry(x, sub(x), 'xs')}</div>`).join('')}</div></li>`).join('')}</ul></div>` : '');

    if (a.format === 'bracket') {
      return `${official}${podium}
        ${d.matches.length
          ? `<div class="card bracket-card"><div class="card-head"><h3>${App.icon('trophy')} Bracket</h3></div>${Competition.bracketView({ activity: a, matches: d.matches })}</div>`
          : `<div class="card">${App.empty('Bracket not drawn yet', 'The bracket appears here once the draw is made.', 'trophy')}</div>`}`;
    }
    if (a.format === 'round_robin') {
      const rounds = [...new Set(d.matches.map((m) => m.round))].sort((x, y) => x - y);
      const played = d.table.some((r) => r.played > 0);
      return `${official}${played ? podium : ''}
        <div class="comp-grid">
          <div class="card"><div class="card-head"><h3>Standings</h3><span class="muted small">Win 3 · Draw 1 · Loss 0</span></div>
            <div class="table-wrap"><table class="table">
              <thead><tr><th>Rank</th><th>Team</th><th class="num">P</th><th class="num">W</th><th class="num">D</th><th class="num">L</th><th class="num">+/−</th><th class="num">Pts</th></tr></thead>
              <tbody>${d.table.map((r) => `<tr class="${played && r.rank <= 3 ? 'rank-' + r.rank : ''}">
                <td>${played ? rankPill(r.rank) : '<span class="muted">—</span>'}</td><td class="name-col">${App.entry(r, sub(r))}</td>
                <td class="num">${r.played}</td><td class="num">${r.won}</td><td class="num">${r.drawn}</td><td class="num">${r.lost}</td>
                <td class="num">${r.diff > 0 ? '+' : ''}${num(r.diff)}</td><td class="num"><strong>${r.points}</strong></td></tr>`).join('')}</tbody>
            </table></div></div>
          <div class="card"><div class="card-head"><h3>Fixtures</h3></div><div class="card-body">
            ${rounds.length ? rounds.map((round) => `<div class="rr-round"><div class="b-round-title">Round ${round}</div>
              ${d.matches.filter((m) => m.round === round).map((m) => {
                const aWin = m.status === 'done' && m.winner_id === m.a?.id;
                const bWin = m.status === 'done' && m.winner_id === m.b?.id;
                return `<div class="rr-game ${m.status === 'done' ? 'done' : ''}">
                  <span class="rr-team ${aWin ? 'win' : ''}">${m.a ? App.avatar(m.a, 'xs') : ''}<span>${esc(m.a?.name || 'TBD')}</span></span>
                  <span class="rr-score">${m.status === 'done' ? `${num(m.score_a)} <small>vs</small> ${num(m.score_b)}` : 'vs'}</span>
                  <span class="rr-team right ${bWin ? 'win' : ''}"><span>${esc(m.b?.name || 'TBD')}</span>${m.b ? App.avatar(m.b, 'xs') : ''}</span></div>`;
              }).join('')}</div>`).join('') : '<p class="muted">Fixtures appear here once they are drawn.</p>'}
          </div></div>
        </div>`;
    }
    // judged (final) and ranking activities: podium + leaderboard
    if (!ranked.length) return `${official}<div class="card">${App.empty('No results yet', 'Standings appear here as soon as results come in.', 'trophy')}</div>`;
    return `${official}${podium}
      <div class="card">
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Rank</th><th>Contestant</th><th class="num">Result</th></tr></thead>
          <tbody>${d.rows.map((r) => `<tr class="${r.rank && r.rank <= 3 ? 'rank-' + r.rank : ''}">
            <td>${rankPill(r.rank)}</td><td class="name-col">${App.entry(r, sub(r))}</td><td class="num"><strong>${esc(r.display || '—')}</strong></td></tr>`).join('')}</tbody>
        </table></div>
      </div>`;
  }

  /* ------------------------------------------------------------------ routing & live updates */

  async function render() {
    const y = window.scrollY;
    try {
      if (activityId) await renderActivity();
      else if (eventId) await renderEvent();
      else await renderEvents();
      if (eventId && tab !== 'activities') App.$(`.tabs [data-tab="${tab}"]`, view)?.click();
      window.scrollTo(0, y);
    } catch (err) {
      view.innerHTML = `<div class="card">${App.empty(err.status === 404 ? 'Not available' : 'Could not load the results', esc(err.message), 'globe')}
        <p class="text-center" style="padding-bottom:26px"><a class="btn" href="public.html">See all events</a></p></div>`;
    }
  }

  await render();

  // Poll a small version string and redraw only when something changed.
  const dot = App.$('[data-live-dot]');
  let last = null;
  App.poll(async () => {
    const { v } = await App.get('public.version', scope ? { event_id: scope } : {});
    dot.hidden = false;
    if (last !== null && v !== last) await render();
    last = v;
  }, Math.max(5, App.session?.app?.live_seconds || 4) * 1000);
})();
