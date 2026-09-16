#!/usr/bin/env node
/**
 * Render all Mermaid diagram sources in docs/images/src/*.mmd to
 * PNG + SVG in docs/images/ using the public mermaid.ink service.
 *
 * Usage:  node scripts/render-diagrams.js
 * Needs:  Node 18+ (global fetch). No other dependencies.
 * The .mmd sources are extracted from docs/*.md mermaid fences, so the
 * images always match the documentation.
 */
const fs = require('fs');
const path = require('path');

const SRC = path.join(__dirname, '..', 'docs', 'images', 'src');
const OUT = path.join(__dirname, '..', 'docs', 'images');

async function render(name, code) {
  const state = Buffer.from(
    JSON.stringify({ code, mermaid: { theme: 'default' } })
  ).toString('base64url');

  for (const [type, ext] of [['png', 'png'], ['svg', 'svg']]) {
    const url = `https://mermaid.ink/img/${state}?type=${type}&width=1600`;
    const res = await fetch(url);
    if (!res.ok) {
      throw new Error(`${name}.${ext}: HTTP ${res.status}`);
    }
    const buf = Buffer.from(await res.arrayBuffer());
    const file = path.join(OUT, `${name}.${ext}`);
    fs.writeFileSync(file, buf);
    console.log(`  ok ${path.relative(process.cwd(), file)} (${(buf.length / 1024).toFixed(1)} KB)`);
  }
}

(async () => {
  const files = fs.readdirSync(SRC).filter((f) => f.endsWith('.mmd'));
  if (!files.length) {
    console.error('No .mmd sources found in', SRC);
    process.exit(1);
  }
  let failed = 0;
  for (const f of files) {
    const name = path.basename(f, '.mmd');
    const code = fs.readFileSync(path.join(SRC, f), 'utf8');
    process.stdout.write(`Rendering ${name}...\n`);
    try {
      await render(name, code);
    } catch (e) {
      failed++;
      console.error(`  FAIL ${name}: ${e.message}`);
    }
  }
  console.log(failed ? `\n${failed} diagram(s) failed` : '\nAll diagrams rendered.');
  process.exit(failed ? 1 : 0);
})();
