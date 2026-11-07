// Loads a slice of an inline <script> from a Twig template and runs it in a
// sandbox, so the behaviour tests exercise the code the page actually ships
// (no copied function text that can drift from the template).
//
// The slice runs from the first line matching `from` to the end of the
// function declared by `fn` (brace-matched). Missing markers, or Twig syntax
// inside the slice, fail loudly: a renamed helper must break the suite, not
// silently skip it.
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';
import vm from 'node:vm';

const TEMPLATES = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../templates');

export function sliceTemplate(template, from, fn) {
  const src = readFileSync(path.join(TEMPLATES, template), 'utf8');
  const start = src.indexOf(from);
  if (start === -1) throw new Error(`${template}: start marker not found: ${from}`);
  const decl = src.indexOf(`function ${fn}(`, start);
  if (decl === -1) throw new Error(`${template}: function ${fn}( not found after ${from}`);
  const open = src.indexOf('{', decl);
  let depth = 0;
  let i = open;
  for (; i < src.length; i++) {
    const c = src[i];
    if (c === '{') depth++;
    else if (c === '}' && --depth === 0) break;
  }
  if (depth !== 0) throw new Error(`${template}: unbalanced braces in ${fn}`);
  const code = src.slice(start, i + 1);
  if (/\{\{|\{%|\{#/.test(code)) throw new Error(`${template}: Twig syntax inside the ${fn} slice`);
  return code;
}

// Runs the slice in a fresh context seeded with `globals`. Top-level `var`
// and function declarations become properties of the returned context.
export function runSlice(template, from, fn, globals = {}) {
  const ctx = vm.createContext({ ...globals });
  vm.runInContext(sliceTemplate(template, from, fn), ctx, { filename: template });
  return ctx;
}
