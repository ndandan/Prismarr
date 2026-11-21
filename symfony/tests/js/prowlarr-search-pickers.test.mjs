// Prowlarr search pickers (batch 2): the Category dropdown lists Prowlarr's
// standard categories when searching every indexer, and the chosen indexer's
// own categories (including its custom ones, ids >= 100000) otherwise.
// Sub-categories are indented one level under their parent.
import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { runSlice } from './support/template-script.mjs';

const load = () => runSlice('prowlarr/index.html.twig', '/* ── Prowlarr search pickers', 'pwSearchQueryString', {});

const DEFAULTS = [
  { id: 2000, name: 'Movies', subCategories: [{ id: 2040, name: 'Movies/HD' }] },
  { id: 5000, name: 'TV', subCategories: [] },
];

describe('pwSearchCategoryOptions', () => {
  it('lists the standard categories when no indexer is chosen', () => {
    const opts = load().pwSearchCategoryOptions(null, DEFAULTS);
    assert.deepEqual(JSON.parse(JSON.stringify(opts)), [
      { id: 2000, name: 'Movies', depth: 0 },
      { id: 2040, name: 'Movies/HD', depth: 1 },
      { id: 5000, name: 'TV', depth: 0 },
    ]);
  });

  it("uses the indexer's own categories, custom ones included", () => {
    const indexer = { id: 5, name: 'Tracker', categories: [
      { id: 2000, name: 'Movies', subCategories: [{ id: 100023, name: 'Movies/UHD Remux' }] },
    ] };
    const ids = Array.from(load().pwSearchCategoryOptions(indexer, DEFAULTS), (o) => o.id);
    assert.deepEqual(ids, [2000, 100023]);
  });

  it('skips malformed entries', () => {
    const opts = load().pwSearchCategoryOptions(null, [{ name: 'no id' }, { id: 3000, name: 'Audio', subCategories: [{ id: 'x' }] }]);
    assert.deepEqual(Array.from(opts, (o) => o.id), [3000]);
  });
});

describe('pwSearchQueryString', () => {
  it('adds the indexer and category only when chosen', () => {
    const f = load().pwSearchQueryString;
    assert.equal(f('dune part two', '', ''), 'q=dune%20part%20two');
    assert.equal(f('dune', '5', '2000'), 'q=dune&indexerId=5&categories=2000');
  });
});
