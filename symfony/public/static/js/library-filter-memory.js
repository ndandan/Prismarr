// Persists per-tab library filters (films/series) in localStorage so each
// tab reopens with the user's last-used filters. Design doc:
// docs/superpowers/specs/2026-07-12-persistent-library-filters-design.md
(function () {
  'use strict';

  var EXCLUDE = { q: 1, page: 1, open: 1 };

  function storageKey(type, slug) {
    return 'prismarr_' + type + '_filters:' + slug;
  }

  // Build the tracked query string: drop search/page/open + empty values.
  function trackedQS(search) {
    var params = new URLSearchParams(search || '');
    var out = new URLSearchParams();
    params.forEach(function (v, k) {
      if (EXCLUDE[k]) return;
      if (v === '' || v == null) return;
      out.append(k, v);
    });
    return out.toString();
  }

  function readSaved(key) {
    try { return localStorage.getItem(key) || ''; } catch (e) { return ''; }
  }
  function writeSaved(key, qs) {
    try { localStorage.setItem(key, qs); } catch (e) {}
  }
  function clearSaved(key) {
    try { localStorage.removeItem(key); } catch (e) {}
  }

  // Per-page save/restore. Called from the films/series page scripts.
  function initPage(opts) {
    var key = storageKey(opts.type, opts.slug);
    var qs = trackedQS(window.location.search);

    if (qs) {
      // Viewing a filtered URL — remember it.
      writeSaved(key, qs);
    } else if (!window.location.search) {
      // Completely empty URL — restore saved filters if any.
      var saved = readSaved(key);
      if (saved) {
        window.location.replace(window.location.pathname + '?' + saved);
        return; // navigating away; skip binding resets on a dead page
      }
    }

    // Reset/clear controls wipe saved state before their normal navigation.
    document.querySelectorAll(opts.resetSelector).forEach(function (el) {
      el.addEventListener('click', function () { clearSaved(key); });
    });
  }

  // Global: rewrite marked library nav links to carry the saved filters, so
  // the common navigation path lands filtered with no reload flash.
  function rewriteNavLinks() {
    var links = document.querySelectorAll('a.js-libfilter-link');
    for (var i = 0; i < links.length; i++) {
      var a = links[i];
      var href = a.getAttribute('href') || '';
      if (href.indexOf('?') !== -1) continue; // already carries a query
      var m = href.match(/\/medias\/([^/]+)\/(films|series)(?:[?#]|$)/);
      if (!m) continue;
      var saved = readSaved(storageKey(m[2], m[1]));
      if (saved) a.setAttribute('href', href + '?' + saved);
    }
  }

  document.addEventListener('turbo:load', rewriteNavLinks);
  document.addEventListener('DOMContentLoaded', rewriteNavLinks);

  window.PrismarrLibFilters = {
    initPage: initPage,
    rewriteNavLinks: rewriteNavLinks,
    _storageKey: storageKey,
    _trackedQS: trackedQS
  };
})();
