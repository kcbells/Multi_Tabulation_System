/* Criteria panel: upload (photo / PDF / DOCX) -> automatic scan -> review & edit -> save. */
(function () {
  'use strict';
  const { esc, num } = App;
  const Criteria = (window.Criteria = {});

  const ACCEPT = '.jpg,.jpeg,.png,.webp,.bmp,.tif,.tiff,.gif,.pdf,.docx,.txt,image/*';

  const reviewKey = (id) => 'criteria-review-' + id;

  /** Criteria must add up to exactly 100 points (the server checks this too). */
  Criteria.totalOk = (total) => Math.abs(total - 100) < 0.005;
  Criteria.totalProblem = (total) => {
    const diff = Math.round((100 - total) * 100) / 100;
    return `Criteria must total exactly 100 points — ${diff > 0 ? `${num(diff)} missing` : `${num(-diff)} too many`}.`;
  };

  /** Keeps a scan result (from the activity form) until the Criteria tab opens it for review. */
  Criteria.stashReview = (activityId, scan) => {
    Criteria.pending = { activityId: Number(activityId), scan };
    try {
      sessionStorage.setItem(reviewKey(activityId), JSON.stringify(scan));
    } catch (e) { /* storage blocked or full: the in-memory copy still works on this page */ }
  };

  function takeReview(activityId) {
    let scan = Criteria.pending && Criteria.pending.activityId === Number(activityId) ? Criteria.pending.scan : null;
    try {
      const raw = sessionStorage.getItem(reviewKey(activityId));
      if (!scan && raw) scan = JSON.parse(raw);
      sessionStorage.removeItem(reviewKey(activityId));
    } catch (e) { /* ignore */ }
    Criteria.pending = null;
    return scan;
  }

  /**
   * opts: { activityId, criteria, canConfigure, hasScores, hasFile, fileName, onSaved() }
   */
  Criteria.mount = (root, opts) => {
    const state = { mode: 'view', rows: [], text: '', engine: '', warnings: [], fileName: opts.fileName };

    const fileLink = () =>
      opts.hasFile || state.fileName
        ? `<a class="btn btn-sm" href="${App.apiUrl('criteria.file', { activity_id: opts.activityId })}" target="_blank" rel="noopener">${App.icon('file')} Original file</a>`
        : '';

    /* -------------------------------------------------- view mode */
    function renderView() {
      const list = opts.criteria;
      const total = list.reduce((s, c) => s + Number(c.max_score), 0);
      root.innerHTML = `
        <div class="card">
          <div class="card-head">
            <div><h3>Judging criteria</h3>
              <div class="muted small">${list.length ? `${list.length} criteria · total ${num(total)} points` : 'No criteria yet'}${state.fileName ? ' · from ' + esc(state.fileName) : ''}</div></div>
            <div class="row">
              ${fileLink()}
              ${opts.canConfigure && list.length ? `<button class="btn btn-sm" data-manual>${App.icon('edit')} Edit</button>` : ''}
            </div>
          </div>
          <div class="card-body">
            ${list.length ? `
              <ol class="criteria-list">
                ${list.map((c, i) => `<li><span class="idx">${i + 1}</span>
                  <div class="body"><strong>${esc(c.name)}</strong>${c.description ? `<div class="desc">${esc(c.description)}</div>` : ''}</div>
                  <span class="pts">${num(c.max_score)} <span class="muted small">pts</span></span></li>`).join('')}
              </ol>
              <div class="total-bar ${Criteria.totalOk(total) ? 'ok' : 'bad'}" style="margin-top:12px"><span>${Criteria.totalOk(total) ? 'Total' : Criteria.totalProblem(total) + ' Scoring cannot be opened until this is fixed.'}</span><strong>${num(total)} / 100</strong></div>`
              : App.empty('No criteria yet', opts.canConfigure ? 'Upload the criteria sheet — the system reads it automatically.' : 'The organizer has not added criteria yet.', 'list')}
            ${opts.canConfigure ? uploadBlock(list.length > 0) : ''}
          </div>
        </div>`;
      bindUpload();
      root.querySelector('[data-manual]')?.addEventListener('click', () => openEditor(opts.criteria.map(copyRow), '', '', []));
    }

    function uploadBlock(replacing) {
      return `
        <div style="margin-top:${replacing ? '20px' : '4px'}">
          ${opts.hasScores ? `<div class="alert alert-warn" style="margin-bottom:12px">Judges have already scored. You can rename criteria, but changing points or the list requires resetting scores.</div>` : ''}
          <label class="dropzone" data-drop>
            ${App.icon('scan')}
            <strong>${replacing ? 'Replace criteria from a document' : 'Upload the criteria sheet'}</strong>
            <span class="muted small">Photo, PDF or Word (.docx) — drag &amp; drop or tap to choose. It will be scanned and read automatically.</span>
            <input type="file" accept="${ACCEPT}" data-file>
          </label>
          <div class="row" style="justify-content:center;margin-top:10px">
            <button class="btn btn-sm btn-ghost" data-blank>${App.icon('edit')} Or type the criteria manually</button>
          </div>
        </div>`;
    }

    function bindUpload() {
      const drop = root.querySelector('[data-drop]');
      if (!drop) return;
      const input = drop.querySelector('[data-file]');
      input.addEventListener('change', () => input.files[0] && scan(input.files[0]));
      ['dragenter', 'dragover'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.add('drag'); }));
      ['dragleave', 'drop'].forEach((ev) => drop.addEventListener(ev, (e) => { e.preventDefault(); drop.classList.remove('drag'); }));
      drop.addEventListener('drop', (e) => e.dataTransfer.files[0] && scan(e.dataTransfer.files[0]));
      root.querySelector('[data-blank]')?.addEventListener('click', () =>
        openEditor(opts.criteria.length ? opts.criteria.map(copyRow) : [{ id: 0, name: '', description: '', max_score: '' }], '', '', []));
    }

    /* -------------------------------------------------- scanning */
    async function scan(file) {
      root.innerHTML = `
        <div class="card"><div class="card-body">
          <h3>Reading “${esc(file.name)}”</h3>
          <div class="scan-steps">
            <div class="scan-step active" data-step="upload"><span class="dot"></span><span>Uploading to the file server <b data-pct>0%</b></span></div>
            <div class="scan-step" data-step="read"><span class="dot"></span><span>Scanning and reading the document</span></div>
            <div class="scan-step" data-step="detect"><span class="dot"></span><span>Detecting criteria and points</span></div>
          </div>
        </div></div>`;
      const step = (name, stateName) => {
        const el = root.querySelector(`[data-step="${name}"]`);
        el.classList.remove('active', 'done');
        if (stateName) el.classList.add(stateName);
        if (stateName === 'done') el.querySelector('.dot').textContent = '✓';
      };

      try {
        const prepared = await App.compressImage(file);
        const fd = new FormData();
        fd.append('activity_id', opts.activityId);
        fd.append('file', prepared, prepared.name || file.name);
        const r = await App.upload('criteria.scan', fd, (pct) => {
          const p = root.querySelector('[data-pct]');
          if (p) p.textContent = pct + '%';
          if (pct >= 100) { step('upload', 'done'); step('read', 'active'); }
        });
        step('upload', 'done'); step('read', 'done'); step('detect', 'done');
        state.fileName = r.file_name;
        opts.hasFile = true;
        await new Promise((res) => setTimeout(res, 350));
        const rows = r.criteria.length ? r.criteria.map((c) => ({ id: 0, ...c })) : [{ id: 0, name: '', description: '', max_score: '' }];
        // Keep ids when the scanned list lines up with saved criteria (lets renames pass the "already scored" check).
        if (opts.hasScores && opts.criteria.length === rows.length) rows.forEach((row, i) => (row.id = opts.criteria[i].id));
        openEditor(rows, r.text, r.engine, r.warnings);
        if (r.criteria.length) App.toast(`Found ${r.criteria.length} criteria — review and save.`, 'success');
      } catch (err) {
        App.fail(err);
        renderView();
      }
    }

    /* -------------------------------------------------- review / editor */
    const copyRow = (c) => ({ id: Number(c.id) || 0, name: c.name, description: c.description || '', max_score: c.max_score });

    function openEditor(rows, text, engine, warnings) {
      state.mode = 'edit';
      state.rows = rows;
      state.text = text;
      root.innerHTML = `
        <div class="card">
          <div class="card-head">
            <div><h3>${text ? 'Review scanned criteria' : 'Edit criteria'}</h3>
              <div class="muted small">${engine ? 'Read with ' + esc(engine) + (state.fileName ? ' from ' + esc(state.fileName) : '') + '. ' : ''}Check every name and point value, then save.</div></div>
            ${fileLink()}
          </div>
          <div class="card-body stack">
            ${warnings.map((w) => `<div class="alert alert-warn">${esc(w)}</div>`).join('')}
            ${text ? `
              <details ${rows.length && rows[0].name ? '' : 'open'}>
                <summary style="cursor:pointer;font-weight:600">Text read from the document</summary>
                <div class="stack" style="margin-top:10px">
                  <textarea class="extracted-text" data-text spellcheck="false">${esc(text)}</textarea>
                  <div class="row"><button class="btn btn-sm" data-reparse>${App.icon('refresh')} Read criteria again from this text</button>
                  <span class="hint" style="margin:0">Fix any OCR mistakes above (e.g. “4O%” → “40%”), then read again.</span></div>
                </div>
              </details>` : ''}
            <div class="criteria-editor" data-rows></div>
            <div class="row"><button class="btn btn-sm" data-add>${App.icon('plus')} Add criterion</button></div>
            <div class="total-bar" data-total></div>
            <div class="row" style="justify-content:flex-end">
              <button class="btn" data-cancel>Cancel</button>
              <button class="btn btn-primary" data-save>${App.icon('check')} Save criteria</button>
            </div>
          </div>
        </div>`;
      drawRows();

      root.querySelector('[data-add]').addEventListener('click', () => {
        syncFromDom();
        state.rows.push({ id: 0, name: '', description: '', max_score: '' });
        drawRows();
        root.querySelector('.crit-row:last-child .c-name')?.focus();
      });
      root.querySelector('[data-cancel]').addEventListener('click', renderView);
      root.querySelector('[data-save]').addEventListener('click', save);
      root.querySelector('[data-reparse]')?.addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        App.setLoading(btn, true);
        try {
          const r = await App.post('criteria.reparse', { activity_id: opts.activityId, text: root.querySelector('[data-text]').value });
          if (r.criteria.length) {
            state.rows = r.criteria.map((c) => ({ id: 0, ...c }));
            drawRows();
            App.toast(`Found ${r.criteria.length} criteria.`, 'success');
          } else {
            App.toast(r.warnings[0] || 'No criteria found.', 'error');
          }
        } catch (err) {
          App.fail(err);
        } finally {
          App.setLoading(btn, false);
        }
      });
    }

    function drawRows() {
      const box = root.querySelector('[data-rows]');
      box.innerHTML = state.rows.map((r, i) => `
        <div class="crit-row" data-i="${i}">
          <span class="c-idx">${i + 1}</span>
          <input class="c-name" placeholder="Criterion name" value="${esc(r.name)}" aria-label="Criterion ${i + 1} name" maxlength="200">
          <textarea class="c-desc" rows="1" placeholder="Description (optional)" aria-label="Criterion ${i + 1} description">${esc(r.description)}</textarea>
          <input class="c-max" type="number" min="0.01" max="1000" step="0.01" inputmode="decimal" placeholder="Pts" value="${esc(r.max_score)}" aria-label="Criterion ${i + 1} points">
          <div class="c-tools">
            <button class="btn btn-sm btn-ghost btn-icon" data-move="-1" title="Move up" ${i === 0 ? 'disabled' : ''}>${App.icon('up')}</button>
            <button class="btn btn-sm btn-ghost btn-icon" data-move="1" title="Move down" ${i === state.rows.length - 1 ? 'disabled' : ''}>${App.icon('down')}</button>
            <button class="btn btn-sm btn-ghost btn-icon" data-remove title="Remove">${App.icon('trash')}</button>
          </div>
        </div>`).join('');
      box.querySelectorAll('input, textarea').forEach((el) => el.addEventListener('input', updateTotal));
      box.querySelectorAll('[data-move]').forEach((b) => b.addEventListener('click', () => {
        syncFromDom();
        const i = Number(b.closest('.crit-row').dataset.i);
        const j = i + Number(b.dataset.move);
        [state.rows[i], state.rows[j]] = [state.rows[j], state.rows[i]];
        drawRows();
      }));
      box.querySelectorAll('[data-remove]').forEach((b) => b.addEventListener('click', () => {
        syncFromDom();
        state.rows.splice(Number(b.closest('.crit-row').dataset.i), 1);
        drawRows();
      }));
      updateTotal();
    }

    function syncFromDom() {
      root.querySelectorAll('.crit-row').forEach((row) => {
        const r = state.rows[Number(row.dataset.i)];
        r.name = row.querySelector('.c-name').value;
        r.description = row.querySelector('.c-desc').value;
        r.max_score = row.querySelector('.c-max').value;
      });
    }

    function updateTotal() {
      syncFromDom();
      const total = state.rows.reduce((s, r) => s + (Number(r.max_score) || 0), 0);
      const bar = root.querySelector('[data-total]');
      const ok = Criteria.totalOk(total);
      bar.className = 'total-bar' + (ok ? ' ok' : ' bad');
      bar.innerHTML = `<span>${ok ? 'Total is 100 — ready to save' : Criteria.totalProblem(total)}</span><strong>${num(total)} / 100</strong>`;
      const btn = root.querySelector('[data-save]');
      if (btn) btn.disabled = !ok;
    }

    async function save(e) {
      syncFromDom();
      const rows = state.rows.filter((r) => r.name.trim() || String(r.max_score).trim());
      if (!rows.length) return App.toast('Add at least one criterion.', 'error');
      const total = rows.reduce((s, r) => s + (Number(r.max_score) || 0), 0);
      if (!Criteria.totalOk(total)) return App.toast(Criteria.totalProblem(total), 'error', 6000);
      const btn = e.currentTarget;
      App.setLoading(btn, true);
      try {
        const r = await App.post('criteria.save', { activity_id: opts.activityId, criteria: rows });
        App.toast(r.message, 'success');
        opts.onSaved && opts.onSaved();
      } catch (err) {
        App.fail(err);
        App.setLoading(btn, false);
      }
    }

    const pending = opts.canConfigure ? takeReview(opts.activityId) : null;
    if (pending) {
      state.fileName = pending.file_name;
      opts.hasFile = true;
      const rows = pending.criteria.length ? pending.criteria.map((c) => ({ id: 0, ...c })) : [{ id: 0, name: '', description: '', max_score: '' }];
      // keep ids when the scanned list lines up with saved criteria (lets renames pass the "already scored" check)
      if (opts.hasScores && opts.criteria.length === rows.length) rows.forEach((row, i) => (row.id = opts.criteria[i].id));
      openEditor(rows, pending.text, pending.engine, pending.warnings);
    } else {
      renderView();
    }
    return { refresh: (next) => { Object.assign(opts, next); renderView(); } };
  };
})();
