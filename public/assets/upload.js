(function () {
  var box = document.getElementById('uploader');
  if (!box) return;
  var input = document.getElementById('file-input');
  var list = document.getElementById('upload-list');
  var zone = box.querySelector('.dropzone');
  var CHUNK = parseInt(box.dataset.chunk, 10);
  var queue = [], busy = false, finished = 0;

  function hex(n) {
    var a = new Uint8Array(n); crypto.getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }
  function row(file) {
    var li = document.createElement('li'), name = document.createElement('span'), state = document.createElement('span');
    name.textContent = file.name; state.textContent = 'Waiting';
    li.appendChild(name); li.appendChild(state); list.appendChild(li);
    return state;
  }
  function add(files) {
    Array.prototype.forEach.call(files, function (f) { queue.push({ file: f, state: row(f) }); });
    next();
  }
  async function send(item) {
    var f = item.file, total = Math.max(1, Math.ceil(f.size / CHUNK)), id = hex(16);
    if (f.size === 0) throw new Error('Empty file.');
    for (var i = 0; i < total; i++) {
      var fd = new FormData();
      fd.append('upload_id', id); fd.append('index', i); fd.append('total', total);
      fd.append('size', f.size); fd.append('name', f.name);
      fd.append('chunk', f.slice(i * CHUNK, (i + 1) * CHUNK), 'chunk');
      var res, data = null, tries = 0;
      while (true) {
        try {
          res = await fetch('/app/upload/chunk', { method: 'POST', body: fd, headers: { 'X-CSRF': box.dataset.csrf }, credentials: 'same-origin' });
          data = await res.json().catch(function () { return null; });
          if (res.ok || res.status < 500) break;
        } catch (e) { res = null; }
        if (++tries >= 3) throw new Error('Connection lost. Try again.');
        await new Promise(function (r) { setTimeout(r, 1500 * tries); });
      }
      if (!res.ok) throw new Error((data && data.error) || 'Upload failed.');
      item.state.textContent = Math.round((i + 1) / total * 100) + '%';
    }
  }
  async function next() {
    if (busy) return;
    var item = queue.shift();
    if (!item) { if (finished) setTimeout(function () { location.reload(); }, 600); return; }
    busy = true;
    try { await send(item); item.state.textContent = 'Stored'; finished++; }
    catch (e) { item.state.textContent = e.message; item.state.className = 'err'; }
    busy = false; next();
  }
  input.addEventListener('change', function () { add(input.files); input.value = ''; });
  ['dragenter', 'dragover'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.add('over'); }); });
  ['dragleave', 'drop'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.classList.remove('over'); }); });
  zone.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files.length) add(e.dataTransfer.files); });
})();
