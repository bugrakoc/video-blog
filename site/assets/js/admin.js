(function () {
  'use strict';

  // Confirm before destructive actions: <form data-confirm="...">
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
      e.preventDefault();
    }
  });

  // Auto-continue long jobs: <form data-autosubmit="800"> submits itself after that many ms.
  var auto = document.querySelector('form[data-autosubmit]');
  if (auto) {
    setTimeout(function () { auto.submit(); }, parseInt(auto.getAttribute('data-autosubmit'), 10) || 800);
  }

  // Video editor: thumbnail preview + fetch the title from YouTube.
  var urlInput = document.getElementById('youtube_url');
  if (!urlInput) return;

  var titleInput = document.getElementById('title');
  var statusEl = document.getElementById('yt-status');
  var previewBox = document.getElementById('yt-preview');
  var thumb = document.getElementById('yt-thumb');
  var button = document.getElementById('fetch-title');
  var lastFetched = '';

  function videoId(v) {
    v = (v || '').trim();
    if (/^[A-Za-z0-9_-]{11}$/.test(v)) return v;
    var m = v.match(/(?:youtu\.be\/|youtube(?:-nocookie)?\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/|v\/))([A-Za-z0-9_-]{11})/);
    return m ? m[1] : null;
  }

  function setStatus(text) { if (statusEl) statusEl.textContent = text; }

  function showThumb(id) {
    if (!previewBox || !thumb) return;
    if (id) {
      thumb.src = 'https://i.ytimg.com/vi/' + id + '/hqdefault.jpg';
      previewBox.hidden = false;
    } else {
      previewBox.hidden = true;
    }
  }

  function fetchTitle(force) {
    var id = videoId(urlInput.value);
    showThumb(id);
    if (!id) { setStatus('Geçerli bir YouTube bağlantısı değil.'); return; }
    if (!force && (titleInput.value.trim() !== '' || lastFetched === id)) return;
    lastFetched = id;
    setStatus('Başlık getiriliyor…');
    fetch('yt-info.php?url=' + encodeURIComponent(urlInput.value), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.title) {
          if (force || titleInput.value.trim() === '') titleInput.value = d.title;
          setStatus('Başlık getirildi' + (d.author ? ' (' + d.author + ')' : '') + '. Açıklamayı YouTube’dan kopyalayıp metin alanına yapıştırın.');
        } else {
          setStatus((d && d.error) || 'Başlık alınamadı; elle yazabilirsiniz.');
        }
      })
      .catch(function () { setStatus('Başlık alınamadı; elle yazabilirsiniz.'); });
  }

  urlInput.addEventListener('change', function () { fetchTitle(false); });
  urlInput.addEventListener('paste', function () { setTimeout(function () { fetchTitle(false); }, 0); });
  if (button) button.addEventListener('click', function () { fetchTitle(true); });
})();
