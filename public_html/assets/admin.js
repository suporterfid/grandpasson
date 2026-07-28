(() => {
  const form = document.getElementById('admin-form');
  const out = document.getElementById('out');
  const apiUrl = form.dataset.apiUrl;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    const flags = {};
    String(fd.get('flags') || '').split(/\n/).forEach((line) => {
      const t = line.trim();
      if (!t.startsWith('--')) return;
      const body = t.slice(2);
      const i = body.indexOf('=');
      if (i === -1) flags[body] = '1';
      else flags[body.slice(0, i)] = body.slice(i + 1);
    });
    const args = String(fd.get('args') || '').match(/(?:"[^"]*"|'[^']*'|\S+)/g) || [];
    const cleaned = args.map((a) => a.replace(/^['"]|['"]$/g, ''));
    out.textContent = 'Running…';
    try {
      const res = await fetch(apiUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Admin-Token': String(fd.get('admin_token') || ''),
        },
        body: JSON.stringify({
          csrf: fd.get('csrf'),
          verb: fd.get('verb'),
          args: cleaned,
          flags,
        }),
      });
      const text = await res.text();
      out.textContent = res.status + '\n' + text;
    } catch (err) {
      out.textContent = String(err);
    }
  });
})();
