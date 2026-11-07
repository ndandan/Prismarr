// cmdState(): the *arr command-poll classifier shared by the films, missing
// films and series pages (MT-7, review 2026-11-06). Each page carries its own
// copy, so every case runs against all three templates.
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { runSlice } from './support/template-script.mjs';

const PAGES = ['media/films.html.twig', 'media/films_missing.html.twig', 'media/series.html.twig'];

function load(template) {
  return runSlice(template, 'var CMD_TERMINAL', 'cmdState').cmdState;
}

for (const template of PAGES) {
  describe(`cmdState (${template})`, () => {
    it('passes running states through', () => {
      const cmdState = load(template);
      assert.equal(cmdState({ id: 1, status: 'queued' }), 'queued');
      assert.equal(cmdState({ id: 1, status: 'started' }), 'started');
      assert.equal(cmdState({ id: 1, status: 'completed' }), 'completed');
    });

    it('maps every *arr terminal state to failed, case-insensitively', () => {
      const cmdState = load(template);
      for (const st of ['failed', 'aborted', 'cancelled', 'orphaned', 'Failed', 'ABORTED']) {
        assert.equal(cmdState({ id: 2, status: st }), 'failed', st);
      }
    });

    it('turns five unknown answers in a row into stalled, not failed', () => {
      const cmdState = load(template);
      for (let n = 1; n <= 4; n++) assert.equal(cmdState({ id: 3, status: 'unknown' }), 'unknown', `answer ${n}`);
      assert.equal(cmdState({ id: 3, status: 'unknown' }), 'stalled');
    });

    it('counts a missing or empty status as unknown', () => {
      const cmdState = load(template);
      for (let n = 1; n <= 4; n++) assert.equal(cmdState({ id: 4, status: n % 2 ? '' : undefined }), 'unknown');
      assert.equal(cmdState({ id: 4 }), 'stalled');
    });

    it('resets the unknown streak when a real status comes back', () => {
      const cmdState = load(template);
      for (let n = 0; n < 4; n++) cmdState({ id: 5, status: 'unknown' });
      assert.equal(cmdState({ id: 5, status: 'started' }), 'started');
      for (let n = 0; n < 4; n++) assert.equal(cmdState({ id: 5, status: 'unknown' }), 'unknown');
    });

    it('gives up as stalled at the poll cap', () => {
      const ctx = runSlice(template, 'var CMD_TERMINAL', 'cmdState');
      const cap = ctx.CMD_POLL_CAP;
      assert.ok(cap > 1, 'CMD_POLL_CAP is defined');
      for (let n = 1; n < cap; n++) assert.equal(ctx.cmdState({ id: 6, status: 'started' }), 'started', `poll ${n}`);
      assert.equal(ctx.cmdState({ id: 6, status: 'started' }), 'stalled');
    });

    it('still reports completed when it lands on the poll cap', () => {
      const ctx = runSlice(template, 'var CMD_TERMINAL', 'cmdState');
      for (let n = 1; n < ctx.CMD_POLL_CAP; n++) ctx.cmdState({ id: 7, status: 'started' });
      assert.equal(ctx.cmdState({ id: 7, status: 'completed' }), 'completed');
    });

    it('tracks each command id separately', () => {
      const cmdState = load(template);
      for (let n = 0; n < 4; n++) cmdState({ id: 8, status: 'unknown' });
      assert.equal(cmdState({ id: 9, status: 'unknown' }), 'unknown');
      assert.equal(cmdState({ id: 8, status: 'unknown' }), 'stalled');
    });
  });
}
