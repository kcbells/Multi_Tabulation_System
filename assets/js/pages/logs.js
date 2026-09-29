/* Administrator: system-wide activity logs (all events, sign-ins, staff accounts). */
(async function () {
  'use strict';
  await App.boot({ roles: ['admin'], nav: 'logs' });
  const view = App.$('#view');
  view.innerHTML = `
    ${App.pathBar({ back: { href: App.page('dashboard.html'), label: 'events' }, trail: [{ label: 'Events', href: App.page('dashboard.html') }, { label: 'Activity logs' }] })}
    <div class="page-head">
      <div><div class="eyebrow">Administration</div><h1>Activity logs</h1>
        <div class="meta">Every sign-in, upload, access code, status change and submission across all events.</div></div>
    </div>
    <div id="logs-root"></div>`;
  const logs = Logs.mount(App.$('#logs-root', view), {});
  App.live({}, () => logs.reload && logs.reload());
})();
