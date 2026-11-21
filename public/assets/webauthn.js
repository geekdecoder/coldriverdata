// Security keys (YubiKey and similar): add a key on the Security page, use one at sign-in.
(function () {
  function toBuf(s) {
    s = s.replace(/-/g, '+').replace(/_/g, '/'); while (s.length % 4) s += '=';
    var bin = atob(s), a = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) a[i] = bin.charCodeAt(i);
    return a.buffer;
  }
  function toStr(buf) {
    var a = new Uint8Array(buf), s = '';
    for (var i = 0; i < a.length; i++) s += String.fromCharCode(a[i]);
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  }
  function post(url, csrf, body) {
    return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF': csrf, 'Content-Type': 'application/json' }, body: JSON.stringify(body || {}) })
      .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); });
  }
  function explain(e) {
    if (e && e.name === 'NotAllowedError') return 'Nothing was received from a security key. Insert it, touch it when it blinks, and try again.';
    if (e && e.name === 'InvalidStateError') return 'That security key is already added to your account.';
    if (e && e.name === 'SecurityError') return 'This browser would not allow a security key on this address.';
    return (e && e.message) || 'Something went wrong. Try again.';
  }

  var box = document.querySelector('[data-webauthn]');
  if (!box) return;
  var csrf = box.dataset.csrf, msg = box.querySelector('[data-msg]'), btn = box.querySelector('[data-go]');
  function say(text, bad) { msg.textContent = text; msg.hidden = !text; msg.className = 'flash ' + (bad ? 'flash-err' : 'flash-ok'); }
  if (!window.PublicKeyCredential || !navigator.credentials) {
    say('This browser does not support security keys. Use a current version of Chrome, Edge, Firefox or Safari.', true);
    btn.disabled = true;
    return;
  }

  btn.addEventListener('click', function () {
    btn.disabled = true;
    say(box.dataset.webauthn === 'register' ? 'Insert your key and touch it when it blinks.' : 'Insert your key and touch it when it blinks.', false);
    var flow = box.dataset.webauthn === 'register' ? register() : login();
    flow.then(function (res) {
      if (res.data && res.data.redirect && (res.ok || res.data.redirect === '/login')) { location.href = res.data.redirect; return; }
      say((res.data && res.data.error) || 'Something went wrong. Try again.', true); btn.disabled = false;
    }).catch(function (e) { say(explain(e), true); btn.disabled = false; });
  });

  function register() {
    return post('/app/security/keys/options', csrf).then(function (res) {
      if (!res.ok) return res;
      var o = res.data;
      o.challenge = toBuf(o.challenge); o.user.id = toBuf(o.user.id);
      o.excludeCredentials = (o.excludeCredentials || []).map(function (c) { return { type: c.type, id: toBuf(c.id) }; });
      return navigator.credentials.create({ publicKey: o }).then(function (cred) {
        return post('/app/security/keys/register', csrf, {
          name: (box.querySelector('[data-name]') || {}).value || '',
          id: toStr(cred.rawId), clientDataJSON: toStr(cred.response.clientDataJSON), attestationObject: toStr(cred.response.attestationObject)
        });
      });
    });
  }
  function login() {
    return post('/login/key/options', csrf).then(function (res) {
      if (!res.ok) return res;
      var o = res.data;
      o.challenge = toBuf(o.challenge);
      o.allowCredentials = (o.allowCredentials || []).map(function (c) { return { type: c.type, id: toBuf(c.id) }; });
      return navigator.credentials.get({ publicKey: o }).then(function (a) {
        return post('/login/key/verify', csrf, {
          id: toStr(a.rawId), clientDataJSON: toStr(a.response.clientDataJSON),
          authenticatorData: toStr(a.response.authenticatorData), signature: toStr(a.response.signature)
        });
      });
    });
  }
})();
