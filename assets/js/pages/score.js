/* Judge score sheet: per-contestant scoring with autosave; each contestant is submitted on its own. */
(async function () {
  'use strict';
  const me = await App.boot({ roles: ['judge'], layout: 'judge' });
  const { esc, num } = App;
  const view = App.$('#view');
  const activityId = Number(App.param('activity') || 0);
  const storeKey = `coc-tab:${me.user.id}:${activityId}`;

  let sheet = null;
  const scores = new Map();   // "cid:crid" -> number
  const pending = new Map();  // "cid:crid" -> {contestant_id, criterion_id, score}
  let index = 0;
  let mode = 'score';
  let saveState = { status: 'idle', at: null };
  let flushTimer = null;
  let retryTimer = null;

  const key = (cid, crid) => cid + ':' + crid;
  const editable = () => sheet.activity.status === 'open' && !sheet.submitted_at;
  /** Contestant colour (own or team), with a readable text colour on top of it. */
  const look = (c) => {
    const color = /^#[0-9a-f]{6}$/i.test(c.color || '') ? c.color : '#00461B';
    return `--entry:${color};--entry-ink:${App.textOn(color)};--entry-soft:${color}1F`;
  };
  const isSubmitted = (c) => (sheet.submitted_contestants || []).includes(c.id);
  const canEdit = (c) => editable() && !isSubmitted(c);
  const storage = {
    get(k, d) { try { const v = localStorage.getItem(k); return v === null ? d : JSON.parse(v); } catch (_) { return d; } },
    set(k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (_) { /* private mode */ } },
    del(k) { try { localStorage.removeItem(k); } catch (_) { /* ignore */ } },
  };

  /* ---------------------------------------------------------------- data */

  async function load() {
    sheet = await App.get('scores.sheet', { activity_id: activityId });
    scores.clear();
    sheet.scores.forEach((s) => scores.set(key(s.contestant_id, s.criterion_id), Number(s.score)));

    // Restore scores typed while offline (not yet saved on the server)
    const saved = storage.get(storeKey + ':pending', []);
    if (saved.length && editable()) {
      saved.forEach((p) => {
        pending.set(key(p.contestant_id, p.criterion_id), p);
        if (p.score === null) scores.delete(key(p.contestant_id, p.criterion_id));
        else scores.set(key(p.contestant_id, p.criterion_id), p.score);
      });
      scheduleFlush(50);
    } else if (!editable()) {
      storage.del(storeKey + ':pending');
    }
    index = Math.min(storage.get(storeKey + ':index', 0), Math.max(0, sheet.contestants.length - 1));
    render();
  }

  const contestantProgress = (c) => sheet.criteria.filter((cr) => scores.has(key(c.id, cr.id))).length;
  const contestantTotal = (c) => sheet.criteria.reduce((s, cr) => s + (scores.get(key(c.id, cr.id)) || 0), 0);
  const totalScored = () => sheet.contestants.reduce((s, c) => s + contestantProgress(c), 0);
  const expected = () => sheet.contestants.length * sheet.criteria.length;

  /* ---------------------------------------------------------------- render */

  function render() {
    const a = sheet.activity;
    document.title = a.title + ' · Score sheet';
    const maxTotal = sheet.criteria.reduce((s, c) => s + Number(c.max_score), 0);

    let banner = '';
    if (sheet.submitted_at) banner = `<div class="lock-banner green">${App.icon('check')}<div><strong>Scores submitted</strong><div class="small">Submitted ${esc(App.fmtDateTime(sheet.submitted_at))}. Ask the facilitator if a correction is needed.</div></div></div>`;
    else if (a.status === 'pending') banner = `<div class="lock-banner">${App.icon('lock')}<div><strong>Scoring has not opened yet</strong><div class="small">You can review the criteria and contestants. This page unlocks automatically when scoring opens.</div></div></div>`;
    else if (a.status === 'closed') banner = `<div class="lock-banner">${App.icon('lock')}<div><strong>Scoring is closed</strong><div class="small">Scores can no longer be changed.</div></div></div>`;

    view.innerHTML = `
      <div class="row-between" style="margin-bottom:10px">
        <a href="${App.page('judge.html')}" class="btn btn-sm btn-ghost">${App.icon('chevronLeft')} My activities</a>
        ${App.badge(a.status)}
      </div>
      <h1>${esc(a.title)}</h1>
      <div class="row muted small" style="margin:4px 0 14px">
        ${a.venue ? `<span>${esc(a.venue)}</span>` : ''}
        <span>${sheet.contestants.length} contestants · ${sheet.criteria.length} criteria · ${num(maxTotal)} pts</span>
        ${a.has_file ? `<a href="${App.apiUrl('criteria.file', { activity_id: activityId })}" target="_blank" rel="noopener">${App.icon('file')} Criteria document</a>` : ''}
      </div>
      ${banner}

      ${!sheet.contestants.length || !sheet.criteria.length
        ? `<div class="card">${App.empty('Not ready yet', 'Contestants or criteria have not been set up for this activity.', 'list')}</div>`
        : `
        <div class="segmented" style="margin-bottom:6px">
          <button type="button" data-mode="score" class="${mode === 'score' ? 'active' : ''}">Score contestants</button>
          <button type="button" data-mode="summary" class="${mode === 'summary' ? 'active' : ''}">Summary</button>
        </div>
        <div id="mode-root"></div>`}
    `;

    App.$$('[data-mode]', view).forEach((b) => b.addEventListener('click', () => {
      mode = b.dataset.mode;
      render();
    }));

    if (sheet.contestants.length && sheet.criteria.length) {
      if (mode === 'score') renderScoring();
      else renderSummary();
    }
    renderBar();
  }

  function renderScoring() {
    const root = App.$('#mode-root', view);
    const c = sheet.contestants[index];
    const locked = !canEdit(c);
    const complete = contestantProgress(c) === sheet.criteria.length;

    root.innerHTML = `
      <div class="contestant-strip" role="tablist" aria-label="Contestants">
        ${sheet.contestants.map((x, i) => {
          const done = contestantProgress(x);
          const cls = isSubmitted(x) ? 'submitted' : done === sheet.criteria.length ? 'complete' : done ? 'partial' : '';
          return `<button type="button" class="c-chip ${cls} ${i === index ? 'active' : ''}" data-go="${i}" aria-selected="${i === index}" style="${look(x)}">
            <span class="n">${isSubmitted(x) ? App.icon('check') : x.number}</span><span>${esc(x.name.length > 18 ? x.name.slice(0, 17) + '…' : x.name)}</span></button>`;
        }).join('')}
      </div>

      <article class="card score-card is-colored" style="${look(c)}">
        <div class="card-body">
          <div class="contestant-head stacked">
            ${c.photo || c.color ? `<span class="contestant-look">${App.avatar(c, 'hero')}<span class="contestant-no small">${c.number}</span></span>` : `<span class="contestant-no">${c.number}</span>`}
            <div class="contestant-name">
              <h2>${esc(c.name)}</h2>
              ${c.team_name && c.team_name !== c.name ? `<div class="muted">${esc(c.team_name)}</div>` : ''}
              ${c.details ? `<div class="muted small">${esc(c.details)}</div>` : ''}
            </div>
          </div>
          ${isSubmitted(c) ? `<div class="contestant-done">${App.icon('check')}<span><strong>Submitted</strong> · these scores are locked. Ask the facilitator if a correction is needed.</span></div>` : ''}
          <div style="margin-top:14px">
            ${sheet.criteria.map((cr, i) => {
              const v = scores.get(key(c.id, cr.id));
              const max = Number(cr.max_score);
              const step = max >= 20 ? 1 : 0.5;
              return `
              <div class="score-item">
                <div class="top">
                  <div><div class="name">${i + 1}. ${esc(cr.name)}</div>${cr.description ? `<div class="desc">${esc(cr.description)}</div>` : ''}</div>
                  <span class="score-max">max ${num(max)}</span>
                </div>
                <div class="score-entry">
                  <input type="range" min="0" max="${max}" step="${step}" value="${v ?? 0}" data-range="${cr.id}" ${locked ? 'disabled' : ''} aria-label="${esc(cr.name)} slider">
                  <input type="number" min="0" max="${max}" step="0.01" inputmode="decimal" enterkeyhint="next" placeholder="—"
                         value="${v ?? ''}" data-score="${cr.id}" ${locked ? 'disabled' : ''} aria-label="${esc(cr.name)} score out of ${num(max)}">
                </div>
              </div>`;
            }).join('')}
          </div>
          <div class="subtotal"><span>Total for ${esc(c.name)}</span><strong data-subtotal>${num(contestantTotal(c))}</strong></div>
        </div>
      </article>

      <div class="row-between contestant-actions" style="margin-top:14px">
        <button class="btn btn-lg" data-prev ${index === 0 ? 'disabled' : ''}>${App.icon('chevronLeft')} Previous</button>
        ${isSubmitted(c)
          ? `<span class="submitted-pill">${App.icon('check')} ${esc(c.name)} submitted</span>`
          : editable()
            ? `<button class="btn btn-lg btn-primary" data-submit-contestant ${complete ? '' : 'disabled'} title="${complete ? '' : 'Score every criterion first'}">${App.icon('check')} Submit ${esc(c.name)}</button>`
            : ''}
      </div>`;

    // scroll active chip into view
    root.querySelector('.c-chip.active')?.scrollIntoView({ block: 'nearest', inline: 'center' });

    root.querySelectorAll('[data-go]').forEach((b) => b.addEventListener('click', () => go(Number(b.dataset.go))));
    root.querySelector('[data-prev]')?.addEventListener('click', () => go(index - 1));
    root.querySelector('[data-submit-contestant]')?.addEventListener('click', (e) => submitContestant(c, e.currentTarget));

    const numbers = root.querySelectorAll('[data-score]');
    numbers.forEach((input, i) => {
      const crid = Number(input.dataset.score);
      const range = root.querySelector(`[data-range="${crid}"]`);
      const max = Number(input.max);

      input.addEventListener('input', () => {
        const raw = input.value.trim();
        if (raw === '') {
          input.classList.remove('invalid');
          setScore(c.id, crid, null);
          range.value = 0;
          return;
        }
        const val = Number(raw);
        const ok = !isNaN(val) && val >= 0 && val <= max;
        input.classList.toggle('invalid', !ok);
        if (!ok) {
          App.toast(`Score must be between 0 and ${num(max)}.`, 'error', 2500);
          return;
        }
        range.value = val;
        setScore(c.id, crid, Math.round(val * 100) / 100);
      });
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          if (numbers[i + 1]) numbers[i + 1].focus();
          else input.blur();
        }
      });
      range.addEventListener('input', () => {
        input.value = range.value;
        input.classList.remove('invalid');
        setScore(c.id, crid, Number(range.value));
      });
    });
  }

  function go(i) {
    if (i < 0 || i >= sheet.contestants.length) return;
    index = i;
    storage.set(storeKey + ':index', index);
    renderScoring();
    renderBar();
    App.$('.score-card', view)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function renderSummary() {
    const root = App.$('#mode-root', view);
    const maxTotal = sheet.criteria.reduce((s, c) => s + Number(c.max_score), 0);
    const totals = sheet.contestants.map((c) => ({ c, total: contestantTotal(c), complete: contestantProgress(c) === sheet.criteria.length }));
    const sorted = [...totals].filter((t) => t.complete).sort((a, b) => b.total - a.total);
    const rankOf = new Map();
    sorted.forEach((t, i) => rankOf.set(t.c.id, i > 0 && Math.abs(t.total - sorted[i - 1].total) < 0.0005 ? rankOf.get(sorted[i - 1].c.id) : i + 1));

    root.innerHTML = `
      <div class="card" style="margin-top:12px">
        <div class="card-head"><div><h3>Your scores</h3><div class="muted small">Tap a row to edit. Your personal ranking is only a preview — official results average all judges.</div></div></div>
        <div class="table-wrap">
          <table class="table">
            <thead><tr><th>No.</th><th>Contestant</th>
              ${sheet.criteria.map((cr) => `<th class="num">${esc(cr.name)}<br><span class="muted">/${num(cr.max_score)}</span></th>`).join('')}
              <th class="num">Total<br><span class="muted">/${num(maxTotal)}</span></th><th class="num">Your rank</th></tr></thead>
            <tbody>
              ${totals.map(({ c, total, complete }, i) => `
                <tr data-row="${i}" style="cursor:pointer">
                  <td class="num">${c.number}</td>
                  <td><strong>${esc(c.name)}</strong>${isSubmitted(c) ? ' <span class="badge badge-open no-dot">Submitted</span>' : complete ? '' : ' <span class="badge badge-pending no-dot">Incomplete</span>'}</td>
                  ${sheet.criteria.map((cr) => { const v = scores.get(key(c.id, cr.id)); return `<td class="num">${v === undefined ? '<span class="muted">—</span>' : num(v)}</td>`; }).join('')}
                  <td class="num"><strong>${num(total)}</strong></td>
                  <td class="num">${rankOf.has(c.id) ? `<span class="rank-pill ${rankOf.get(c.id) <= 3 ? 'r' + rankOf.get(c.id) : ''}">${rankOf.get(c.id)}</span>` : '—'}</td>
                </tr>`).join('')}
            </tbody>
          </table>
        </div>
      </div>`;
    root.querySelectorAll('[data-row]').forEach((tr) => tr.addEventListener('click', () => {
      mode = 'score';
      index = Number(tr.dataset.row);
      storage.set(storeKey + ':index', index);
      render();
    }));
  }

  function renderBar() {
    let bar = App.$('.judge-bar');
    if (!bar) {
      bar = App.h('<div class="judge-bar"><div class="inner"></div></div>');
      document.body.appendChild(bar);
    }
    if (!sheet.contestants.length || !sheet.criteria.length) {
      bar.hidden = true;
      return;
    }
    bar.hidden = false;
    const done = totalScored();
    const exp = expected();
    const complete = done >= exp;
    let saveText = 'All changes saved';
    let dot = '';
    if (saveState.status === 'saving' || pending.size) { saveText = 'Saving…'; dot = 'saving'; }
    if (saveState.status === 'error') { saveText = 'Offline — will retry'; dot = 'error'; }
    if (saveState.status === 'idle' && !pending.size && saveState.at) saveText = 'Saved ' + saveState.at;

    const sent = (sheet.submitted_contestants || []).length;
    bar.querySelector('.inner').innerHTML = `
      <div class="status-text">
        <div><b>${done}/${exp}</b> scores entered</div>
        ${editable() ? `<div><span class="save-dot ${dot}"></span>${esc(saveText)}</div>` : `<div>${sheet.submitted_at ? 'All submitted' : 'View only'}</div>`}
      </div>
      <div class="submitted-count"><b>${sent}/${sheet.contestants.length}</b><span>contestants submitted</span></div>`;
    void complete;
  }

  /* ---------------------------------------------------------------- saving */

  function setScore(cid, crid, value) {
    if (!editable()) return;
    const k = key(cid, crid);
    if (value === null) scores.delete(k);
    else scores.set(k, value);
    pending.set(k, { contestant_id: cid, criterion_id: crid, score: value });
    storage.set(storeKey + ':pending', [...pending.values()]);

    // live UI updates without re-rendering inputs
    const c = sheet.contestants[index];
    const sub = App.$('[data-subtotal]', view);
    if (sub && c && c.id === cid) sub.textContent = num(contestantTotal(c));
    const chip = App.$(`.c-chip[data-go="${index}"]`, view);
    if (chip) {
      const doneCount = contestantProgress(c);
      chip.classList.toggle('complete', doneCount === sheet.criteria.length);
      chip.classList.toggle('partial', doneCount > 0 && doneCount < sheet.criteria.length);
    }
    const submitBtn = App.$('[data-submit-contestant]', view);
    if (submitBtn && c) {
      const ready = contestantProgress(c) === sheet.criteria.length;
      submitBtn.disabled = !ready;
      submitBtn.title = ready ? '' : 'Score every criterion first';
    }
    scheduleFlush();
    renderBar();
  }

  function scheduleFlush(ms = 700) {
    clearTimeout(flushTimer);
    flushTimer = setTimeout(flush, ms);
  }

  async function flush() {
    if (!pending.size || saveState.status === 'saving') return;
    const batch = [...pending.entries()];
    saveState.status = 'saving';
    renderBar();
    try {
      const r = await App.post('scores.save', { activity_id: activityId, scores: batch.map(([, v]) => v) });
      batch.forEach(([k, v]) => {
        if (pending.get(k) === v) pending.delete(k);
      });
      storage.set(storeKey + ':pending', [...pending.values()]);
      if (!pending.size) storage.del(storeKey + ':pending');
      saveState = { status: 'idle', at: r.saved_at };
      clearTimeout(retryTimer);
      if (pending.size) scheduleFlush(200);
    } catch (err) {
      if (err.status === 423 || err.status === 422) {
        // Scoring closed / locked, or invalid data: stop retrying and reload the truth from the server.
        pending.clear();
        storage.del(storeKey + ':pending');
        saveState = { status: 'idle', at: null };
        App.fail(err);
        await load();
        return;
      }
      saveState.status = 'error';
      clearTimeout(retryTimer);
      retryTimer = setTimeout(() => { saveState.status = 'idle'; flush(); }, 5000);
    }
    renderBar();
  }

  async function submitContestant(c, btn) {
    await flush();
    if (pending.size) return App.toast('Some scores are not saved yet. Check your connection and try again.', 'error');
    const rows = sheet.criteria.map((cr) => `<tr><td>${esc(cr.name)}</td><td class="num"><strong>${num(scores.get(key(c.id, cr.id)) ?? 0)}</strong> / ${num(cr.max_score)}</td></tr>`).join('');
    const ok = await App.modal({
      title: `Submit ${c.name}?`,
      submitText: 'Submit scores',
      onSubmit: () => true,
      body: `<p>After submitting you can no longer change the scores for <strong>${esc(c.name)}</strong>.</p>
        <div class="table-wrap"><table class="table"><thead><tr><th>Criterion</th><th class="num">Score</th></tr></thead>
        <tbody>${rows}<tr><td><strong>Total</strong></td><td class="num"><strong>${num(contestantTotal(c))}</strong></td></tr></tbody></table></div>`,
    });
    if (ok !== true) return;
    App.setLoading(btn, true);
    try {
      const r = await App.post('scores.submit_contestant', { activity_id: activityId, contestant_id: c.id });
      App.toast(r.message, 'success', 3500);
      sheet.submitted_contestants = r.submitted_contestants;
      if (r.all_submitted) {
        storage.del(storeKey + ':pending');
        await load();
        window.scrollTo(0, 0);
        return;
      }
      // move on to the next contestant that still needs scores
      const order = sheet.contestants.map((x, i) => i);
      const nextIndex = [...order.slice(index + 1), ...order.slice(0, index)].find((i) => !isSubmitted(sheet.contestants[i]));
      if (nextIndex !== undefined) {
        index = nextIndex;
        storage.set(storeKey + ':index', index);
      }
      render();
      App.$('.score-card', view)?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (err) {
      App.fail(err);
      App.setLoading(btn, false);
      if (err.status === 423) await load();
    }
  }

  async function submit(e) {
    await flush();
    if (pending.size) return App.toast('Some scores are not saved yet. Check your connection and try again.', 'error');
    const rows = sheet.contestants.map((c) => `<tr><td class="num">${c.number}</td><td>${esc(c.name)}</td><td class="num"><strong>${num(contestantTotal(c))}</strong></td></tr>`).join('');
    const ok = await App.modal({
      title: 'Submit final scores?',
      submitText: 'Submit final scores',
      onSubmit: () => true,
      body: `<p>After submitting you can no longer change your scores for <strong>${esc(sheet.activity.title)}</strong>.</p>
        <div class="table-wrap"><table class="table"><thead><tr><th class="num">No.</th><th>Contestant</th><th class="num">Your total</th></tr></thead><tbody>${rows}</tbody></table></div>`,
    });
    if (ok !== true) return;
    const btn = e.target.closest('button');
    App.setLoading(btn, true);
    try {
      const r = await App.post('scores.submit', { activity_id: activityId });
      storage.del(storeKey + ':pending');
      App.toast(r.message, 'success', 5000);
      await load();
      window.scrollTo(0, 0);
    } catch (err) {
      App.fail(err);
      App.setLoading(btn, false);
    }
  }

  /* ---------------------------------------------------------------- status sync */

  async function syncStatus() {
    if (pending.size || saveState.status === 'saving') return;
    const fresh = await App.get('scores.sheet', { activity_id: activityId });
    const changed = fresh.activity.status !== sheet.activity.status
      || fresh.submitted_at !== sheet.submitted_at
      || (fresh.submitted_contestants || []).join() !== (sheet.submitted_contestants || []).join()
      || fresh.criteria.length !== sheet.criteria.length
      || fresh.contestants.length !== sheet.contestants.length;
    if (changed) {
      const opened = fresh.activity.status === 'open' && sheet.activity.status !== 'open';
      await load();
      if (opened) App.toast('Scoring is now open. You can start scoring.', 'success');
    }
  }

  window.addEventListener('beforeunload', (e) => {
    if (pending.size) {
      flush();
      e.preventDefault();
      e.returnValue = '';
    }
  });
  window.addEventListener('online', () => pending.size && flush());

  try {
    if (!activityId) throw new Error('No activity selected.');
    await load();
    App.poll(syncStatus, 15000);
    App.live({}, () => syncStatus());
  } catch (err) {
    view.innerHTML = `<div class="alert alert-error">${esc(err.message)}</div>
      <p style="margin-top:12px"><a class="btn" href="${App.page('judge.html')}">Back to my activities</a></p>`;
  }
})();
