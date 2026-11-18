(function () {
  'use strict';

  // Confirm before destructive actions: <form data-confirm="...">
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute && e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
      e.preventDefault();
    } else if (timer) {
      clearTimeout(timer);
    }
  });

  // Auto-continue long jobs: <form data-autosubmit="800"> submits itself after that many ms.
  var auto = document.querySelector('form[data-autosubmit]');
  var timer = auto && setTimeout(function () {
    auto.requestSubmit();
  }, parseInt(auto.getAttribute('data-autosubmit'), 10) || 800);

  // Video editor: thumbnail preview + fetch the title from YouTube.
  var urlInput = document.getElementById('youtube_url');
  if (!urlInput) return;

  var titleInput = document.getElementById('title');
  var statusEl = document.getElementById('yt-status');
  var previewBox = document.getElementById('yt-preview');
  var thumb = document.getElementById('yt-thumb');
  var button = document.getElementById('fetch-title');
  var request = 0;
  var pending = null;
  var autoTitle = null;

  function videoId(value) {
    var input = value.trim();
    var pattern = /^[A-Za-z0-9_-]{11}$/;
    if (pattern.test(input)) return input;

    var parsed;
    try {
      parsed = new URL(input.includes('://') ? input : 'https://' + input);
    } catch (err) {
      return null;
    }
    if (!['http:', 'https:'].includes(parsed.protocol) || parsed.username || parsed.password) return null;

    var id = null;
    if (parsed.hostname === 'youtu.be') {
      id = parsed.pathname.replace(/^\/+|\/+$/g, '');
    } else if (['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com',
                'youtube-nocookie.com', 'www.youtube-nocookie.com'].includes(parsed.hostname)) {
      var path = parsed.pathname.match(/^\/(?:embed|shorts|live|v)\/([^/]+)\/?$/);
      if (parsed.pathname === '/watch') {
        var ids = parsed.searchParams.getAll('v');
        id = ids.length ? ids[ids.length - 1] : null;
      } else if (path) {
        id = path[1];
      }
    }
    return id && pattern.test(id) ? id : null;
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
    if (!id) {
      request++;
      pending = null;
      setStatus('Geçerli bir YouTube bağlantısı değil.');
      return;
    }
    var previousTitle = titleInput.value;
    if (!force && ((previousTitle.trim() !== '' && previousTitle !== autoTitle) || pending === id)) return;

    var currentRequest = ++request;
    pending = id;
    setStatus('Başlık getiriliyor…');
    fetch('yt-info.php?url=' + encodeURIComponent(id), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (request !== currentRequest || videoId(urlInput.value) !== id) return;
        if (d && d.title) {
          if (titleInput.value === previousTitle) {
            titleInput.value = d.title;
            autoTitle = d.title;
          }
          setStatus('Başlık getirildi' + (d.author ? ' (' + d.author + ')' : '') + '. Açıklamayı YouTube’dan kopyalayıp metin alanına yapıştırın.');
        } else {
          setStatus((d && d.error) || 'Başlık alınamadı; elle yazabilirsiniz.');
        }
      })
      .catch(function () {
        if (request === currentRequest && videoId(urlInput.value) === id) {
          setStatus('Başlık alınamadı; elle yazabilirsiniz.');
        }
      })
      .finally(function () {
        if (request === currentRequest) pending = null;
      });
  }

  urlInput.addEventListener('input', function () {
    var id = videoId(urlInput.value);
    showThumb(id);
    if (pending && pending !== id) {
      request++;
      pending = null;
      setStatus('');
    }
  });
  urlInput.addEventListener('change', function () { fetchTitle(false); });
  urlInput.addEventListener('paste', function () { setTimeout(function () { fetchTitle(false); }, 0); });
  if (button) button.addEventListener('click', function () { fetchTitle(true); });
})();
