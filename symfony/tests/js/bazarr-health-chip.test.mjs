// Bazarr health badge (batch 3): "⚠ 2 issues" in the Bazarr nav, opening a
// list of Bazarr's own health issues (object in bold, then the issue). Hidden
// (null) when Bazarr is healthy or can't be read.
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { runSlice } from './support/template-script.mjs';

const I18N = { issue_one: '1 issue', issues_tpl: '__N__ issues' };
const esc = (s) => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
const chip = (d) => runSlice('bazarr/_health_chip.html.twig', '/* ── Bazarr health chip', 'bzHealthChip', {
  window: { escHtml: esc },
}).bzHealthChip(d, I18N);

describe('bzHealthChip', () => {
  it('counts the issues and lists each with its object', () => {
    const c = chip({ ok: true, issues: [
      { object: '/movies', issue: 'Not accessible' },
      { object: '', issue: 'No languages profile' },
    ] });
    assert.equal(c.text, '2 issues');
    assert.equal((c.html.match(/<li/g) || []).length, 2);
    assert.match(c.html, /<strong>\/movies<\/strong>/);
    assert.match(c.html, /No languages profile/);
    assert.doesNotMatch(c.html, /<strong><\/strong>/, 'no empty object label');
  });

  it('uses the singular for one issue', () => {
    assert.equal(chip({ ok: true, issues: [{ object: 'x', issue: 'y' }] }).text, '1 issue');
  });

  it('escapes what Bazarr sends', () => {
    const c = chip({ ok: true, issues: [{ object: '<b>o</b>', issue: '<img src=x onerror=alert(1)>' }] });
    assert.doesNotMatch(c.html, /<b>o<\/b>|<img/);
    assert.match(c.html, /&lt;img/);
  });

  it('hides when healthy or unreadable', () => {
    assert.equal(chip({ ok: true, issues: [] }), null);
    assert.equal(chip({ ok: false, issues: [] }), null);
    assert.equal(chip(null), null);
  });
});

describe('bzHealthOpensLeft', () => {
  // Live 2026-11-21: on desktop the badge sits near the right edge and the
  // list ran off-screen; on a phone the nav wraps and the badge is at the left.
  const opensLeft = (rect, vw) => runSlice('bazarr/_health_chip.html.twig', '/* ── Bazarr health chip', 'bzHealthOpensLeft', {
    window: { escHtml: esc },
  }).bzHealthOpensLeft(rect, vw);

  it('opens leftwards when the list would run off the right edge', () => {
    assert.equal(opensLeft({ left: 596, right: 1012 }, 945), true);
  });

  it('keeps opening rightwards when it fits', () => {
    assert.equal(opensLeft({ left: 26, right: 364 }, 375), false);
  });
});
