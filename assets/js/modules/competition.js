/* Bracket, round robin and ranking activities: visual board + result entry. */
(function () {
  'use strict';
  const { esc, num } = App;
  const Competition = (window.Competition = {});

  const roundName = (round, rounds) => {
    const left = rounds - round;
    if (left === 0) return 'Final';
    if (left === 1) return 'Semifinals';
    if (left === 2) return 'Quarterfinals';
    return 'Round of ' + 2 ** (left + 1);
  };

  const scoreText = (v) => (v === null || v === undefined ? '' : num(v));

  /* ================================================================ bracket */

  function bracketHtml(data, canManage, arranging = false) {
    const main = data.matches.filter((m) => m.stage === 'main');
    const third = data.matches.find((m) => m.stage === 'third');
    if (!main.length) return '';
    const rounds = Math.max(...main.map((m) => m.round));
    const final = main.find((m) => m.round === rounds);
    const champ = final && final.status === 'done' ? (final.winner_id === final.a?.id ? final.a : final.b) : null;

    const slot = (m, side) => {
      const c = m[side];
      const s = side === 'a' ? m.score_a : m.score_b;
      // a bye moves a team on, but it is not a win: only played matches show win / lose
      const played = m.status === 'done' && !m.is_bye && m.winner_id;
      const win = played && c && m.winner_id === c.id;
      const lose = played && c && m.winner_id !== c.id;
      if (!c) {
        return `<div class="b-slot empty"><span class="b-seed"></span><span class="avatar avatar-sm is-empty"></span><span class="b-text"><span class="b-name">TBD</span></span></div>`;
      }
      const noScore = s === null || s === undefined;
      const mark = win ? 'W' : lose ? 'L' : '';
      const movable = arranging && m.stage === 'main' && m.round === 1;
      return `<div class="b-slot ${win ? 'win' : ''} ${lose ? 'lose' : ''} ${c.color ? 'has-color' : ''} ${movable ? 'movable' : ''}"${c.color ? ` style="--entry:${esc(c.color)}"` : ''}${movable ? ` draggable="true" data-swap="${c.id}" role="button" tabindex="0" aria-label="Move ${esc(c.name)}"` : ''}>
        <span class="b-seed">${c.number}</span>
        ${App.avatar(c, 'sm')}
        <span class="b-text"><span class="b-name" title="${esc(c.name)}${c.team && c.team !== c.name ? ' · ' + esc(c.team) : ''}">${esc(c.name)}</span>
        ${c.team && c.team.trim().toLowerCase() !== c.name.trim().toLowerCase() ? `<span class="b-team">${esc(c.team)}</span>` : ''}</span>
        ${played ? `<span class="b-score ${win ? 'is-win' : 'is-lose'}">${noScore ? mark : `${scoreText(s)}<i>${mark}</i>`}</span>` : ''}</div>`;
    };
    /*
     * Classic bracket: every team is its own box. The two boxes of a match are joined by a line
     * (with the VS on it) that runs on to the winner's box in the next round.
     */
    const cell = (inner, cls = '') => `<div class="bk-cell ${cls}">${inner}</div>`;
    const pair = (m) => {
      // an empty spot of the bracket keeps the shape but shows nothing
      if (m.is_bye && m.status === 'done' && !m.a && !m.b) {
        return `<div class="bk-pair void" aria-hidden="true">${cell(slot({}, 'a'), 'ghost')}${cell(slot({}, 'b'), 'ghost')}</div>`;
      }
      const playable = !arranging && canManage && m.a && m.b && !m.is_bye && data.activity.status !== 'closed';
      const isVoid = (x) => x.is_bye && x.status === 'done' && !x.a && !x.b;
      const feeder = main.find((x) => x.next_match_id === m.id && !isVoid(x));
      const side = m.a ? 'a' : m.b ? 'b' : feeder && feeder.position % 2 === 0 ? 'b' : 'a';
      const cls = `bk-pair ${m.status === 'done' ? 'done' : ''} ${m.is_bye ? 'bye bye-' + side : ''} ${playable && m.status !== 'done' ? 'ready' : ''}`;
      // a bye keeps both places so the line still meets the right box in the next round; the empty side is hidden
      const byeTag = `<span class="b-bye">${m.status === 'done' ? 'Bye · advances' : 'Bye'}</span>`;
      const inner = m.is_bye
        ? (side === 'a' ? `${cell(slot(m, 'a') + byeTag)}${cell('', 'ghost')}` : `${cell('', 'ghost')}${cell(slot(m, 'b') + byeTag)}`)
        : `${cell(slot(m, 'a'))}${cell(slot(m, 'b'))}<span class="bk-vs" aria-hidden="true">VS</span>`;
      return playable
        ? `<button type="button" class="${cls}" data-match="${m.id}" aria-label="Record the result of ${esc(m.a.name)} vs ${esc(m.b.name)}">${inner}</button>`
        : `<div class="${cls}">${inner}</div>`;
    };

    let html = '<div class="bracket-scroll"><div class="bk">';
    for (let r = 1; r <= rounds; r++) {
      const ms = main.filter((m) => m.round === r).sort((x, y) => x.position - y.position);
      html += `<div class="bk-col">
        <div class="b-round-title">${roundName(r, rounds)}</div>
        <div class="bk-pairs">${ms.map(pair).join('')}</div>
      </div>`;
    }
    html += `<div class="bk-col bk-champ">
        <div class="b-round-title">Champion</div>
        <div class="bk-pairs"><div class="bk-pair solo-champ">${cell(`<div class="champion-card ${champ ? 'crowned' : ''}">
          ${App.icon('trophy')}${champ ? App.avatar(champ, 'lg') : ''}<strong>${champ ? esc(champ.name) : 'To be decided'}</strong>${champ && champ.team && champ.team !== champ.name ? `<small>${esc(champ.team)}</small>` : ''}
        </div>`, 'solo')}</div></div>
      </div>`;
    html += '</div></div>';
    if (third) {
      html += `<div class="third-place"><div class="b-round-title">3rd-place match</div><div class="bk bk-third"><div class="bk-col"><div class="bk-pairs">${pair(third)}</div></div></div></div>`;
    }
    return html;
  }
  function matchModal(data, match, onSaved) {
    const label = data.activity.score_label || 'Score';
    const isRR = match.stage === 'rr';
    return App.modal({
      title: isRR ? `Round ${match.round} result` : 'Match result',
      submitText: 'Save result',
      body: `
        ${isRR ? '' : `<div class="quick-win">
          <span class="field-label">Quick result: tap the winner (no score needed)</span>
          <div class="quick-win-row">
            <button type="button" class="btn quick-win-btn" data-quick="a">${App.avatar(match.a, 'sm')} <span>${esc(match.a.name)} wins</span></button>
            <button type="button" class="btn quick-win-btn" data-quick="b">${App.avatar(match.b, 'sm')} <span>${esc(match.b.name)} wins</span></button>
          </div>
          <div class="or-line"><span>or enter the score</span></div>
        </div>`}
        <div class="match-form">
          <div class="mf-side">
            ${App.avatar(match.a, 'xl')}<span class="muted small">No. ${match.a.number}</span>
            <strong>${esc(match.a.name)}</strong>${match.a.team && match.a.team !== match.a.name ? `<small>${esc(match.a.team)}</small>` : ''}
            <label class="field"><span>${esc(label)}</span><input type="number" name="score_a" min="0" step="any" inputmode="decimal" value="${scoreText(match.score_a)}" ${isRR ? 'required' : ''}></label>
          </div>
          <div class="mf-vs">vs</div>
          <div class="mf-side">
            ${App.avatar(match.b, 'xl')}<span class="muted small">No. ${match.b.number}</span>
            <strong>${esc(match.b.name)}</strong>${match.b.team && match.b.team !== match.b.name ? `<small>${esc(match.b.team)}</small>` : ''}
            <label class="field"><span>${esc(label)}</span><input type="number" name="score_b" min="0" step="any" inputmode="decimal" value="${scoreText(match.score_b)}" ${isRR ? 'required' : ''}></label>
          </div>
        </div>
        ${isRR ? '<p class="hint">Equal scores are recorded as a draw.</p>' : `
        <div class="field" style="margin-top:14px"><span class="field-label">Who advances?</span>
          <div class="segmented" data-winner>
            <button type="button" data-w="">By score</button>
            <button type="button" data-w="a">${esc(match.a.name)}</button>
            <button type="button" data-w="b">${esc(match.b.name)}</button>
          </div>
          <input type="hidden" name="winner" value="">
          <p class="hint">Leave on “By score”, or pick the winner for a tie decided by overtime, a tiebreak or default.</p>
        </div>`}
        ${match.status === 'done' ? `<p style="margin-top:12px"><button type="button" class="btn btn-sm btn-danger" data-clear>${App.icon('x')} Clear this result</button></p>` : ''}`,
      onOpen: (form, dlg, close) => {
        const seg = form.querySelector('[data-winner]');
        if (seg) {
          const hidden = form.querySelector('[name=winner]');
          const current = match.status === 'done' && match.winner_id && match.score_a !== null && match.score_a === match.score_b ? (match.winner_id === match.a.id ? 'a' : 'b') : '';
          const set = (w) => {
            hidden.value = w;
            seg.querySelectorAll('button').forEach((b) => b.classList.toggle('active', b.dataset.w === w));
          };
          seg.querySelectorAll('button').forEach((b) => b.addEventListener('click', () => set(b.dataset.w)));
          set(current);
        }
        form.querySelectorAll('[data-quick]').forEach((b) => b.addEventListener('click', async () => {
          App.setLoading(b, true);
          try {
            const r = await App.post('competition.match', { match_id: match.id, score_a: null, score_b: null, winner: b.dataset.quick });
            App.toast(r.message, 'success');
            close(undefined);
            onSaved(r);
          } catch (err) {
            App.fail(err);
            App.setLoading(b, false);
          }
        }));
        form.querySelector('[data-clear]')?.addEventListener('click', async () => {
          try {
            const r = await App.post('competition.clear', { match_id: match.id });
            App.toast(r.message, 'success');
            close(undefined);
            onSaved(r);
          } catch (err) { App.fail(err); }
        });
      },
      onSubmit: async (d) => {
        const r = await App.post('competition.match', {
          match_id: match.id,
          score_a: d.score_a === '' ? null : d.score_a,
          score_b: d.score_b === '' ? null : d.score_b,
          winner: d.winner || '',
        });
        App.toast(r.message, 'success');
        onSaved(r);
      },
    });
  }

  /* ================================================================ round robin */

  function roundRobinHtml(data, canManage) {
    const label = data.activity.score_label || 'Score';
    const s = data.standings;
    const played = s.some((r) => r.played > 0);
    const rounds = [...new Set(data.matches.map((m) => m.round))].sort((a, b) => a - b);
    const done = data.matches.filter((m) => m.status === 'done').length;
    const locked = data.activity.status === 'closed';

    return `
      ${played ? Charts.podium(s.map((r) => ({ rank: r.rank, name: r.name, sub: r.team_name !== r.name ? r.team_name : '', value: App.pts(r.points), color: r.color, photo: r.photo }))) : ''}
      <div class="comp-grid">
        <div class="card">
          <div class="card-head"><div><h3>Standings</h3><div class="muted small">Win ${3} · Draw 1 · Loss 0 · then ${esc(label.toLowerCase())} difference</div></div>
            <span class="muted small">${done}/${data.matches.length} games played</span></div>
          <div class="table-wrap"><table class="table">
            <thead><tr><th>Rank</th><th>Team / contestant</th><th class="num">P</th><th class="num">W</th><th class="num">D</th><th class="num">L</th><th class="num">+/−</th><th class="num">Pts</th></tr></thead>
            <tbody>${s.map((r) => `<tr class="${r.rank && r.rank <= 3 && played ? 'rank-' + r.rank : ''}">
              <td>${r.rank && played ? `<span class="rank-pill ${r.rank <= 3 ? 'r' + r.rank : ''}">${r.rank}</span>` : '<span class="muted">—</span>'}</td>
              <td class="name-col">${App.entry(r, r.team_name && r.team_name !== r.name ? r.team_name : '')}</td>
              <td class="num">${r.played}</td><td class="num">${r.won}</td><td class="num">${r.drawn}</td><td class="num">${r.lost}</td>
              <td class="num">${r.diff > 0 ? '+' : ''}${num(r.diff)}</td><td class="num"><strong>${r.points}</strong></td></tr>`).join('')}
            </tbody></table></div>
          ${played ? `<div class="card-body" style="border-top:1px solid var(--gray-100)">${Charts.bars(s.map((r) => ({ label: r.name, sublabel: r.team_name !== r.name ? r.team_name : '', value: r.points, display: App.pts(r.points), rank: r.rank, look: r })), { title: 'Points', caption: 'Standings points per team' })}</div>` : ''}
        </div>
        <div class="card">
          <div class="card-head"><h3>Fixtures</h3>${canManage && !locked ? '<span class="muted small">Tap a game to enter the result</span>' : ''}</div>
          <div class="card-body">
            ${rounds.map((round) => `
              <div class="rr-round"><div class="b-round-title">Round ${round}</div>
                ${data.matches.filter((m) => m.round === round).map((m) => {
                  const aWin = m.status === 'done' && m.winner_id === m.a?.id;
                  const bWin = m.status === 'done' && m.winner_id === m.b?.id;
                  const inner = `
                    <span class="rr-team ${aWin ? 'win' : ''}">${m.a ? App.avatar(m.a, 'xs') : ''}<span>${esc(m.a?.name || 'TBD')}</span></span>
                    <span class="rr-score">${m.status === 'done' ? `${scoreText(m.score_a)} <small>vs</small> ${scoreText(m.score_b)}` : 'vs'}</span>
                    <span class="rr-team right ${bWin ? 'win' : ''}"><span>${esc(m.b?.name || 'TBD')}</span>${m.b ? App.avatar(m.b, 'xs') : ''}</span>`;
                  return canManage && !locked
                    ? `<button type="button" class="rr-game ${m.status === 'done' ? 'done' : ''}" data-match="${m.id}">${inner}</button>`
                    : `<div class="rr-game ${m.status === 'done' ? 'done' : ''}">${inner}</div>`;
                }).join('')}
              </div>`).join('')}
          </div>
        </div>
      </div>`;
  }

  /* ================================================================ ranking */

  function rankingHtml(data, canManage) {
    const label = data.activity.score_label || 'Result';
    const asc = data.activity.rank_direction === 'asc';
    const s = data.standings;
    const ranked = s.filter((r) => r.rank);
    const locked = data.activity.status === 'closed';
    const values = ranked.map((r) => r.value);
    return `
      ${ranked.length ? Charts.podium(ranked.map((r) => ({ rank: r.rank, name: r.name, sub: r.team_name !== r.name ? r.team_name : '', value: num(r.value, 3) + ' ' + label, color: r.color, photo: r.photo }))) : ''}
      <div class="comp-grid">
        <div class="card">
          <div class="card-head"><div><h3>Leaderboard</h3><div class="muted small">${asc ? 'Lower' : 'Higher'} ${esc(label.toLowerCase())} ranks first · ties share a rank</div></div></div>
          <div class="card-body">
            ${ranked.length
              ? Charts.bars(ranked.map((r) => ({ label: r.name, sublabel: r.team_name !== r.name ? r.team_name : '', value: r.value, display: num(r.value, 3) + ' ' + label, rank: r.rank, look: r })),
                  { title: label, caption: asc ? 'Shorter bar is better' : 'Longer bar is better', max: Math.max(...values) })
              : App.empty('No results yet', canManage ? 'Enter each contestant’s result on the right.' : 'Results will appear here.', 'trophy')}
          </div>
        </div>
        <div class="card">
          <div class="card-head"><h3>Results</h3>${canManage && !locked ? `<button type="button" class="btn btn-sm btn-primary" data-save-results>${App.icon('check')} Save results</button>` : ''}</div>
          <div class="table-wrap"><table class="table">
            <thead><tr><th>Rank</th><th>Contestant</th><th class="num">${esc(label)}</th><th>Remarks</th></tr></thead>
            <tbody>${[...s].sort((x, y) => x.number - y.number).map((r) => `<tr>
              <td>${r.rank ? `<span class="rank-pill ${r.rank <= 3 ? 'r' + r.rank : ''}">${r.rank}</span>` : '<span class="muted">—</span>'}</td>
              <td class="name-col">${App.entry(r, r.team_name && r.team_name !== r.name ? r.team_name : '')}</td>
              <td class="num">${canManage && !locked ? `<input class="result-input" type="number" step="any" inputmode="decimal" data-result="${r.id}" value="${r.value ?? ''}" aria-label="${esc(label)} for ${esc(r.name)}">` : (r.value === null ? '—' : num(r.value, 3))}</td>
              <td>${canManage && !locked ? `<input data-remarks="${r.id}" maxlength="255" value="${esc(r.remarks || '')}" placeholder="Optional" aria-label="Remarks for ${esc(r.name)}">` : esc(r.remarks || '')}</td>
            </tr>`).join('')}</tbody></table></div>
        </div>
      </div>`;
  }

  /* ================================================================ mount */

  /**
   * opts: { activityId, canManage, onChange() }
   */
  Competition.mount = async (root, opts) => {
    let data = null;
    let arranging = false;
    let picked = null;

    async function load() {
      data = await App.get('competition.get', { id: opts.activityId });
      render();
    }

    function render() {
      const a = data.activity;
      const locked = a.status === 'closed';
      const needsMatches = a.format === 'bracket' || a.format === 'round_robin';
      const hasMatches = data.matches.length > 0;
      const played = data.matches.some((m) => m.status === 'done' && !m.is_bye);
      const enough = data.contestants.length >= 2;

      let body = '';
      if (needsMatches && !hasMatches) {
        body = `<div class="card">${App.empty(
          a.format === 'bracket' ? 'No bracket yet' : 'No fixtures yet',
          enough ? `${data.contestants.length} contestants are ready. Create the ${a.format === 'bracket' ? 'bracket' : 'round robin fixtures'} to start.` : 'Add at least 2 contestants first (Contestants tab).',
          'trophy')}
          ${opts.canManage && enough ? `<div class="row" style="justify-content:center;padding-bottom:28px">
            ${a.format === 'bracket' ? `<button class="btn" data-generate="random">${App.icon('refresh')} Random draw</button>` : ''}
            <button class="btn btn-primary" data-generate="number">${App.icon('plus')} ${a.format === 'bracket' ? 'Create bracket (1 vs 2, 3 vs 4…)' : 'Create fixtures'}</button></div>` : ''}
        </div>`;
      } else if (a.format === 'bracket') {
        body = `
          ${data.placements.complete ? Charts.podium(data.placements.rows.map((r) => ({ rank: r.rank, name: r.name, sub: r.team_name !== r.name ? r.team_name : '', value: r.display, color: r.color, photo: r.photo }))) : ''}
          <div class="card bracket-card">
            <div class="card-head">
              <div><h3>${App.icon('trophy')} Bracket</h3><div class="muted small">Single elimination · ${data.contestants.length} contestants${arranging ? ' · <strong>drag a team onto another to swap them</strong> (or tap one, then the other)' : opts.canManage && !locked ? ' · click a match to record the result' : ''}</div></div>
              <div class="row">
                ${opts.canManage && !locked && !played ? `<button class="btn btn-sm ${arranging ? 'btn-dark' : ''}" data-arrange>${App.icon(arranging ? 'check' : 'move')} ${arranging ? 'Done arranging' : 'Arrange teams'}</button>` : ''}
                ${opts.canManage ? `<button class="btn btn-sm" data-pictures>${App.icon('image')} Pictures</button>` : ''}
                ${opts.canManage && !locked ? `<button class="btn btn-sm" data-regenerate>${App.icon('refresh')} Recreate bracket</button>` : ''}
                <button class="btn btn-sm ${isFull() ? 'btn-dark' : ''}" data-fullscreen>${App.icon(isFull() ? 'shrink' : 'expand')} ${isFull() ? 'Exit full screen' : 'Full screen'}</button>
              </div>
            </div>
            ${opts.canManage && !data.contestants.some((c) => c.photo) ? `<div class="bracket-tip">${App.icon('image')}<span>No logos yet, so the circles show initials. Use <strong>Pictures</strong> to add each team's logo.</span></div>` : ''}
            <div class="card-body ${arranging ? 'is-arranging' : ''}">${bracketHtml(data, opts.canManage, arranging && !played)}</div>
          </div>
          ${placementsTable(data)}`;
      } else if (a.format === 'round_robin') {
        body = `${roundRobinHtml(data, opts.canManage)}
          ${opts.canManage && !locked ? `<p class="text-right" style="margin-top:12px"><button class="btn btn-sm" data-regenerate>${App.icon('refresh')} Recreate fixtures</button></p>` : ''}`;
      } else {
        body = rankingHtml(data, opts.canManage);
      }

      root.innerHTML = `${locked ? `<div class="alert alert-info" style="margin-bottom:14px">${App.icon('lock')} This activity is closed — results are final.</div>` : ''}${body}`;
      Charts.bind(root);
      bind(played);
    }

    function placementsTable(d) {
      const rows = d.placements.rows.filter((r) => r.rank);
      if (!rows.length) return '';
      return `<div class="card"><div class="card-head"><h3>Placements</h3><span class="muted small">${d.placements.complete ? 'Final' : 'So far'}</span></div>
        <div class="table-wrap"><table class="table stackable"><thead><tr><th>Rank</th><th>Contestant</th><th>Result</th></tr></thead>
        <tbody>${rows.map((r) => `<tr><td data-label="Rank"><span class="rank-pill ${r.rank <= 3 ? 'r' + r.rank : ''}">${r.rank}</span></td>
          <td class="primary" data-label="Contestant">${App.entry(r, r.team_name && r.team_name !== r.name ? r.team_name : '')}</td>
          <td data-label="Result">${esc(r.display)}</td></tr>`).join('')}</tbody></table></div></div>`;
    }

    /* full screen: the whole board goes full screen, showing only the bracket (it survives re-renders) */
    const fsElement = () => document.fullscreenElement || document.webkitFullscreenElement || null;
    function isFull() { return root.classList.contains('comp-full'); }
    function syncFull() {
      const on = fsElement() === root || root.classList.contains('comp-full-fallback');
      root.classList.toggle('comp-full', on);
      const btn = root.querySelector('[data-fullscreen]');
      if (btn) {
        btn.classList.toggle('btn-dark', on);
        btn.innerHTML = `${App.icon(on ? 'shrink' : 'expand')} ${on ? 'Exit full screen' : 'Full screen'}`;
      }
    }
    async function toggleFull() {
      if (isFull()) {
        root.classList.remove('comp-full-fallback');
        if (fsElement()) await (document.exitFullscreen || document.webkitExitFullscreen).call(document);
        return syncFull();
      }
      const request = root.requestFullscreen || root.webkitRequestFullscreen;
      try {
        if (!request) throw new Error('unsupported');
        await request.call(root);
      } catch {
        root.classList.add('comp-full-fallback'); // phones without the Fullscreen API: fill the window instead
      }
      syncFull();
    }
    document.addEventListener('fullscreenchange', syncFull);
    document.addEventListener('webkitfullscreenchange', syncFull);
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && root.classList.contains('comp-full-fallback') && !document.querySelector('dialog[open]')) {
        root.classList.remove('comp-full-fallback');
        syncFull();
      }
    });

    function bind(played) {
      root.querySelector('[data-fullscreen]')?.addEventListener('click', toggleFull);
      root.querySelector('[data-arrange]')?.addEventListener('click', () => {
        arranging = !arranging;
        picked = null;
        render();
      });
      bindArrange();
      root.querySelector('[data-pictures]')?.addEventListener('click', async () => {
        const changed = await Forms.entryPictures(data.contestants, {
          staff: App.user?.kind === 'staff',
          refresh: async () => (await App.get('competition.get', { id: opts.activityId })).contestants,
        });
        if (changed) load();
      });
      root.querySelectorAll('[data-generate]').forEach((b) => b.addEventListener('click', () => generate(b, b.dataset.generate, false)));
      root.querySelector('[data-regenerate]')?.addEventListener('click', async (e) => {
        const a = data.activity;
        const ok = await App.confirm({
          title: a.format === 'bracket' ? 'Recreate the bracket?' : 'Recreate the fixtures?',
          message: played ? 'All recorded match results will be <strong>erased</strong>. Use this when contestants changed.' : 'The matches will be rebuilt from the current contestants.',
          confirmText: 'Recreate', danger: played,
        });
        if (ok) generate(e.currentTarget, 'number', true);
      });
      root.querySelectorAll('[data-match]').forEach((b) => b.addEventListener('click', () => {
        const m = data.matches.find((x) => x.id === Number(b.dataset.match));
        matchModal(data, m, (r) => { data = r; render(); opts.onChange && opts.onChange(); });
      }));
      root.querySelector('[data-save-results]')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        const results = [...root.querySelectorAll('[data-result]')].map((inp) => ({
          contestant_id: Number(inp.dataset.result),
          value: inp.value,
          remarks: root.querySelector(`[data-remarks="${inp.dataset.result}"]`)?.value || '',
        }));
        App.setLoading(btn, true);
        try {
          const r = await App.post('competition.results', { id: opts.activityId, results });
          App.toast(r.message, 'success');
          data = r;
          render();
          opts.onChange && opts.onChange();
        } catch (err) {
          App.fail(err);
          App.setLoading(btn, false);
        }
      });
    }

    /* arrange teams: drag one team onto another (or tap one, then the other) to swap their places */
    function bindArrange() {
      const tiles = [...root.querySelectorAll('[data-swap]')];
      if (!tiles.length) return;
      const swap = async (first, second) => {
        picked = null;
        if (!first || !second || first === second) return render();
        root.querySelector('.bracket-card .card-body')?.classList.add('is-busy');
        try {
          const r = await App.post('competition.swap', { id: opts.activityId, first, second });
          data = r;
          render();
          opts.onChange && opts.onChange();
        } catch (err) {
          App.fail(err);
          render();
        }
      };
      tiles.forEach((t) => {
        const id = Number(t.dataset.swap);
        t.addEventListener('dragstart', (e) => {
          e.dataTransfer.setData('text/plain', String(id));
          e.dataTransfer.effectAllowed = 'move';
          t.classList.add('dragging');
        });
        t.addEventListener('dragend', () => t.classList.remove('dragging'));
        t.addEventListener('dragover', (e) => { e.preventDefault(); t.classList.add('drop-target'); });
        t.addEventListener('dragleave', () => t.classList.remove('drop-target'));
        t.addEventListener('drop', (e) => {
          e.preventDefault();
          t.classList.remove('drop-target');
          swap(Number(e.dataTransfer.getData('text/plain')), id);
        });
        const tap = () => {
          if (picked === null) {
            picked = id;
            t.classList.add('picked');
          } else {
            swap(picked, id);
          }
        };
        t.addEventListener('click', tap);
        t.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); tap(); } });
      });
    }

    async function generate(btn, seeding, force) {
      App.setLoading(btn, true);
      try {
        const r = await App.post('competition.generate', { id: opts.activityId, seeding, force: force ? 1 : 0 });
        App.toast(r.message, 'success');
        data = r;
        render();
        opts.onChange && opts.onChange();
      } catch (err) {
        App.fail(err);
        App.setLoading(btn, false);
      }
    }

    root.innerHTML = App.loading();
    try { await load(); } catch (err) { root.innerHTML = `<div class="alert alert-error">${esc(err.message)}</div>`; }
    return { reload: load };
  };

  Competition.roundName = roundName;
  /** Read-only bracket (public results page and big screen). */
  Competition.bracketView = (data) => bracketHtml(data, false);
})();
