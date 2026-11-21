// Radarr 7 per-track media info in the film detail panel (films page): audio
// tracks one per line as "Language Codec Channels", subtitle tracks as
// language chips (duplicates merged as "×N") with the same F / HI markers the
// Bazarr chips use. Long lists (remuxes carry 10+ audio and 20+ subtitle
// tracks) show the first few and fold the rest into a native <details>.
// Language codes are ISO 639-2 ("eng", "fre") rendered through
// Intl.DisplayNames in the page language.
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { runSlice } from './support/template-script.mjs';

const I18N = { subtitle_hi_title: 'Hearing impaired', subtitle_forced_title: 'Forced', tracks_more_tpl: '+__N__ more' };

function load(lang = 'en') {
  return runSlice('media/films.html.twig', '/* ── Radarr 7 per-track media info', 'filmSubtitleTracksHtml', {
    window: { escHtml: (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;') },
    document: { documentElement: { lang } },
    FILM_I18N: I18N,
    Intl,
  });
}

const audio = (language, codec = 'EAC3', channels = 5.1) => ({ language, codec, channels, title: null });
const sub = (language, forced = false, hearingImpaired = false) => ({ language, forced, hearingImpaired });

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
  it('puts every track on its own line', () => {
    const html = load('en').filmAudioTracksHtml([audio('eng', 'TrueHD Atmos', 7.1), audio('fre', 'AC3')]);
    assert.equal(html, '<div class="film-track">English TrueHD Atmos 7.1</div><div class="film-track">French AC3 5.1</div>');
  });

  it('shows three lines and folds the rest of a long list', () => {
    const tracks = ['eng', 'eng', 'spa', 'cze', 'hun', 'ita', 'pol', 'por', 'rus', 'tha', 'tur'].map((l) => audio(l));
    const html = load('en').filmAudioTracksHtml(tracks);
    const [visible, folded] = html.split('<details');
    assert.equal((visible.match(/class="film-track"/g) || []).length, 3);
    assert.match(folded, /<summary[^>]*>\+8 more<\/summary>/);
    assert.equal((folded.match(/class="film-track"/g) || []).length, 8);
  });

  it('does not fold four tracks into a "+1 more"', () => {
    const html = load('en').filmAudioTracksHtml(['eng', 'fre', 'ger', 'spa'].map((l) => audio(l)));
    assert.doesNotMatch(html, /<details/);
    assert.equal((html.match(/class="film-track"/g) || []).length, 4);
  });

  it('skips missing parts and escapes upstream text', () => {
    const html = load('en').filmAudioTracksHtml([{ language: 'und', codec: '<b>AAC</b>', channels: 2 }]);
    assert.equal(html, '<div class="film-track">&lt;b&gt;AAC&lt;/b&gt; 2</div>');
  });

  it('returns null for an empty or missing list', () => {
    const f = load('en').filmAudioTracksHtml;
    assert.equal(f([]), null);
    assert.equal(f(null), null);
  });
});

describe('filmSubtitleTracksHtml', () => {
  it('renders one chip per track with forced and HI markers', () => {
    const html = load('en').filmSubtitleTracksHtml([sub('eng', true), sub('spa', false, true)]);
    assert.match(html, /English<sup title="Forced">F<\/sup><\/span>/);
    assert.match(html, /Spanish<sup title="Hearing impaired">HI<\/sup><\/span>/);
    assert.equal((html.match(/<span class="badge/g) || []).length, 2);
  });

  it('merges identical tracks into one chip with a count', () => {
    const html = load('en').filmSubtitleTracksHtml([sub('chi'), sub('kor'), sub('chi'), sub('eng', true), sub('eng')]);
    assert.equal((html.match(/<span class="badge/g) || []).length, 4, 'Chinese once; English forced and plain stay apart');
    assert.match(html, /Chinese ×2<\/span>/);
    assert.doesNotMatch(html, /Korean ×/);
  });

  it('shows eight chips and folds the rest of a long list', () => {
    const codes = ['eng', 'chi', 'kor', 'por', 'ara', 'ind', 'bul', 'hrv', 'cze', 'est', 'gre', 'heb'];
    const html = load('en').filmSubtitleTracksHtml(codes.map((c) => sub(c)));
    const [visible, folded] = html.split('<details');
    assert.equal((visible.match(/<span class="badge/g) || []).length, 8);
    assert.match(folded, /<summary[^>]*>\+4 more<\/summary>/);
    assert.equal((folded.match(/<span class="badge/g) || []).length, 4);
  });

  it('shows an undetermined track as a dash and returns null when empty', () => {
    const f = load('en').filmSubtitleTracksHtml;
    assert.match(f([sub('und')]), />—<\/span>/);
    assert.equal(f([]), null);
  });
});
