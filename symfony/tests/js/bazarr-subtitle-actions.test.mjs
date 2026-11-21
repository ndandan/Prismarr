// Bazarr subtitle modal, "Current subtitles" section (batch 2): which tools
// each subtitle offers, and the escaped markup. Sync needs an external file;
// Extract needs an embedded track with Bazarr 1.6's database id; Translate
// needs a source (file or embedded id) and at least one missing language
// to translate into.
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { runSlice } from './support/template-script.mjs';

const I18N = {
  current_title: 'Current subtitles', embedded: 'Embedded', sync: 'Sync', translate: 'Translate',
  extract: 'Extract', translate_to: 'Translate to', hi_title: 'Hearing impaired', forced_title: 'Forced',
};

function load() {
  return runSlice('bazarr/_search_modal.html.twig', '/* ── Bazarr current subtitles', 'bzCurrentSubsHtml', {
    window: { escHtml: (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;') },
    I18N,
  });
}

const ext = { name: 'English', code2: 'en', path: '/movies/X/x.en.srt', forced: false, hi: false, id: 3, embedded: false };
const emb = { name: 'French', code2: 'fr', path: null, forced: false, hi: true, id: 4, embedded: true };
const missing = [{ name: 'German', code2: 'de', forced: false, hi: false }];
const missingWithOwn = [{ name: 'English', code2: 'en', forced: true, hi: false }, ...missing];

describe('bzActionsFor', () => {
  it('offers Sync and Translate on an external file', () => {
    assert.deepEqual(Array.from(load().bzActionsFor(ext, missing)), ['sync', 'translate']);
  });

  it('offers Translate and Extract on an embedded track', () => {
    assert.deepEqual(Array.from(load().bzActionsFor(emb, missing)), ['translate', 'extract']);
  });

  it('offers nothing on an embedded track from Bazarr older than 1.6 (no id)', () => {
    assert.deepEqual(Array.from(load().bzActionsFor({ ...emb, id: null }, missing)), []);
  });

  it("never offers translating into the subtitle's own language", () => {
    // Live 2026-11-21: an English (HI) file with "English forced" missing
    // offered English -> English.
    const enForced = [{ name: 'English', code2: 'en', forced: true, hi: false }];
    assert.deepEqual(Array.from(load().bzActionsFor(ext, enForced)), ['sync']);
    assert.deepEqual(Array.from(load().bzActionsFor(ext, [...enForced, ...missing])), ['sync', 'translate']);
  });

  it('drops Translate when no language is missing', () => {
    assert.deepEqual(Array.from(load().bzActionsFor(ext, [])), ['sync']);
  });
});

describe('bzCurrentSubsHtml', () => {
  it('lists only other languages as translation targets', () => {
    const html = load().bzCurrentSubsHtml({ tracked: true, subtitles: [ext], missing: missingWithOwn });
    assert.match(html, /<option value="1">German<\/option>/, 'option values index the full missing list');
    assert.doesNotMatch(html, /<option[^>]*>English/, 'English subtitles are not translated into English');
  });

  it('renders every subtitle row with its tools and the file name', () => {
    const html = load().bzCurrentSubsHtml({ tracked: true, subtitles: [ext, emb], missing });
    assert.equal((html.match(/data-bz-sub="/g) || []).length, 2);
    assert.match(html, /x\.en\.srt/);
    assert.doesNotMatch(html, /\/movies\/X\/x\.en\.srt</, 'shows the file name, not the whole path');
    assert.match(html, />Embedded</);
    assert.match(html, /data-bz-sub-action="sync" data-idx="0"/);
    assert.match(html, /data-bz-sub-action="extract" data-idx="1"/);
    assert.match(html, /<option value="0">German<\/option>/);
    assert.match(html, /<sup title="Hearing impaired">HI<\/sup>/);
  });

  it('escapes names and paths from Bazarr', () => {
    const html = load().bzCurrentSubsHtml({ tracked: true, subtitles: [{ ...ext, name: '<b>x</b>', path: '/m/<i>.srt' }], missing: [] });
    assert.doesNotMatch(html, /<b>x<\/b>|<i>\.srt/);
    assert.match(html, /&lt;b&gt;x&lt;\/b&gt;/);
  });

  it('renders nothing when untracked or empty', () => {
    const f = load().bzCurrentSubsHtml;
    assert.equal(f({ tracked: false, subtitles: [ext], missing }), '');
    assert.equal(f({ tracked: true, subtitles: [], missing }), '');
    assert.equal(f(null), '');
  });
});
