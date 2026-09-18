/* Sign-in page: one form. Username + password (staff) OR an access code (judge / facilitator). */
(async function () {
  'use strict';
  const me = await App.boot({ roles: 'public' });
  if (me.user) {
    location.replace(App.page('dashboard.html'));
    return;
  }

  const form = App.$('#login-form');
  const alertBox = App.$('#login-alert');
  const { username, password, code } = form;
  const staffFields = [username, password];

  // Format the access code as XXXX-XXXX while typing
  code.addEventListener('input', () => {
    const raw = code.value.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 8);
    code.value = raw.length > 4 ? raw.slice(0, 4) + '-' + raw.slice(4) : raw;
  });

  // Using one way to sign in dims the other, so it is clear which one will be used
  const sync = () => {
    const usingCode = code.value.trim() !== '';
    const usingStaff = staffFields.some((f) => f.value !== '');
    form.classList.toggle('using-code', usingCode && !usingStaff);
    form.classList.toggle('using-staff', usingStaff && !usingCode);
  };
  [username, password, code].forEach((f) => f.addEventListener('input', sync));

  // Show / hide password
  const toggle = form.querySelector('[data-toggle-password]');
  toggle.addEventListener('click', () => {
    const show = password.type === 'password';
    password.type = show ? 'text' : 'password';
    toggle.classList.toggle('showing', show);
    toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    toggle.title = show ? 'Hide password' : 'Show password';
    password.focus();
  });

  const showError = (msg, field) => {
    alertBox.innerHTML = `<div class="alert alert-error" style="margin-bottom:14px">${App.esc(msg)}</div>`;
    if (field) field.focus();
  };

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const hasCode = code.value.trim() !== '';
    const hasStaff = username.value.trim() !== '' || password.value !== '';

    if (hasCode && hasStaff) return showError('Use either your username and password or an access code, not both.', code);
    if (!hasCode && !hasStaff) return showError('Enter your username and password, or your access code.', username);
    if (hasStaff && !username.value.trim()) return showError('Enter your username.', username);
    if (hasStaff && !password.value) return showError('Enter your password.', password);
    if (hasCode && code.value.replace(/[^A-Z0-9]/gi, '').length < 8) return showError('The access code has 8 letters and numbers, e.g. ABCD-1234.', code);

    const btn = form.querySelector('[type=submit]');
    App.setLoading(btn, true);
    alertBox.innerHTML = '';
    try {
      if (hasCode) {
        await App.post('auth.code_login', { code: code.value });
      } else {
        await App.post('auth.staff_login', { username: username.value, password: password.value });
      }
      location.replace(App.page('dashboard.html'));
    } catch (err) {
      showError(err.message, hasCode ? code : password);
      App.setLoading(btn, false);
    }
  });

  username.focus();
})();
