// Bazarr jobs chip (batch 2): "2 running · 1 queued 40%" next to the Bazarr
// nav, with the running job's message as the tooltip. Hidden (null) when the
// queue is idle or Bazarr has no job queue (< 1.6).
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { runSlice } from './support/template-script.mjs';

const I18N = { running_tpl: '__N__ running', pending_tpl: '__N__ queued', title: 'Bazarr jobs' };
const chip = (d) => runSlice('bazarr/_jobs_chip.html.twig', '/* ── Bazarr jobs chip', 'bzJobsChip', {}).bzJobsChip(d, I18N);

describe('bzJobsChip', () => {
  it('counts running and queued jobs, with progress and the job as tooltip', () => {
    const c = chip({ supported: true, running: 2, pending: 1, message: 'Syncing subtitles: The Matrix', progress: 40 });
    assert.equal(c.text, '2 running · 1 queued · 40%');
    assert.equal(c.title, 'Bazarr jobs: Syncing subtitles: The Matrix');
  });

  it('shows only what is non-zero', () => {
    assert.equal(chip({ supported: true, running: 0, pending: 3, message: null, progress: null }).text, '3 queued');
    assert.equal(chip({ supported: true, running: 1, pending: 0, message: null, progress: null }).title, 'Bazarr jobs');
  });

  it('hides when idle, unsupported or unreadable', () => {
    assert.equal(chip({ supported: true, running: 0, pending: 0 }), null);
    assert.equal(chip({ supported: false, running: 2, pending: 0 }), null);
    assert.equal(chip(null), null);
  });
});
