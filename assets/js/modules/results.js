/* Renderers for tabulation results, judge progress and overall standings. */
(function () {
  'use strict';
  const { esc, num } = App;
  const Results = (window.Results = {});

  // a trophy only for a real top-3 place; everyone else shows a plain number
  const rankPill = (rank, medal = true) => (rank ? `<span class="rank-pill ${medal && rank <= 3 ? 'r' + rank : ''}">${rank}</span>` : '<span class="muted">—</span>');
  const ordinal = (n) => n + (['th', 'st', 'nd', 'rd'][(n % 100 - 20) % 10] || ['th', 'st', 'nd', 'rd'][n % 100] || 'th');
  Results.ordinal = ordinal;

  /** Activity ranking table. opts: { showJudges (default true), showCriteria (default true) } */
  Results.activityTable = (r, opts = {}) => {
    const showJudges = opts.showJudges !== false;
    const showCriteria = opts.showCriteria !== false;
    const judges = r.judges.filter((j) => r.counted_judges.includes(j.id));
    if (!r.rows.length) return App.empty('No contestants yet', 'Add contestants to this activity first.', 'users');
    if (!judges.length) {
      return App.empty(
        r.include_drafts ? 'No scores yet' : 'No submitted scores yet',
        r.include_drafts ? 'Scores appear here as soon as judges start scoring.' : 'Rankings appear when judges submit. Turn on “Include unsubmitted scores” to preview live scoring.',
        'trophy'
      );
    }
    return `
      <div class="table-wrap">
        <table class="table">
          <thead><tr>
            <th>Rank</th><th>No.</th><th>Contestant</th>
            ${showCriteria ? r.criteria.map((c) => `<th class="num" title="Average across judges">${esc(c.name)}<br><span class="muted">/${num(c.max_score)}</span></th>`).join('') : ''}
            ${showJudges ? judges.map((j) => `<th class="num">${esc(j.name)}${j.submitted ? '' : ' <span title="Not yet submitted">*</span>'}</th>`).join('') : ''}
            <th class="num">Average<br><span class="muted">/${num(r.max_total)}</span></th>
            <th class="num">%</th>
          </tr></thead>
          <tbody>
            ${r.rows.map((row) => `
              <tr class="${row.rank && row.rank <= 3 ? 'rank-' + row.rank : ''}">
                <td>${rankPill(row.rank)}</td>
                <td class="num">${row.number}</td>
                <td class="name-col">${App.entry(row, row.team_name && row.team_name !== row.name ? row.team_name : '')}</td>
                ${showCriteria ? r.criteria.map((c) => `<td class="num">${num(row.criteria_avg[c.id])}</td>`).join('') : ''}
                ${showJudges ? judges.map((j) => `<td class="num">${num(row.judge_totals[j.id])}</td>`).join('') : ''}
                <td class="num"><strong>${row.average === null ? '—' : Number(row.average).toFixed(2)}</strong></td>
                <td class="num">${row.percentage === null ? '—' : Number(row.percentage).toFixed(2)}</td>
              </tr>`).join('')}
          </tbody>
        </table>
      </div>
      <p class="muted small" style="margin:10px 2px 0">
        Final score = average of ${judges.length} judge total${judges.length === 1 ? '' : 's'}${r.include_drafts ? ' (including unsubmitted — preview only)' : ''}. Ties share the same rank.
        ${judges.some((j) => !j.submitted) ? ' * not yet submitted.' : ''}
      </p>`;
  };

  /** Judge progress table. opts: { canUnlock, onlyActive } */
  Results.judgeProgress = (r, opts = {}) => {
    if (!r.judges.length) return App.empty('No judges assigned', 'Assign judges to this activity to start scoring.', 'users');
    return `
      <div class="table-wrap">
        <table class="table stackable">
          <thead><tr><th>Judge</th><th>Progress</th><th>Status</th><th class="actions"></th></tr></thead>
          <tbody>
            ${r.judges.map((j) => {
              const pct = j.expected ? Math.round((j.scored / j.expected) * 100) : 0;
              return `<tr>
                <td class="primary" data-label="Judge">${esc(j.name)}${j.is_active ? '' : ' <span class="badge no-dot">Disabled</span>'}</td>
                <td data-label="Progress"><div class="row" style="flex-wrap:nowrap"><div class="progress grow ${pct >= 100 ? 'done' : ''}"><span style="width:${Math.min(100, pct)}%"></span></div><span class="small nowrap">${j.scored}/${j.expected}</span></div></td>
                <td data-label="Status">${j.submitted ? `<span class="badge badge-closed">Submitted</span> <span class="muted small">${App.fmtDateTime(j.submitted_at)}</span>` : j.scored ? '<span class="badge badge-open">Scoring</span>' : '<span class="badge badge-pending">Not started</span>'}</td>
                <td class="actions">
                  <button class="btn btn-sm" data-sheet="${j.id}" ${j.scored ? '' : 'disabled'}>${App.icon('eye')} Sheet</button>
                  ${opts.canUnlock && j.submitted ? `<button class="btn btn-sm btn-danger" data-unlock="${j.id}">${App.icon('unlock')} Unlock</button>` : ''}
                </td>
              </tr>`;
            }).join('')}
          </tbody>
        </table>
      </div>`;
  };

  /** Modal with one judge's criterion-level scores. */
  Results.showJudgeSheet = async (activityId, judge) => {
    const d = await App.get('scores.judge_sheet', { activity_id: activityId, judge_id: judge.id });
    const map = {};
    d.scores.forEach((s) => (map[s.contestant_id + ':' + s.criterion_id] = s.score));
    const body = `
      <p class="muted small">${d.submitted_at ? 'Submitted ' + App.fmtDateTime(d.submitted_at) : 'Not yet submitted'}</p>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>No.</th><th>Contestant</th>${d.criteria.map((c) => `<th class="num">${esc(c.name)}<br><span class="muted">/${num(c.max_score)}</span></th>`).join('')}<th class="num">Total</th></tr></thead>
        <tbody>${d.contestants.map((c) => {
          let total = 0;
          let any = false;
          const cells = d.criteria.map((cr) => {
            const v = map[c.id + ':' + cr.id];
            if (v !== undefined) { total += Number(v); any = true; }
            return `<td class="num">${v === undefined ? '<span class="muted">—</span>' : num(v)}</td>`;
          }).join('');
          return `<tr><td class="num">${c.number}</td><td>${esc(c.name)}</td>${cells}<td class="num"><strong>${any ? num(total) : '—'}</strong></td></tr>`;
        }).join('')}</tbody>
      </table></div>`;
    return App.modal({ title: 'Score sheet — ' + judge.name, body, wide: true, submitText: null, cancelText: 'Close' });
  };

  /** Ranking of the individual participants of a solo activity (no team entries). */
  const soloStandings = (a) => {
    const ranked = a.ranking.filter((p) => p.rank);
    return `
      <div class="card solo-standings">
        <div class="card-head">
          <div><h3>${esc(a.title)} — Individual standings</h3><div class="muted small">Solo entries · ties share the same rank</div></div>
          ${a.status === 'closed' ? App.badge('closed', 'Final') : App.badge('pending', 'Unofficial — not final')}
        </div>
        ${ranked.length ? `<div class="card-body">${Charts.podium(ranked.map((p) => ({ rank: p.rank, name: p.name, value: p.display, color: p.color, photo: p.photo })))}</div>` : ''}
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Rank</th><th class="num">No.</th><th>Participant</th><th class="num">Result</th></tr></thead>
            <tbody>${a.ranking.map((p) => `<tr class="${p.rank && p.rank <= 3 ? 'rank-' + p.rank : ''}">
              <td>${rankPill(p.rank)}</td>
              <td class="num">${p.number ?? ''}</td>
              <td class="name-col">${App.entry(p)}</td>
              <td class="num"><strong>${esc(p.display || '—')}</strong></td></tr>`).join('')}</tbody>
          </table>
        </div>
      </div>`;
  };

  /**
   * Event standings: team standings for activities entered by teams, and an individual
   * ranking for each solo activity (entries that belong to no team).
   */
  Results.overall = (r) => {
    const solo = r.activities.filter((a) => a.solo);
    if (!r.standings.length) {
      return solo.length
        ? solo.map(soloStandings).join('')
        : App.empty('No results yet', 'Add contestants to the activities. Teams are only needed when contestants represent colleges or departments.', 'trophy');
    }
    return solo.map(soloStandings).join('') + teamStandings(r);
  };

  function teamStandings(r) {
    const s = r.standings;
    const counted = r.activities.filter((a) => a.included && !a.solo);
    const anyPoints = s.some((t) => t.total > 0);
    const medal = (t) => t.total > 0 && t.rank <= 3; // teams without points never get a trophy
    const top = anyPoints ? s.filter(medal).slice(0, 3) : [];
    const unlinked = counted.filter((a) => a.unlinked > 0);
    const finished = counted.filter((a) => a.scored).length;

    return `
      <div class="standings-note">
        ${App.icon('trophy')}
        <span>${finished ? `Points from <strong>${finished}</strong> of ${counted.length} counted activit${counted.length === 1 ? 'y' : 'ies'} with results. Standings update automatically as activities finish.` : 'No results yet. Standings fill in automatically as soon as activities have winners.'}</span>
      </div>
      ${unlinked.map((a) => `<div class="alert alert-warn" style="margin-bottom:12px"><strong>${esc(a.title)}</strong>: ${a.unlinked} ranked entr${a.unlinked === 1 ? 'y is' : 'ies are'} not linked to a team, so ${a.unlinked === 1 ? 'it earns' : 'they earn'} no points. Open the activity's Contestants tab and choose each entry's team.</div>`).join('')}
      ${top.length ? `<div class="podium">${top.map((t, i) => `
        <div class="place p${Math.min(t.rank, 3)}">
          <div class="pos">${ordinal(t.rank)} place</div>
          ${t.photo || t.color ? `<div class="place-avatar">${App.avatar(t, t.rank === 1 ? 'xl' : 'lg')}</div>` : ''}
          <div class="team">${esc(t.name)}</div>
          <div class="pts">${num(t.total)} <span class="small">pts</span></div>
        </div>`).join('')}</div>` : ''}

      ${anyPoints ? `<div class="card"><div class="card-body">${Charts.bars(s.map((t) => ({ label: t.name, value: t.total, display: App.pts(t.total), rank: t.rank, medal: medal(t), look: t })), { title: 'Overall points', caption: 'Total team points from every counted activity' })}</div></div>` : ''}

      <div class="card">
        <div class="card-head"><h3>Team standings</h3>
          <span class="muted small">Points: ${r.placement_points.map((p, i) => ordinal(i + 1) + ' = ' + num(p)).join(', ')}${r.participation_points ? ', others = ' + num(r.participation_points) : ''}</span></div>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Rank</th><th>Team</th>
              ${counted.map((a) => `<th class="num">${esc(a.title)}</th>`).join('')}
              <th class="num" title="Number of 1st / 2nd / 3rd places">Places<br><span class="muted">1st/2nd/3rd</span></th><th class="num">Total</th></tr></thead>
            <tbody>
              ${s.map((t) => `<tr class="${anyPoints && medal(t) ? 'rank-' + t.rank : ''}">
                <td>${anyPoints ? rankPill(t.rank, medal(t)) : '<span class="muted">—</span>'}</td>
                <td class="name-col">${App.entry(t)}</td>
                ${counted.map((a) => `<td class="num">${t.activities[a.id] !== undefined ? num(t.activities[a.id]) : '<span class="muted">—</span>'}</td>`).join('')}
                <td class="num nowrap">${t.medals.join(' / ')}</td>
                <td class="num"><strong>${num(t.total)}</strong></td>
              </tr>`).join('')}
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <div class="card-head"><h3>Activity winners</h3></div>
        <div class="table-wrap">
          <table class="table stackable">
            <thead><tr><th>Activity</th><th>Status</th><th>1st</th><th>2nd</th><th>3rd</th></tr></thead>
            <tbody>
              ${r.activities.filter((a) => !a.solo).map((a) => {
                const place = (n) => a.winners.filter((w) => w.rank === n).map((w) => `<div>${App.entry(w, w.team && w.team !== w.name ? w.team : '', 'xs')}</div>`).join('') || '<span class="muted">—</span>';
                return `<tr>
                  <td class="primary" data-label="Activity">${esc(a.title)}${a.counts_to_overall ? '' : ' <span class="badge no-dot">Not counted</span>'}</td>
                  <td data-label="Status">${App.badge(a.status)}</td>
                  <td data-label="1st">${place(1)}</td><td data-label="2nd">${place(2)}</td><td data-label="3rd">${place(3)}</td>
                </tr>`;
              }).join('')}
            </tbody>
          </table>
        </div>
      </div>`;
  }
})();
