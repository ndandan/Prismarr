// refreshData(): the torrent-list poller on the Deluge, qBittorrent and
// Transmission pages (DC-3, review 2026-11-06). A timer tick while a fetch is
// in flight is skipped; a forced refresh aborts the stale fetch; a sequence
// number drops answers that arrive after a newer request was issued.
//
// The Deluge copy once shipped without its `refreshSeq` declaration and threw
// a ReferenceError on every tick — running each page's own slice catches that.
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { runSlice } from './support/template-script.mjs';

const PAGES = ['deluge/index.html.twig', 'qbittorrent/index.html.twig', 'transmission/index.html.twig'];

const flush = () => new Promise((resolve) => setImmediate(resolve));

// honourAbort=false models the race abort() cannot stop: the old answer had
// already arrived when the newer request was issued.
function load(template, { honourAbort = true } = {}) {
  const calls = [];
  const rendered = { stats: [], list: [], pagination: [] };
  const fetch = (url, opts) =>
    new Promise((resolve, reject) => {
      const call = {
        url,
        signal: opts.signal,
        respond: (data) => resolve({ json: () => Promise.resolve(data) }),
        fail: () => reject(new TypeError('Failed to fetch')),
      };
      if (honourAbort) {
        opts.signal.addEventListener('abort', () => reject(new DOMException('Aborted', 'AbortError')));
      }
      calls.push(call);
    });
  const ctx = runSlice(template, 'var refreshCtrl = null;', 'refreshData', {
    fetch,
    AbortController,
    URLSearchParams,
    BASE: '/torrents',
    pagState: { page: 2, perPage: 50, sort: 'name', desc: true, filter: 'all', search: 'linux', category: 'iso', tag: 'keep' },
    updateStats: (s) => rendered.stats.push(s),
    updateList: (t) => rendered.list.push(t),
    updatePagination: (p) => rendered.pagination.push(p),
  });
  return { ctx, calls, rendered };
}

const answer = (name) => ({ stats: { name }, torrents: [name], pagination: { page: 1 } });

for (const template of PAGES) {
  describe(`refreshData (${template})`, () => {
    it('fetches the list with the pagination state', () => {
      const { ctx, calls } = load(template);
      ctx.refreshData();
      assert.equal(calls.length, 1);
      const [pathPart, query] = calls[0].url.split('?');
      assert.equal(pathPart, '/torrents/api/torrents');
      const q = new URLSearchParams(query);
      assert.equal(q.get('page'), '2');
      assert.equal(q.get('perPage'), '50');
      assert.equal(q.get('desc'), '1');
      assert.equal(q.get('search'), 'linux');
      assert.equal(q.get('category'), 'iso');
    });

    it('renders the answer and then accepts the next tick', async () => {
      const { ctx, calls, rendered } = load(template);
      ctx.refreshData();
      calls[0].respond(answer('a'));
      await flush();
      assert.deepEqual(rendered.list, [['a']]);
      assert.equal(ctx.refreshInFlight, false);
      ctx.refreshData();
      assert.equal(calls.length, 2);
    });

    it('skips a timer tick while the previous fetch is in flight', () => {
      const { ctx, calls } = load(template);
      ctx.refreshData();
      ctx.refreshData();
      assert.equal(calls.length, 1);
      assert.equal(calls[0].signal.aborted, false);
    });

    it('aborts the stale fetch on a forced refresh', async () => {
      const { ctx, calls, rendered } = load(template);
      ctx.refreshData();
      ctx.refreshData(true);
      assert.equal(calls.length, 2);
      assert.equal(calls[0].signal.aborted, true);
      calls[1].respond(answer('b'));
      await flush();
      assert.deepEqual(rendered.list, [['b']]);
      assert.equal(ctx.refreshInFlight, false);
    });

    it('drops an older answer that lands after a newer request', async () => {
      const { ctx, calls, rendered } = load(template, { honourAbort: false });
      ctx.refreshData();
      ctx.refreshData(true);
      calls[1].respond(answer('new'));
      await flush();
      calls[0].respond(answer('old'));
      await flush();
      assert.deepEqual(rendered.list, [['new']]);
    });

    it('keeps the newer request in flight when the older one settles first', async () => {
      const { ctx, calls, rendered } = load(template, { honourAbort: false });
      ctx.refreshData();
      ctx.refreshData(true);
      calls[0].respond(answer('old'));
      await flush();
      assert.equal(ctx.refreshInFlight, true);
      ctx.refreshData();
      assert.equal(calls.length, 2, 'tick still skipped');
      assert.deepEqual(rendered.list, []);
    });

    it('clears the in-flight flag after a network failure', async () => {
      const { ctx, calls, rendered } = load(template);
      ctx.refreshData();
      calls[0].fail();
      await flush();
      assert.equal(ctx.refreshInFlight, false);
      assert.deepEqual(rendered.list, []);
      ctx.refreshData();
      assert.equal(calls.length, 2);
    });

    it('renders nothing for an error payload but keeps polling', async () => {
      const { ctx, calls, rendered } = load(template);
      ctx.refreshData();
      calls[0].respond({ error: 'unreachable' });
      await flush();
      assert.deepEqual(rendered, { stats: [], list: [], pagination: [] });
      assert.equal(ctx.refreshInFlight, false);
    });
  });
}

describe('refreshData (qbittorrent/index.html.twig) tag filter', () => {
  it('sends the tag filter', () => {
    const { ctx, calls } = load('qbittorrent/index.html.twig');
    ctx.refreshData();
    assert.equal(new URLSearchParams(calls[0].url.split('?')[1]).get('tag'), 'keep');
  });
});
