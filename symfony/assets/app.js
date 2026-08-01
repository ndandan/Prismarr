import './stimulus_bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 *
 * styles/app.css is NOT imported here: under the enforcing script-src CSP
 * (no `data:`), AssetMapper's `data:application/javascript,` stub module for
 * a CSS import gets blocked by the browser, and that failure aborts this
 * entire module graph (Turbo, Stimulus, Alpine, Chart.js never load). It is
 * linked directly from base.html.twig instead.
 */

console.log('This log comes from assets/app.js - welcome to AssetMapper! 🎉');
