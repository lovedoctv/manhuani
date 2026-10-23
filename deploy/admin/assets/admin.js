// Blog admin: the post editor (formatting, links, images), and small helpers for the forms.
(() => {
  'use strict';

  // Buttons and forms that ask first ("Delete this post for good?")
  document.addEventListener('click', (e) => {
    const el = e.target.closest('button[data-confirm]');
    if (el && !confirm(el.dataset.confirm)) e.preventDefault();
  });
  document.querySelectorAll('form[data-confirm]').forEach((form) =>
    form.addEventListener('submit', (e) => { if (!confirm(form.dataset.confirm)) e.preventDefault(); }),
  );

  const form = document.querySelector('form[data-editor]');
  if (!form) return;

  const source = form.querySelector('textarea[name="body"]');
  const rte = form.querySelector('[data-rte]');
  const toolbar = form.querySelector('.toolbar');
  const imageInput = form.querySelector('[data-image-input]');
  let dirty = false;
  const markDirty = () => { dirty = true; };

  // ---------- Rich text editor ----------
  // The textarea holds the post as HTML. With JavaScript on, it's swapped for an editable box with
  // a toolbar; the server cleans whatever is sent, so only safe formatting is ever saved.
  rte.innerHTML = source.value.trim() || '<p><br></p>';
  rte.contentEditable = 'true';
  rte.setAttribute('role', 'textbox');
  rte.setAttribute('aria-multiline', 'true');
  rte.setAttribute('aria-label', 'Post text');
  rte.hidden = false;
  toolbar.hidden = false;
  source.hidden = true;
  try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch { /* older browsers */ }

  const exec = (cmd, value = null) => { rte.focus(); document.execCommand(cmd, false, value); markDirty(); syncToolbar(); };

  // The block the cursor is in (paragraph, heading, list item...)
  const currentBlock = () => {
    const sel = getSelection();
    let n = sel && sel.anchorNode;
    while (n && n !== rte) {
      if (n.nodeType === 1 && /^(P|H2|H3|H4|LI|BLOCKQUOTE|PRE)$/.test(n.nodeName)) return n;
      n = n.parentNode;
    }
    return null;
  };
  const blockSelect = toolbar.querySelector('[data-block]');
  function syncToolbar() {
    const b = currentBlock();
    const tag = b ? b.nodeName.toLowerCase() : 'p';
    blockSelect.value = ['h2', 'h3'].includes(tag) ? tag : 'p';
    toolbar.querySelectorAll('[data-cmd="bold"],[data-cmd="italic"],[data-cmd="insertUnorderedList"],[data-cmd="insertOrderedList"]').forEach((btn) => {
      let on = false;
      try { on = document.queryCommandState(btn.dataset.cmd); } catch { /* ignore */ }
      btn.classList.toggle('on', on);
    });
  }
  document.addEventListener('selectionchange', () => { if (rte.contains(getSelection().anchorNode)) syncToolbar(); });
  blockSelect.addEventListener('change', () => exec('formatBlock', `<${blockSelect.value}>`));

  // Keep the cursor's place while a prompt or file picker is open.
  let saved = null;
  const saveRange = () => { const s = getSelection(); saved = s.rangeCount && rte.contains(s.anchorNode) ? s.getRangeAt(0).cloneRange() : null; };
  const restoreRange = () => {
    rte.focus();
    const s = getSelection();
    s.removeAllRanges();
    if (saved) s.addRange(saved);
    else { const r = document.createRange(); r.selectNodeContents(rte); r.collapse(false); s.addRange(r); }
  };

  toolbar.addEventListener('mousedown', (e) => { if (e.target.closest('button')) e.preventDefault(); });
  toolbar.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-cmd]');
    if (!btn) return;
    const cmd = btn.dataset.cmd;
    if (cmd === 'link') {
      saveRange();
      const current = saved && saved.startContainer.parentElement && saved.startContainer.parentElement.closest('a');
      const url = prompt('Paste the link (https://...). Leave it empty to remove the link.', current ? current.getAttribute('href') : 'https://');
      restoreRange();
      if (url === null) return;
      if (!url.trim() || url.trim() === 'https://') return exec('unlink');
      let href = url.trim();
      if (/^www\./i.test(href)) href = `https://${href}`;
      if (!/^(https?:\/\/|mailto:|tel:|\/)/i.test(href)) { alert('Links must start with https://'); return; }
      if (getSelection().isCollapsed) {
        const a = document.createElement('a');
        a.href = href;
        a.textContent = href.replace(/^https?:\/\//, '');
        getSelection().getRangeAt(0).insertNode(a);
        markDirty();
      } else exec('createLink', href);
    } else if (cmd === 'quote') {
      const b = currentBlock();
      exec('formatBlock', b && b.nodeName === 'BLOCKQUOTE' ? '<p>' : '<blockquote>');
    } else if (cmd === 'image') {
      saveRange();
      imageInput.click();
    } else if (cmd === 'removeFormat') {
      exec('removeFormat');
      exec('formatBlock', '<p>');
    } else exec(cmd);
  });

  // Keyboard shortcuts most people already know
  rte.addEventListener('keydown', (e) => {
    if (!(e.ctrlKey || e.metaKey)) return;
    const k = e.key.toLowerCase();
    if (k === 'k') { e.preventDefault(); toolbar.querySelector('[data-cmd="link"]').click(); }
  });
  rte.addEventListener('input', markDirty);

  // Phone photos are big: make them 1600 pixels wide at most before they're sent, so they fit
  // under the server's upload limit and load fast on mobile data. GIFs are sent as they are.
  const maxMb = Number(form.dataset.maxMb || 8);
  async function shrink(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || !window.createImageBitmap) return file;
    try {
      const bmp = await createImageBitmap(file, { imageOrientation: 'from-image' });
      const scale = Math.min(1, 1600 / bmp.width);
      if (scale === 1 && file.size < 1.5 * 1048576) return file;
      const canvas = Object.assign(document.createElement('canvas'), { width: Math.round(bmp.width * scale), height: Math.round(bmp.height * scale) });
      canvas.getContext('2d').drawImage(bmp, 0, 0, canvas.width, canvas.height);
      const type = file.type === 'image/png' ? 'image/webp' : file.type === 'image/webp' ? 'image/webp' : 'image/jpeg';
      const blob = await new Promise((r) => canvas.toBlob(r, type, 0.85));
      if (!blob || blob.size >= file.size) return file;
      return new File([blob], file.name.replace(/\.\w+$/, '') + (type === 'image/jpeg' ? '.jpg' : '.webp'), { type });
    } catch {
      return file;
    }
  }

  // Images: uploaded straight away, then placed where the cursor was.
  imageInput.addEventListener('change', async () => {
    let file = imageInput.files[0];
    imageInput.value = '';
    if (!file) return;
    file = await shrink(file);
    if (file.size > maxMb * 1048576) { alert(`That image is over ${maxMb} MB. Use a smaller one.`); return; }
    const holder = document.createElement('p');
    holder.className = 'uploading';
    holder.textContent = `Uploading ${file.name}…`;
    restoreRange();
    const block = currentBlock();
    if (block && block.parentNode === rte) block.after(holder); else rte.append(holder);
    const body = new FormData();
    body.append('image', file);
    body.append('csrf', form.dataset.csrf);
    try {
      const res = await fetch(form.dataset.upload, { method: 'POST', body, headers: { 'X-CSRF-Token': form.dataset.csrf }, credentials: 'same-origin' });
      const data = await res.json().catch(() => ({ error: 'The upload failed. Try again.' }));
      if (!res.ok || !data.url) throw new Error(data.error || 'The upload failed. Try again.');
      const alt = prompt('Describe the image in a few words (for Google and screen readers):', '') || '';
      const fig = document.createElement('p');
      const img = document.createElement('img');
      img.src = data.url;
      img.alt = alt;
      fig.append(img);
      holder.replaceWith(fig);
      if (!fig.nextElementSibling) fig.after(Object.assign(document.createElement('p'), { innerHTML: '<br>' }));
      markDirty();
    } catch (err) {
      holder.remove();
      alert(err.message);
    }
  });

  // Pasting from Word, Google Docs or a website: keep the structure, drop the styling.
  const KEEP = { P: 1, H2: 1, H3: 1, H4: 1, UL: 1, OL: 1, LI: 1, BLOCKQUOTE: 1, STRONG: 1, B: 1, EM: 1, I: 1, A: 1, BR: 1, TABLE: 1, THEAD: 1, TBODY: 1, TR: 1, TH: 1, TD: 1, IMG: 1 };
  const DROP = /^(SCRIPT|STYLE|META|LINK|TITLE|IFRAME|OBJECT|EMBED|SVG|NOSCRIPT|TEMPLATE|FORM|INPUT|BUTTON|SELECT|TEXTAREA)$/;
  function tidy(node) {
    [...node.childNodes].forEach((n) => {
      if (n.nodeType === 8) return n.remove();
      if (n.nodeType !== 1) return;
      if (DROP.test(n.nodeName)) return n.remove();
      tidy(n);
      if (n.nodeName === 'H1') { const h = document.createElement('h2'); h.append(...n.childNodes); n.replaceWith(h); return; }
      if (!KEEP[n.nodeName]) {
        if (/^(DIV|SECTION|ARTICLE|HEADER|FOOTER)$/.test(n.nodeName) && !n.querySelector('p,h2,h3,h4,ul,ol,table,blockquote,div')) {
          const p = document.createElement('p'); p.append(...n.childNodes); n.replaceWith(p);
        } else n.replaceWith(...n.childNodes);
        return;
      }
      [...n.attributes].forEach((a) => { if (!['href', 'src', 'alt', 'colspan', 'rowspan'].includes(a.name)) n.removeAttribute(a.name); });
      if (n.nodeName === 'IMG' && !/^https:\/\//i.test(n.getAttribute('src') || '')) n.remove();
    });
  }
  rte.addEventListener('paste', (e) => {
    const html = e.clipboardData && e.clipboardData.getData('text/html');
    const text = e.clipboardData && e.clipboardData.getData('text/plain');
    if (!html && !text) return;
    e.preventDefault();
    if (html) {
      const doc = new DOMParser().parseFromString(html, 'text/html');
      tidy(doc.body);
      document.execCommand('insertHTML', false, doc.body.innerHTML);
    } else {
      const paras = text.replace(/\r/g, '').split(/\n\s*\n/).map((p) => p.trim()).filter(Boolean);
      const escape = (s) => s.replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' })[c]);
      document.execCommand('insertHTML', false, paras.length > 1 ? paras.map((p) => `<p>${escape(p).replace(/\n/g, '<br>')}</p>`).join('') : escape(text));
    }
    markDirty();
  });

  // ---------- The rest of the form ----------
  form.addEventListener('input', markDirty);
  form.addEventListener('submit', () => {
    source.value = rte.innerHTML;
    dirty = false;
  });
  addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  // The web address follows the title until it's changed by hand (new posts only).
  const title = form.querySelector('input[name="title"]');
  const slug = form.querySelector('[data-slug]');
  const slugify = (s) => s.toLowerCase().normalize('NFKD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '').slice(0, 80);
  let slugTouched = form.dataset.new !== '1' || slug.value !== '';
  slug.addEventListener('input', () => { slugTouched = true; slug.value = slugify(slug.value.replace(/\s+/g, '-')) + (slug.value.endsWith('-') ? '-' : ''); });
  title.addEventListener('input', () => { if (!slugTouched) slug.value = slugify(title.value); });

  // Summary length counter
  const desc = form.querySelector('textarea[data-count]');
  const count = form.querySelector('[data-count-for="description"]');
  const showCount = () => {
    const n = desc.value.trim().length;
    const ideal = Number(desc.dataset.count);
    count.textContent = `${n} characters. ${n < 30 ? 'A little more, please.' : n > ideal ? `Google shows about ${ideal}, so put the key point first.` : 'Good length.'}`;
  };
  desc.addEventListener('input', showCount);
  showCount();

  // Key facts: add another empty row
  form.querySelector('[data-add-fact]').addEventListener('click', () => {
    const rows = form.querySelector('[data-facts]');
    if (rows.children.length >= 8) { alert('Up to 8 facts fit in the box.'); return; }
    const row = rows.firstElementChild.cloneNode(true);
    row.querySelectorAll('input').forEach((i) => { i.value = ''; });
    rows.append(row);
    row.querySelector('input').focus();
  });

  // Cover image: made smaller like the others, and shown before it's saved
  const cover = form.querySelector('[data-cover-input]');
  cover.addEventListener('change', async () => {
    form.querySelector('.cover-preview')?.remove();
    let file = cover.files[0];
    if (!file) return;
    const small = await shrink(file);
    if (small !== file && window.DataTransfer) {
      try { const dt = new DataTransfer(); dt.items.add(small); cover.files = dt.files; file = small; } catch { /* keep the original */ }
    }
    if (file.size > maxMb * 1048576) { alert(`That image is over ${maxMb} MB. Use a smaller one.`); cover.value = ''; return; }
    const img = Object.assign(document.createElement('img'), { src: URL.createObjectURL(file), alt: 'New cover image', className: 'cover-preview' });
    cover.before(img);
  });

  // A post from the site's code with published changes: check that its address really shows them
  // (post.php marks the pages it makes). If the server skips the .htaccess rule, the original stays up.
  const live = form.dataset.checkLive;
  if (live) {
    fetch(live, { cache: 'no-store', credentials: 'omit' }).then((r) => {
      if (!r.ok || r.headers.get('X-Post-Source') === 'admin') return;
      const warn = Object.assign(document.createElement('p'), { className: 'flash error',
        textContent: 'Your changes are saved, but the site still shows the original version of this post. Deploy the site again in cPanel. If it still happens, the server is skipping a rule in .htaccess.' });
      warn.setAttribute('role', 'alert');
      form.before(warn);
    }).catch(() => {});
  }
})();
