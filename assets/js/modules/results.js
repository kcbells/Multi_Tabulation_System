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
    const m = r.method || {};
    const rankSum = m.scoring === 'rank_sum';
    const carry = Number(m.carry_weight) > 0;
    const anyDeduction = r.rows.some((row) => Number(row.deduction) > 0);
    const anyTieNote = r.rows.some((row) => row.tie_note);
    const f2 = (v) => (v === null || v === undefined ? '—' : Number(v).toFixed(2));
    return `
      <div class="table-wrap">
        <table class="table">
          <thead><tr>
            <th>Rank</th><th>No.</th><th>Contestant</th>
            ${showCriteria ? r.criteria.map((c) => `<th class="num" title="Average across judges">${esc(c.name)}<br><span class="muted">/${num(c.max_score)}</span></th>`).join('') : ''}
            ${showJudges ? judges.map((j) => `<th class="num">${esc(j.name)}${j.submitted ? '' : ' <span title="Not yet submitted">*</span>'}</th>`).join('') : ''}
            ${anyDeduction ? '<th class="num" title="Points taken off (penalties)">Deduction</th>' : ''}
            ${carry ? `<th class="num" title="Score of the previous round">Prev. round<br><span class="muted">${num(m.carry_weight)}%</span></th><th class="num">This round<br><span class="muted">${num(100 - m.carry_weight)}%</span></th>` : ''}
            ${rankSum ? '<th class="num" title="Sum of the ranks the judges gave (lower is better)">Rank sum</th>' : ''}
            <th class="num">${carry ? 'Final' : 'Average'}<br><span class="muted">/${num(r.max_total)}</span></th>
            <th class="num">%</th>
          </tr></thead>
          <tbody>
            ${r.rows.map((row) => {
              const dropped = new Set((row.dropped || []).map(Number));
              return `
              <tr class="${row.rank && row.rank <= 3 ? 'rank-' + row.rank : ''}">
                <td>${rankPill(row.rank)}${row.tie_note ? ` <span class="tie-mark" title="${esc(row.tie_note)}">${App.icon('alert')}</span>` : ''}</td>
                <td class="num">${row.number}</td>
                <td class="name-col">${App.entry(row, row.team_name && row.team_name !== row.name ? row.team_name : '')}</td>
                ${showCriteria ? r.criteria.map((c) => `<td class="num">${num(row.criteria_avg[c.id])}</td>`).join('') : ''}
                ${showJudges ? judges.map((j) => `<td class="num ${dropped.has(j.id) ? 'dropped' : ''}" ${dropped.has(j.id) ? 'title="Dropped (highest or lowest)"' : ''}>${num(row.judge_totals[j.id])}</td>`).join('') : ''}
                ${anyDeduction ? `<td class="num">${Number(row.deduction) ? `<span class="deduct" title="${esc((row.deductions || []).map((d) => num(d.points) + ' — ' + d.reason).join('\n'))}">−${num(row.deduction)}</span>` : '<span class="muted">—</span>'}</td>` : ''}
                ${carry ? `<td class="num">${f2(row.previous)}</td><td class="num">${f2(row.round_score)}</td>` : ''}
                ${rankSum ? `<td class="num"><strong>${row.rank_sum === null ? '—' : num(row.rank_sum)}</strong></td>` : ''}
                <td class="num"><strong>${f2(row.average)}</strong></td>
                <td class="num">${f2(row.percentage)}</td>
              </tr>`;
            }).join('')}
          </tbody>
        </table>
      </div>
      <p class="muted small" style="margin:10px 2px 0">
        ${rankSum ? `Ranked by the sum of the ranks each judge gave (lowest wins), from ${judges.length} judge${judges.length === 1 ? '' : 's'}` : `Final score = average of ${judges.length} judge total${judges.length === 1 ? '' : 's'}`}${m.drop_extremes ? ', leaving out the highest and lowest judge (struck through)' : ''}${anyDeduction ? ', minus deductions' : ''}${r.include_drafts ? ' (including unsubmitted — preview only)' : ''}.
        ${m.tie_break === 'share' ? 'Ties share the same rank.' : 'Ties are broken by ' + esc({ criterion: 'the higher ' + (m.tie_criterion || 'criterion') + ' score', rank_sum: 'the lower rank sum', average: 'the higher average' }[m.tie_break]) + (anyTieNote ? ' (marked ' + App.icon('alert') + ').' : '.')}
        ${judges.some((j) => !j.submitted) ? ' * not yet submitted.' : ''}
      </p>`;
  };

  /** One line on how this activity is tabulated (method, scale, rounds, tie-break). */
  Results.methodNote = (r) => {
    const m = r.method || {};
    const parts = [];
    if (m.scoring === 'rank_sum') parts.push('Rank sum: each judge ranks the contestants, lowest total of ranks wins');
    if (m.drop_extremes) parts.push('highest and lowest judge dropped');
    if (m.score_scale) parts.push(`judges score 0–${num(m.score_scale)} per criterion, weighted by its points`);
    if (Number(m.carry_weight)) parts.push(`${num(m.carry_weight)}% of the previous round carried over`);
    if (m.tie_break !== 'share') parts.push('ties broken by ' + ({ criterion: 'the higher ' + (m.tie_criterion || 'criterion') + ' score', rank_sum: 'the lower rank sum', average: 'the higher average' }[m.tie_break] || ''));
    const line = parts.join(' · ');
    return parts.length ? `<div class="method-note">${App.icon('list')}<span>${esc(line[0].toUpperCase() + line.slice(1))}.</span></div>` : '';
  };

  const FLAG_TEXT = { flat: 'Nearly the same total for everyone', harsh: 'Scores far below the rest of the panel', generous: 'Scores far above the rest of the panel' };

  /** Score changes (after unlocks and corrections) and unlocks of an activity. */
  Results.history = (h) => {
    if (!h.changes.length && !h.unlocks.length) return `<div class="card-body muted small">No score was changed after being entered. ${App.icon('check')}</div>`;
    return `
      ${h.unlocks.length ? `<div class="card-body small" style="padding-bottom:0">${h.unlocks.map((u) => `<div>${App.icon('unlock')} <strong>${esc(u.judge_name || 'A judge')}</strong> was unlocked${u.unlocked_by ? ' by ' + esc(u.unlocked_by) : ''} · ${esc(App.fmtDateTime(u.unlocked_at))}</div>`).join('')}</div>` : ''}
      ${h.changes.length ? `<div class="table-wrap"><table class="table stackable">
        <thead><tr><th>When</th><th>Judge</th><th>Contestant</th><th>Criterion</th><th class="num">Before</th><th class="num">After</th></tr></thead>
        <tbody>${h.changes.map((c) => `<tr class="${Number(c.after_unlock) ? 'after-unlock' : ''}">
          <td data-label="When" class="nowrap">${esc(App.fmtDateTime(c.changed_at))}${Number(c.after_unlock) ? ' <span class="badge badge-pending no-dot" title="Changed after the judge was unlocked">after unlock</span>' : ''}</td>
          <td data-label="Judge">${esc(c.judge_name || '—')}</td>
          <td data-label="Contestant">${c.contestant_number ? c.contestant_number + '. ' : ''}${esc(c.contestant_name || '(removed)')}</td>
          <td data-label="Criterion">${esc(c.criterion_name || '(removed)')}</td>
          <td class="num" data-label="Before">${c.old_score === null ? '<span class="muted">—</span>' : num(c.old_score)}</td>
          <td class="num" data-label="After"><strong>${c.new_score === null ? '<span class="muted">cleared</span>' : num(c.new_score)}</strong></td>
        </tr>`).join('')}</tbody></table></div>` : ''}`;
  };

  Results.deductions = (list, canEdit) => (list.length ? `
    <div class="table-wrap"><table class="table stackable">
      <thead><tr><th>Contestant</th><th class="num">Points</th><th>Reason</th><th>By</th>${canEdit ? '<th class="actions"></th>' : ''}</tr></thead>
      <tbody>${list.map((d) => `<tr>
        <td class="primary" data-label="Contestant">${d.contestant_number}. ${esc(d.contestant_name)}</td>
        <td class="num" data-label="Points"><span class="deduct">−${num(d.points)}</span></td>
        <td data-label="Reason">${esc(d.reason)}</td>
        <td data-label="By" class="muted small">${esc(d.created_by || '')} · ${esc(App.fmtDateTime(d.created_at))}</td>
        ${canEdit ? `<td class="actions"><button class="btn btn-sm btn-ghost" data-delete-deduction="${d.id}" title="Remove">${App.icon('trash')}</button></td>` : ''}
      </tr>`).join('')}</tbody></table></div>`
    : '<div class="card-body muted small">No deductions.</div>');

  Results.awards = (awards) => (awards.length ? `
    <ul class="award-list">${awards.map((a) => `
      <li><span class="award-icon">${App.icon('star')}</span>
        <div><strong>${esc(a.name)}</strong><div class="muted small">${a.criterion_id ? 'Highest ' + esc(a.criterion_name || '') + ' average' : 'Picked by hand'}</div></div>
        <div class="award-winners">${a.winners.length ? a.winners.map((w) => `<div>${App.entry(w, w.value !== null && w.value !== undefined ? num(w.value) : '', 'xs')}</div>`).join('') : '<span class="muted small">No scores yet</span>'}</div>
      </li>`).join('')}</ul>`
    : '<div class="card-body muted small">No special awards yet.</div>');

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
                <td class="primary" data-label="Judge">${esc(j.name)}${j.is_active ? '' : ' <span class="badge no-dot">Disabled</span>'}
                  ${(j.flags || []).map((f) => `<div class="judge-flag">${App.icon('alert')} ${esc(FLAG_TEXT[f] || f)}</div>`).join('')}
                  ${j.mean !== null && j.mean !== undefined ? `<div class="muted small">Gives ${num(j.mean)} on average · spread ${num(j.spread)}</div>` : ''}</td>
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
    const scale = Number(d.score_scale) || 0; // 0–10 per criterion, weighted by its points
    const body = `
      <p class="muted small">${d.submitted_at ? 'Submitted ' + App.fmtDateTime(d.submitted_at) : 'Not yet submitted'}${scale ? ` · scored 0–${num(scale)} per criterion; the total is weighted` : ''}</p>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>No.</th><th>Contestant</th>${d.criteria.map((c) => `<th class="num">${esc(c.name)}<br><span class="muted">/${num(scale || c.max_score)}</span></th>`).join('')}<th class="num">Total<br><span class="muted">/100</span></th></tr></thead>
        <tbody>${d.contestants.map((c) => {
          let total = 0;
          let any = false;
          const cells = d.criteria.map((cr) => {
            const v = map[c.id + ':' + cr.id];
            if (v !== undefined) { total += Number(v) * (scale ? Number(cr.max_score) / scale : 1); any = true; }
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
      ${unlinked.map((a) => `<div class="alert alert-warn" style="margin-bottom:12px"><strong>${esc(a.title)}</strong>: ${a.unlinked} ranked entr${a.unlinked === 1 ? 'y has' : 'ies have'} no group, so ${a.unlinked === 1 ? 'it earns' : 'they earn'} no points. Open the activity's Participants tab and choose each entry's group.</div>`).join('')}
      ${top.length ? `<div class="podium">${top.map((t, i) => `
        <div class="place p${Math.min(t.rank, 3)}">
          <div class="pos">${ordinal(t.rank)} place</div>
          ${t.photo || t.color ? `<div class="place-avatar">${App.avatar(t, t.rank === 1 ? 'xl' : 'lg')}</div>` : ''}
          <div class="team">${esc(t.name)}</div>
          <div class="pts">${num(t.total)} <span class="small">pts</span></div>
        </div>`).join('')}</div>` : ''}

      ${anyPoints ? `<div class="card"><div class="card-body">${Charts.bars(s.map((t) => ({ label: t.name, value: t.total, display: App.pts(t.total), rank: t.rank, medal: medal(t), look: t })), { title: 'Overall points', caption: 'Total group points from every counted activity' })}</div></div>` : ''}

      <div class="card">
        <div class="card-head"><h3>Overall standings</h3>
          <span class="muted small">Points: ${r.placement_points.map((p, i) => ordinal(i + 1) + ' = ' + num(p)).join(', ')}${r.participation_points ? ', others = ' + num(r.participation_points) : ''}</span></div>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>Rank</th><th>Group</th>
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
