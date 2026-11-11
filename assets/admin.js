(() => {
  const cfg = window.mtrk;
  if (!cfg) return;
  const t = cfg.i18n;
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  const post = async (action, data = {}) => {
    const body = new FormData();
    body.append('action', action);
    body.append('_ajax_nonce', cfg.nonce);
    Object.entries(data).forEach(([k, v]) => {
      if (Array.isArray(v)) v.forEach((x) => body.append(`${k}[]`, x));
      else body.append(k, v);
    });
    const res = await fetch(cfg.ajax, { method: 'POST', body, credentials: 'same-origin' });
    const json = await res.json().catch(() => null);
    if (!json || !json.success) throw new Error((json && json.data) || t.error);
    return json.data;
  };

  const fmt = (s, ...a) => s.replace(/%(\d)\$s/g, (_, i) => a[i - 1]).replace('%s', a[0]);

  // Files built on the server (ZIP, lists) arrive as a download; an error comes back as JSON (or a PHP error page).
  const saveBlob = (blob, name) => {
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = name;
    document.body.append(link);
    link.click();
    link.remove();
    setTimeout(() => URL.revokeObjectURL(link.href), 60000);
  };
  const download = async (data, fallback) => {
    const body = new FormData();
    body.append('_ajax_nonce', cfg.nonce);
    Object.entries(data).forEach(([k, v]) => body.append(k, v));
    const res = await fetch(cfg.ajax, { method: 'POST', body, credentials: 'same-origin' });
    if (!res.ok || /json|html/.test(res.headers.get('Content-Type') || '')) {
      const json = await res.json().catch(() => null);
      throw new Error((json && json.data) || t.error);
    }
    saveBlob(await res.blob(), ((res.headers.get('Content-Disposition') || '').match(/filename="?([^";]+)/) || [])[1] || fallback);
    return res;
  };
  // CSV built in the browser (extra files), with the same rules as the server: quoted when needed, and text that a
  // spreadsheet would run as a formula (= + - @ tab CR) gets a leading quote.
  const csvCell = (v) => {
    let s = String(v);
    if (/^[=+\-@\t\r]/.test(s)) s = `'${s}`;
    return /[",\r\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  };
  const stamp = () => {
    const d = new Date();
    const p = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}${p(d.getMonth() + 1)}${p(d.getDate())}-${p(d.getHours())}${p(d.getMinutes())}${p(d.getSeconds())}`;
  };
  const exportStatus = (btn, text) => { btn.closest('.mtrk-export').querySelector('.mtrk-export-status').textContent = text; };
  // Human sizes: KB under a megabyte (small leftovers would all read 0.0 MB), MB above.
  const size = (b) => (b >= 1048576 ? `${(b / 1048576).toFixed(1)} ${t.mb}` : `${Math.max(1, Math.round(b / 1024))} ${t.kb}`);

  // One modal for "where is it used" (media library) and "preview" (plugin page): a native <dialog>, so focus
  // trap, Esc and the backdrop come from the browser.
  let dialog;
  let seq = 0;
  const ensureDialog = () => {
    if (!dialog) {
      dialog = document.createElement('dialog');
      dialog.className = 'mtrk-dialog';
      dialog.setAttribute('aria-labelledby', 'mtrk-dialog-title');
      const bar = document.createElement('form');
      bar.method = 'dialog';
      bar.className = 'mtrk-dialog-bar';
      const h = document.createElement('h2');
      h.id = 'mtrk-dialog-title';
      h.textContent = t.title;
      const close = document.createElement('button');
      close.className = 'mtrk-close';
      close.setAttribute('aria-label', t.close);
      close.textContent = '×';
      bar.append(h, close);
      const content = document.createElement('div');
      content.className = 'mtrk-body';
      dialog.append(bar, content);
      dialog.addEventListener('click', (e) => { if (e.target === dialog) dialog.close(); });
      // The media modal underneath listens for Esc/arrows too; while ours is open, keys are ours only.
      // Default actions (Esc closes the dialog, Tab moves focus) still happen.
      window.addEventListener('keydown', (e) => { if (dialog.open) e.stopPropagation(); }, true);
      document.body.append(dialog);
    }
    return dialog;
  };
  // data: an attachment id, or the request fields (merge preview, "Where?" of an extra file).
  const openDialog = async (action, fields) => {
    ensureDialog();
    const content = dialog.querySelector('.mtrk-body');
    const title = dialog.querySelector('#mtrk-dialog-title');
    const mine = ++seq;
    title.textContent = { mtrk_preview: t.preview, mtrk_merge: t.mergeTitle }[action] || t.title;
    dialog.classList.toggle('mtrk-dialog-wide', action === 'mtrk_preview' || action === 'mtrk_merge');
    content.textContent = t.loading;
    if (!dialog.open) dialog.showModal();
    try {
      const data = await post(action, typeof fields === 'object' ? fields : { id: fields });
      if (mine !== seq) return;
      content.innerHTML = data.html;
      if (data.title) title.textContent = data.title;
    } catch (e) {
      if (mine === seq) content.textContent = e.message;
    }
  };

  // Preview of a file that is not in the library (extra files): built here, there is no attachment to ask about.
  const openFile = (f, url, root) => {
    ensureDialog();
    ++seq; // a slower request for another dialog must not overwrite this one
    dialog.querySelector('#mtrk-dialog-title').textContent = t.preview;
    dialog.classList.add('mtrk-dialog-wide');
    const make = (tag, props = {}) => Object.assign(document.createElement(tag), props);
    const media = make('div', { className: 'mtrk-preview-media' });
    if (/\.(jpe?g|png|gif|webp|avif|svg)$/i.test(f.path)) media.append(make('img', { src: url, alt: '' }));
    else if (/\.(mp3|m4a|ogg|oga|wav|flac|aac)$/i.test(f.path)) media.append(make('audio', { src: url, controls: true, preload: 'metadata' }));
    else if (/\.(mp4|m4v|webm|ogv|mov)$/i.test(f.path)) media.append(make('video', { src: url, controls: true, preload: 'metadata' }));
    else media.append(make('p', { textContent: t.noPreview }));
    const dl = make('dl', { className: 'mtrk-details' });
    const add = (label, node) => {
      const dd = make('dd');
      dd.append(node);
      dl.append(make('dt', { textContent: label }), dd);
    };
    const path = make('bdi', { dir: 'ltr', textContent: `${root}/${f.path}` });
    add(t.fileLabel, path);
    add(t.sizeLabel, document.createTextNode(size(f.bytes)));
    add(t.openTab, make('a', { href: url, target: '_blank', rel: 'noopener', textContent: url }));
    const layout = make('div', { className: 'mtrk-preview-layout' });
    layout.append(media, make('div', { className: 'mtrk-preview-info' }));
    layout.lastChild.append(dl);
    dialog.querySelector('.mtrk-body').replaceChildren(layout);
    if (!dialog.open) dialog.showModal();
  };

  // Actions on media (plugin page): quarantine, restore, keep, unkeep, delete. The page reloads with a notice.
  // files: extra files (paths) picked in a duplicate group; they can only be deleted, never quarantined.
  const act = async (name, ids, btn, files = []) => {
    if (!ids.length && !files.length) return;
    if (name === 'delete') {
      const msg = !files.length ? fmt(t.confirmDelete, ids.length)
        : (ids.length ? fmt(t.confirmMixed, ids.length, files.length) : fmt(t.confirmFiles, files.length));
      if (!window.confirm(msg)) return;
    }
    if (btn) btn.disabled = true;
    try {
      let n = 0;
      let bytes = 0;
      for (let i = 0; i < files.length; i += 200) {
        const r = await post('mtrk_orphans', { do: 'delete', paths: files.slice(i, i + 200) });
        n += r.files;
        bytes += r.bytes;
      }
      if (ids.length) {
        const r = await post('mtrk_act', { act: name, ids });
        n += r.done.length;
        bytes += r.bytes;
      }
      const url = new URL(window.location.href);
      url.searchParams.delete('paged'); // the current page may be gone now
      url.searchParams.set('done', name);
      url.searchParams.set('n', n);
      url.searchParams.set('b', bytes);
      window.location.href = url.toString();
    } catch (e) {
      window.alert(e.message);
      if (btn) btn.disabled = false;
    }
  };

  const picked = () => [...document.querySelectorAll('.mtrk-pick:checked')].map((b) => b.value);
  const pickedFiles = () => [...document.querySelectorAll('.mtrk-xpick:checked')].map((b) => b.value);
  const syncBulk = () => {
    const ids = picked();
    const files = pickedFiles();
    document.querySelectorAll('.mtrk-bulk[data-scope]').forEach((bar) => {
      bar.querySelectorAll('[data-act]').forEach((b) => {
        // extra files can only be deleted: nothing to quarantine or restore for them
        b.disabled = b.dataset.act === 'delete' ? !(ids.length + files.length) : !ids.length;
      });
      bar.querySelector('.mtrk-count').textContent = ids.length + files.length ? fmt(t.selected, ids.length + files.length) : '';
    });
  };

  document.addEventListener('click', (e) => {
    const usage = e.target.closest('.mtrk-open');
    const preview = e.target.closest('button.mtrk-preview');
    const action = e.target.closest('[data-act]');
    if (usage || preview) {
      e.preventDefault();
      e.stopPropagation(); // keep the media grid from treating it as a selection click
      openDialog(usage ? 'mtrk_usage' : 'mtrk_preview', (usage || preview).dataset.id);
    } else if (action && action.dataset.ids) { // "restore every file that was requested": the ids come from the server
      e.preventDefault();
      act(action.dataset.act, action.dataset.ids.split(' '), action);
    } else if (action && (action.dataset.id || action.closest('.mtrk-bulk[data-scope]'))) {
      e.preventDefault();
      act(action.dataset.act, action.dataset.id ? [action.dataset.id] : picked(), action, action.dataset.id ? [] : pickedFiles());
    } else if (e.target.closest('.mtrk-xpreview')) { // an extra file in a duplicate group
      const b = e.target.closest('.mtrk-xpreview');
      const path = b.dataset.path;
      openFile({ path, bytes: Number(b.dataset.bytes) }, `${cfg.uploads.url}/${path.split('/').map(encodeURIComponent).join('/')}`, cfg.uploads.root);
    }
  }, true);

  // Duplicates: "Where?" of an extra file, and merge (preview, run, continue, undo).
  const mergeLoop = async (first, merge) => {
    let r = await post('mtrk_merge', { do: first, merge });
    while (!r.done) r = await post('mtrk_merge', { do: first === 'undo' ? 'undo' : 'step', merge: r.merge });
    return r;
  };
  const mergeRun = async (btn) => {
    const body = btn.closest('.mtrk-body');
    const box = body.querySelector('.mtrk-merge-delete');
    const out = body.querySelector('.mtrk-merge-result');
    const items = JSON.parse(btn.dataset.items || '[]');
    if (box.checked && !window.confirm(fmt(t.mergeConfirmDelete, items.length))) return;
    btn.disabled = true;
    box.disabled = true;
    const list = document.createElement('ul');
    list.className = 'mtrk-list';
    for (const item of items) {
      const label = typeof item === 'number' ? `#${item}` : item;
      out.textContent = fmt(t.merging, label);
      let text;
      try {
        const first = await post('mtrk_merge', { do: 'run', keep: btn.dataset.keep, delete: box.checked ? 1 : '', ...(typeof item === 'number' ? { id: item } : { paths: [item] }) });
        text = (first.done ? first : await mergeLoop('step', first.merge)).message;
      } catch (e) {
        text = e.message;
      }
      list.append(Object.assign(document.createElement('li'), { textContent: `${label}: ${text}` }));
    }
    const reload = Object.assign(document.createElement('button'), { type: 'button', className: 'button button-primary', textContent: t.reload });
    reload.addEventListener('click', () => window.location.reload());
    const note = Object.assign(document.createElement('p'), { className: 'notice notice-info inline', textContent: t.cachePurge });
    out.replaceChildren(list, note, reload);
  };
  document.addEventListener('click', async (e) => {
    const where = e.target.closest('.mtrk-where');
    const open = e.target.closest('.mtrk-merge');
    const go = e.target.closest('.mtrk-merge-go');
    const again = e.target.closest('.mtrk-merge-undo, .mtrk-merge-step');
    if (where) {
      openDialog('mtrk_where', { path: where.dataset.path });
    } else if (open) {
      const section = open.closest('.mtrk-dup');
      const keep = section.querySelector('.mtrk-keep:checked');
      if (!keep) {
        window.alert(t.pickKeep);
        return;
      }
      const ids = [...section.querySelectorAll('.mtrk-mtable button.mtrk-preview[data-id]')].map((b) => b.dataset.id).filter((id) => id !== keep.value);
      const paths = [...section.querySelectorAll('.mtrk-xtable .mtrk-xpreview[data-path]')].map((b) => b.dataset.path);
      openDialog('mtrk_merge', { do: 'preview', keep: keep.value, ids, paths });
    } else if (go) {
      mergeRun(go);
    } else if (again) {
      const undo = again.matches('.mtrk-merge-undo');
      if (undo && !window.confirm(t.undoConfirm)) return;
      again.disabled = true;
      try {
        const r = await mergeLoop(undo ? 'undo' : 'step', again.dataset.merge);
        window.alert(`${r.message}\n\n${t.cachePurge}`);
        window.location.reload();
      } catch (err) {
        window.alert(err.message);
        again.disabled = false;
      }
    }
  });

  document.addEventListener('change', (e) => {
    if (e.target.matches('.mtrk-merge-delete')) {
      const warn = e.target.closest('.mtrk-body').querySelector('.mtrk-merge-warn');
      if (warn) warn.hidden = !e.target.checked;
    }
    if (e.target.matches('.mtrk-bulk[data-scope] .mtrk-all')) {
      document.querySelectorAll('.mtrk-pick').forEach((b) => { b.checked = e.target.checked; });
    }
    if (e.target.matches('.mtrk-pick, .mtrk-xpick, .mtrk-all')) syncBulk();
  });

  // Duplicates tab: sizes first, then content hashes where sizes collide; the page reloads with the groups.
  const dups = document.getElementById('mtrk-dups');
  if (dups) {
    const btn = dups.querySelector('.mtrk-dups-check');
    const status = dups.querySelector('.mtrk-dups-status');
    btn.addEventListener('click', async () => {
      btn.disabled = true;
      try {
        const phases = { size: t.dupsSizes, list: t.dupsList, hash: t.dupsHashes };
        let r = await post('mtrk_dups', { start: 1, extras: dups.querySelector('.mtrk-dups-extras').checked ? 1 : 0 });
        while (!r.done) {
          status.textContent = fmt(phases[r.phase], r.left);
          r = await post('mtrk_dups');
        }
        const url = new URL(window.location.href);
        url.searchParams.delete('paged');
        window.location.href = url.toString();
      } catch (e) {
        status.textContent = e.message;
        btn.disabled = false;
      }
    });
  }

  // Quarantine tab: do requests for missing upload files reach WordPress here? (nginx rules or a CDN can stop them)
  const probe = document.querySelector('.mtrk-probe[data-url]');
  if (probe) {
    fetch(probe.dataset.url, { cache: 'no-store', credentials: 'omit' })
      .then((r) => r.headers.get('X-Mtrk') === 'ok')
      .catch(() => false)
      .then((ok) => {
        probe.textContent = ok ? t.probeOk : t.probeFail;
        probe.classList.add(ok ? 'notice-success' : 'notice-warning');
        const testProbe = document.querySelector('.mtrk-qtest-probe');
        if (testProbe) testProbe.hidden = ok;
      });
  }

  // 404 test: start or stop, then the page reloads with its new state.
  document.querySelectorAll('[data-qtest]').forEach((btn) => btn.addEventListener('click', async () => {
    btn.disabled = true;
    try {
      const hours = document.getElementById('mtrk-qtest-hours');
      await post('mtrk_qtest', { do: btn.dataset.qtest, hours: hours ? hours.value : 24 });
      window.location.reload();
    } catch (e) {
      window.alert(e.message);
      btn.disabled = false;
    }
  }));

  // "Download list" of the Unreferenced (as filtered) and Quarantine tabs: built on the server, every page.
  document.addEventListener('click', async (e) => {
    const btn = e.target.closest('.mtrk-export:not([data-list="orphans"]) .mtrk-export-btn');
    if (!btn) return;
    const bar = btn.closest('.mtrk-export');
    btn.disabled = true;
    exportStatus(btn, t.exportBuilding);
    try {
      const args = JSON.parse(bar.dataset.args || '{}');
      const res = await download({ action: 'mtrk_export', list: bar.dataset.list, format: btn.dataset.format, ...args }, `${bar.dataset.list}-media.${btn.dataset.format}`);
      exportStatus(btn, fmt(t.exportDone, res.headers.get('X-Mtrk-Rows') || '?'));
    } catch (err) {
      exportStatus(btn, err.message);
    }
    btn.disabled = false;
  });

  // The last result of a slow check (extra files, unregistered sizes) is kept for this browser tab, so moving
  // between tabs doesn't mean checking again. It belongs to one scan: a new scan drops it. Deleting never relies
  // on it: the server looks at the disk again before removing anything.
  const stash = {
    get(key, scan) {
      try {
        const v = JSON.parse(sessionStorage.getItem(key));
        return v && v.scan === scan ? v : null;
      } catch (e) {
        return null;
      }
    },
    set(key, scan, data) {
      try {
        sessionStorage.setItem(key, JSON.stringify({ scan, at: Date.now(), data }));
      } catch (e) { /* storage full or blocked: it just checks again next time */ }
    },
  };
  const checkedAt = (at) => fmt(t.checkedAt, new Date(at).toLocaleString());

  // Orphan files: walk the media folders in batches, list what no attachment owns, delete the checked ones.
  const orphans = document.getElementById('mtrk-orphans');
  if (orphans) {
    const status = orphans.querySelector('.mtrk-orphans-status');
    const result = orphans.querySelector('.mtrk-orphans-result');
    const tbody = orphans.querySelector('tbody');
    const checkBtn = orphans.querySelector('.mtrk-orphans-check');
    const delBtn = orphans.querySelector('.mtrk-orphans-delete');
    const zipBtn = orphans.querySelector('.mtrk-orphans-zip');
    const all = orphans.querySelector('.mtrk-orphans-all');
    const more = orphans.querySelector('.mtrk-orphans-more');
    const count = orphans.querySelector('.mtrk-count');
    const isImage = /\.(jpe?g|png|gif|webp|avif|svg)$/i;
    const PAGE = 300;
    let files = [];
    let shown = 0;

    const fileUrl = (f) => `${orphans.dataset.base}/${f.path.split('/').map(encodeURIComponent).join('/')}`;
    const cell = (child) => {
      const td = document.createElement('td');
      td.append(child);
      return td;
    };
    const text = (s, tag = 'span') => {
      const el = document.createElement(tag);
      el.textContent = s;
      return el;
    };
    const row = (f) => {
      const box = document.createElement('input');
      box.type = 'checkbox';
      box.className = 'mtrk-orphan';
      box.value = f.path;
      box.disabled = f.kept;
      box.setAttribute('aria-label', f.path);
      const th = document.createElement('th');
      th.className = 'check-column';
      th.scope = 'row';
      th.append(box);
      const name = document.createElement('div');
      name.className = 'mtrk-qmedia';
      const url = fileUrl(f);
      const open = document.createElement('a');
      open.href = url;
      open.target = '_blank';
      open.rel = 'noopener';
      open.textContent = t.openTab;
      const show = document.createElement('button');
      show.type = 'button';
      show.className = 'button-link mtrk-orphan-preview';
      show.textContent = t.preview;
      show.setAttribute('aria-haspopup', 'dialog');
      if (isImage.test(f.path)) { // the thumbnail opens the same modal
        const img = document.createElement('img');
        img.src = url;
        img.alt = '';
        img.loading = 'lazy';
        const thumb = document.createElement('button');
        thumb.type = 'button';
        thumb.className = 'mtrk-thumb mtrk-orphan-preview';
        thumb.setAttribute('aria-label', `${t.preview} ${f.path}`);
        thumb.setAttribute('aria-haspopup', 'dialog');
        thumb.append(img);
        name.append(thumb);
      }
      const info = document.createElement('div');
      const path = text(`${orphans.dataset.root}/${f.path}`, 'code'); // the full path on the server
      path.dir = 'ltr';
      info.append(path, document.createElement('br'), show, ' · ', open);
      name.append(info);
      name.addEventListener('click', (e) => {
        if (e.target.closest('.mtrk-orphan-preview')) openFile(f, url, orphans.dataset.root);
      });
      const kind = f.kept ? t.orphanKept : (f.type === 'size' ? fmt(t.orphanSize, f.owner) : t.orphanFile);
      const tr = document.createElement('tr');
      tr.append(th, cell(name), cell(text(kind)), cell(text(size(f.bytes))));
      return tr;
    };
    const renderMore = () => {
      files.slice(shown, shown + PAGE).forEach((f) => tbody.append(row(f)));
      shown = Math.min(files.length, shown + PAGE);
      more.hidden = shown >= files.length;
      more.textContent = `${t.more} (${files.length - shown})`;
    };
    const selected = () => [...tbody.querySelectorAll('.mtrk-orphan:checked')].map((b) => b.value);
    const sync = () => {
      const n = selected().length;
      delBtn.disabled = !n;
      zipBtn.disabled = !n;
      count.textContent = n ? fmt(t.selected, n) : '';
    };

    const check = async () => {
      checkBtn.disabled = true;
      files = [];
      try {
        let cursor = 0;
        let folders = 0;
        do {
          const r = await post('mtrk_orphans', { do: 'step', cursor });
          files = files.concat(r.files);
          folders = Math.min(r.total, folders + 20);
          status.textContent = fmt(t.orphansChecking, folders);
          cursor = r.next;
        } while (cursor !== null);
        stash.set('mtrk-orphans', orphans.dataset.scan, files);
        display(Date.now());
      } catch (e) {
        status.textContent = e.message;
      }
      checkBtn.disabled = false;
    };
    const display = (at) => {
      files.sort((a, b) => (a.kept - b.kept) || (b.bytes - a.bytes)); // deletable and biggest first
      tbody.replaceChildren();
      shown = 0;
      renderMore();
      all.checked = false;
      sync();
      result.hidden = !files.length;
      const free = files.filter((f) => !f.kept);
      status.textContent = `${files.length ? fmt(t.orphansFound, free.length, size(free.reduce((n, f) => n + f.bytes, 0))) : t.orphansNone} ${checkedAt(at)}`;
    };

    checkBtn.addEventListener('click', check);
    const cached = stash.get('mtrk-orphans', orphans.dataset.scan);
    if (cached) {
      files = cached.data;
      display(cached.at);
    }
    more.addEventListener('click', renderMore);
    orphans.addEventListener('change', (e) => {
      if (e.target === all) {
        while (all.checked && shown < files.length) renderMore(); // "all" means all, not only the rows drawn
        tbody.querySelectorAll('.mtrk-orphan:not(:disabled)').forEach((b) => { b.checked = all.checked; });
      }
      sync();
    });
    // The ZIP is built on the server and arrives as a file; an error comes back as JSON and is shown here.
    zipBtn.addEventListener('click', async () => {
      const paths = selected();
      zipBtn.disabled = true;
      status.textContent = t.zipBuilding;
      try {
        await download({ action: 'mtrk_zip', paths: JSON.stringify(paths) }, 'extra-files.zip');
        status.textContent = fmt(t.zipDone, paths.length);
      } catch (e) {
        status.textContent = e.message;
      }
      sync();
    });
    // "Download list": built here from the result this tab already holds (every file, not only the rows drawn).
    orphans.querySelector('.mtrk-export').addEventListener('click', (e) => {
      const btn = e.target.closest('.mtrk-export-btn');
      if (!btn) return;
      const csv = btn.dataset.format === 'csv';
      const lines = csv
        ? [['path', 'url', 'server_path', 'size_bytes', 'type', 'owner_id', 'kept'],
          ...files.map((f) => [f.path, fileUrl(f), `${orphans.dataset.root}/${f.path}`, f.bytes, f.type, f.owner || '', f.kept ? 'yes' : 'no'])]
          .map((r) => r.map(csvCell).join(','))
        : files.map(fileUrl);
      saveBlob(new Blob([csv ? '﻿' : '', lines.join('\n'), '\n'], { type: `${csv ? 'text/csv' : 'text/plain'};charset=utf-8` }), `extra-files-${stamp()}.${btn.dataset.format}`);
      exportStatus(btn, fmt(t.exportDone, files.length));
    });
    delBtn.addEventListener('click', async () => {
      const paths = selected();
      if (!window.confirm(fmt(t.confirmFiles, paths.length))) return;
      delBtn.disabled = true;
      let removed = 0;
      let bytes = 0;
      try {
        for (let i = 0; i < paths.length; i += 200) {
          const r = await post('mtrk_orphans', { do: 'delete', paths: paths.slice(i, i + 200) });
          removed += r.files;
          bytes += r.bytes;
        }
        await check();
        status.textContent = fmt(t.sizesDeleted, removed, size(bytes));
      } catch (e) {
        status.textContent = e.message;
      }
    });
  }

  // Unregistered sizes: walk every attachment in batches to report, then again to delete the checked sizes.
  const sizes = document.getElementById('mtrk-sizes');
  if (sizes) {
    const status = sizes.querySelector('.mtrk-sizes-status');
    const result = sizes.querySelector('.mtrk-sizes-result');
    const tbody = sizes.querySelector('tbody');
    const checkBtn = sizes.querySelector('.mtrk-sizes-check');
    const cleanBtn = sizes.querySelector('.mtrk-sizes-clean');
    const all = sizes.querySelector('.mtrk-sizes-all');
    const sum = (list, key) => list.reduce((n, s) => n + s[key], 0);
    let report = {};

    const walk = async (data) => {
      const total = {};
      let cursor = 0;
      let seen = 0;
      do {
        const r = await post('mtrk_sizes', { ...data, cursor });
        Object.entries(r.sizes).forEach(([name, s]) => {
          const tot = total[name] || (total[name] = { files: 0, bytes: 0, kept: 0, dims: s.dims });
          tot.files += s.files;
          tot.bytes += s.bytes;
          tot.kept += s.kept;
        });
        cursor = r.next;
        seen += r.count;
        status.textContent = fmt(t.sizesChecking, seen);
      } while (cursor !== null);
      return total;
    };

    const render = (at) => {
      tbody.replaceChildren();
      const names = Object.keys(report).sort((a, b) => report[b].bytes - report[a].bytes);
      names.forEach((name) => {
        const s = report[name];
        const box = document.createElement('input');
        box.type = 'checkbox';
        box.className = 'mtrk-size';
        box.value = name;
        box.disabled = !s.files;
        box.setAttribute('aria-label', name);
        const th = document.createElement('th');
        th.className = 'check-column';
        th.scope = 'row';
        th.append(box);
        const tr = document.createElement('tr');
        tr.append(th, ...[name, s.dims, s.files, size(s.bytes), s.kept].map((v) => {
          const td = document.createElement('td');
          const bdi = document.createElement('bdi'); // keep "1170×600" and size names in their own direction in RTL
          bdi.textContent = v;
          td.append(bdi);
          return td;
        }));
        tbody.append(tr);
      });
      all.checked = false;
      cleanBtn.disabled = true;
      result.hidden = !names.length;
      const list = Object.values(report);
      status.textContent = `${names.length ? fmt(t.sizesFound, sum(list, 'files'), size(sum(list, 'bytes'))) : t.sizesNone} ${checkedAt(at)}`;
    };

    const selected = () => [...tbody.querySelectorAll('.mtrk-size:checked')].map((b) => b.value);
    sizes.addEventListener('change', (e) => {
      if (e.target === all) tbody.querySelectorAll('.mtrk-size:not(:disabled)').forEach((b) => { b.checked = all.checked; });
      cleanBtn.disabled = !selected().length;
    });

    const check = async () => {
      checkBtn.disabled = true;
      cleanBtn.disabled = true;
      try {
        report = await walk({ do: 'report' });
        stash.set('mtrk-sizes', sizes.dataset.scan, report);
        render(Date.now());
      } catch (e) {
        status.textContent = e.message;
      }
      checkBtn.disabled = false;
    };
    checkBtn.addEventListener('click', check);
    const cached = stash.get('mtrk-sizes', sizes.dataset.scan);
    if (cached) {
      report = cached.data;
      render(cached.at);
    }

    cleanBtn.addEventListener('click', async () => {
      const names = selected();
      const picked = names.map((n) => report[n]);
      if (!window.confirm(fmt(t.sizesConfirm, sum(picked, 'files'), size(sum(picked, 'bytes'))))) return;
      checkBtn.disabled = true;
      cleanBtn.disabled = true;
      try {
        const done = Object.values(await walk({ do: 'clean', names }));
        await check();
        status.textContent = fmt(t.sizesDeleted, sum(done, 'files'), size(sum(done, 'bytes')));
      } catch (e) {
        status.textContent = e.message;
        checkBtn.disabled = false;
      }
    });
  }

  // Scan page: start, then step until done. Steps are idempotent, so failures are retried.
  const form = document.getElementById('mtrk-form');
  const box = document.getElementById('mtrk-progress');
  if (!form || !box) return;
  const bar = box.querySelector('progress');
  const label = box.querySelector('.mtrk-label');
  const cancel = box.querySelector('.mtrk-cancel');
  const retry = box.querySelector('.mtrk-retry');
  let stop = false;

  const show = (p) => {
    form.hidden = true;
    box.hidden = false;
    bar.value = p.percent;
    label.textContent = `${p.percent}% — ${p.label}`;
  };

  const run = async () => {
    retry.hidden = true;
    let fails = 0;
    while (!stop) {
      let p;
      try {
        p = await post('mtrk_scan', { do: 'step' });
        fails = 0;
      } catch (e) {
        if (++fails > 3) {
          label.textContent = e.message;
          retry.hidden = false;
          return;
        }
        await sleep(2000);
        continue;
      }
      if (p.done) {
        bar.value = 100;
        label.textContent = t.done;
        window.location.reload();
        return;
      }
      show(p);
      if (p.busy) await sleep(2000);
    }
    await post('mtrk_scan', { do: 'cancel' }).catch(() => {});
    window.location.reload();
  };

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const fd = new FormData(form);
    try {
      show(await post('mtrk_scan', { do: 'start', code: fd.get('code'), tables: fd.get('tables') ? 1 : '' }));
      run();
    } catch (err) {
      window.alert(err.message);
    }
  });

  cancel.addEventListener('click', () => {
    stop = true;
    cancel.disabled = true;
    label.textContent = t.stopping;
  });
  retry.addEventListener('click', run);

  const running = JSON.parse(box.dataset.running || 'null');
  if (running) {
    show(running);
    run();
  }
})();
