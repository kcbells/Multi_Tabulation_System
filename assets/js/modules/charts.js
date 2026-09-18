/* Lightweight, dependency-free visualizations: leaderboard bars and podium.
   Single series → one brand hue (no legend); values at the bar tips in text ink;
   hover/focus tooltip per bar; the result tables on the same page are the table view. */
(function () {
  'use strict';
  const { esc } = App;
  const Charts = (window.Charts = {});

  let tip = null;
  const tooltip = () => {
    if (!tip) {
      tip = document.createElement('div');
      tip.className = 'chart-tip';
      tip.setAttribute('role', 'tooltip');
      tip.hidden = true;
      document.body.appendChild(tip);
    }
    return tip;
  };

  const place = (el, target) => {
    const r = target.getBoundingClientRect();
    const t = tooltip();
    t.hidden = false;
    const x = Math.min(window.innerWidth - t.offsetWidth - 8, Math.max(8, r.left + r.width / 2 - t.offsetWidth / 2));
    const y = r.top - t.offsetHeight - 8 < 8 ? r.bottom + 8 : r.top - t.offsetHeight - 8;
    t.style.left = x + 'px';
    t.style.top = y + 'px';
  };

  /** Shows value (strong) then label; built with textContent (labels are user data). */
  const showTip = (target) => {
    const t = tooltip();
    t.textContent = '';
    const v = document.createElement('strong');
    v.textContent = target.dataset.value;
    const l = document.createElement('span');
    l.textContent = target.dataset.label;
    t.append(v, l);
    place(t, target);
  };
  const hideTip = () => { if (tip) tip.hidden = true; };

  /** Wires hover + keyboard focus tooltips inside a container. */
  Charts.bind = (root) => {
    root.querySelectorAll('[data-tip]').forEach((el) => {
      el.addEventListener('pointerenter', () => showTip(el));
      el.addEventListener('pointerleave', hideTip);
      el.addEventListener('focus', () => showTip(el));
      el.addEventListener('blur', hideTip);
    });
  };

  /**
   * Horizontal bar leaderboard.
   * items: [{ label, sublabel?, value:number|null, display?:string, rank?:number }]
   * opts:  { title, caption, max, emptyText }
   */
  Charts.bars = (items, opts = {}) => {
    const rows = items.filter((i) => i.value !== null && i.value !== undefined && !isNaN(i.value));
    if (!rows.length) return opts.emptyText ? `<p class="muted small">${esc(opts.emptyText)}</p>` : '';
    const max = opts.max || Math.max(...rows.map((r) => Number(r.value)), 0) || 1;
    return `
      <figure class="chart-bars" aria-label="${esc(opts.title || 'Bar chart')}">
        ${opts.title ? `<figcaption><strong>${esc(opts.title)}</strong>${opts.caption ? `<span>${esc(opts.caption)}</span>` : ''}</figcaption>` : ''}
        <div class="cb-rows">
          ${rows.map((r) => {
            const pct = Math.max(0.5, Math.min(100, (Number(r.value) / max) * 100));
            const shown = r.display ?? App.num(r.value);
            return `
            <div class="cb-row" tabindex="0" data-tip data-value="${esc(shown)}" data-label="${esc(r.label + (r.sublabel ? ' · ' + r.sublabel : ''))}">
              <div class="cb-label">
                ${r.rank ? `<span class="rank-pill ${r.rank <= 3 && r.medal !== false ? 'r' + r.rank : ''}">${r.rank}</span>` : '<span class="rank-pill">—</span>'}
                ${r.look && (r.look.photo || r.look.color) ? App.avatar({ name: r.label, color: r.look.color, photo: r.look.photo }, 'xs') : ''}
                <span class="cb-name"><strong>${esc(r.label)}</strong>${r.sublabel ? `<small>${esc(r.sublabel)}</small>` : ''}</span>
              </div>
              <div class="cb-track"><span class="cb-bar" style="width:${pct}%"></span><span class="cb-value">${esc(shown)}</span></div>
            </div>`;
          }).join('')}
        </div>
      </figure>`;
  };

  /** Top-three podium. rows: [{ rank, name, sub?, value?, color?, photo? }] (already ranked). */
  Charts.podium = (rows) => {
    const top = rows.filter((r) => r.rank && r.rank <= 3).slice(0, 3);
    if (!top.length) return '';
    const ord = (n) => n + (['th', 'st', 'nd', 'rd'][(n % 100 - 20) % 10] || ['th', 'st', 'nd', 'rd'][n % 100] || 'th');
    return `<div class="podium">${top.map((t) => `
      <div class="place p${t.rank}">
        <div class="pos">${ord(t.rank)} place</div>
        ${t.photo || t.color ? `<div class="place-avatar">${App.avatar(t, t.rank === 1 ? 'xl' : 'lg')}</div>` : ''}
        <div class="team">${esc(t.name)}</div>
        ${t.sub ? `<div class="small" style="opacity:.8">${esc(t.sub)}</div>` : ''}
        ${t.value ? `<div class="pts">${esc(t.value)}</div>` : ''}
      </div>`).join('')}</div>`;
  };
})();
