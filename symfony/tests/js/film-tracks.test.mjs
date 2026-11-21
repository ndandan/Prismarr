// Radarr 7 per-track media info in the film detail panel (films page): audio
// tracks as "Language Codec Channels", subtitle tracks as language chips with
// the same F / HI markers the Bazarr chips use. Language codes are ISO 639-2
// ("eng", "fre") rendered through Intl.DisplayNames in the page language.
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { runSlice } from './support/template-script.mjs';

const I18N = { subtitle_hi_title: 'Hearing impaired', subtitle_forced_title: 'Forced' };

function load(lang = 'en') {
  return runSlice('media/films.html.twig', '/* ── Radarr 7 per-track media info', 'filmSubtitleTracksHtml', {
    window: { escHtml: (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;') },
    document: { documentElement: { lang } },
    FILM_I18N: I18N,
    Intl,
  });
}

describe('filmTrackLang', () => {
  it('names ISO 639-2 codes in the page language', () => {
    assert.equal(load('en').filmTrackLang('eng'), 'English');
    assert.equal(load('en').filmTrackLang('fre'), 'French');
    assert.equal(load('fr').filmTrackLang('ger'), 'allemand');
  });

  it('returns null for undetermined or empty, and the bare code when unknown', () => {
    const f = load('en').filmTrackLang;
    assert.equal(f('und'), null);
    assert.equal(f(''), null);
    assert.equal(f(null), null);
    assert.equal(f('pob'), 'POB');
  });
});

describe('filmAudioTracksHtml', () => {
  it('lists every track as language, codec and channels', () => {
    const html = load('en').filmAudioTracksHtml([
      { language: 'eng', codec: 'TrueHD Atmos', channels: 7.1, title: 'Main' },
      { language: 'fre', codec: 'AC3', channels: 5.1, title: null },
    ]);
    assert.equal(html, 'English TrueHD Atmos 7.1 · French AC3 5.1');
  });

  it('skips missing parts and escapes upstream text', () => {
    const html = load('en').filmAudioTracksHtml([{ language: 'und', codec: '<b>AAC</b>', channels: 2 }]);
    assert.equal(html, '&lt;b&gt;AAC&lt;/b&gt; 2');
  });

  it('returns null for an empty or missing list', () => {
    const f = load('en').filmAudioTracksHtml;
    assert.equal(f([]), null);
    assert.equal(f(null), null);
  });
});

describe('filmSubtitleTracksHtml', () => {
  it('renders one chip per track with forced and HI markers', () => {
    const html = load('en').filmSubtitleTracksHtml([
      { language: 'eng', forced: true, hearingImpaired: false },
      { language: 'spa', forced: false, hearingImpaired: true },
    ]);
    assert.match(html, /English<sup title="Forced">F<\/sup><\/span>/);
    assert.match(html, /Spanish<sup title="Hearing impaired">HI<\/sup><\/span>/);
    assert.equal((html.match(/<span class="badge/g) || []).length, 2);
  });

  it('shows an undetermined track as a dash and returns null when empty', () => {
    const f = load('en').filmSubtitleTracksHtml;
    assert.match(f([{ language: 'und', forced: false, hearingImpaired: false }]), />—<\/span>/);
    assert.equal(f([]), null);
  });
});
