#!/usr/bin/env node
/**
 * Makes docs/images/src/erd-full.mmd compatible with older Mermaid versions
 * (the ones behind kroki.io), then renders PNG + SVG to docs/images/.
 * Newer Mermaid (GitHub) renders the original file fine.
 */
const fs = require('fs');
const path = require('path');

const SRC = path.join(__dirname, '..', 'docs', 'images', 'src', 'erd-full.mmd');
const OUT = path.join(__dirname, '..', 'docs', 'images');

let code = fs.readFileSync(SRC, 'utf8');

// 1. Attribute types with spaces ("int unsigned x") confuse old parsers -> drop the word "unsigned"
code = code.replace(/\bint unsigned\b/g, 'int');

// 2. Quoted keys like "DERIVED: units - rejects" are fine, but parentheses inside
//    quoted comments can break old versions -> replace parens with square brackets
code = code.replace(/"([^"]*)\(([^)]*)\)"/g, '"$1[$2]"');

// 3. P.V.C dots in attribute comments are fine, but normalize anyway for old parsers
code = code.replace(/P\.V\.C/g, 'PVC');

// 4. Relationship labels: strip parentheses (e.g. "issued_by (CEO)")
code = code.replace(/: "([^"]*)\(([^)]*)\)"/g, ': "$1 - $2"');

fs.writeFileSync(path.join(OUT, 'src', 'erd-full-compat.mmd'), code);

(async () => {
  for (const ext of ['svg', 'png']) {
    const res = await fetch('https://kroki.io/mermaid/' + ext, {
      method: 'POST',
      headers: { 'Content-Type': 'text/plain' },
      body: code,
    });
    if (!res.ok) {
      console.error(`kroki ${ext}: HTTP ${res.status}`, (await res.text()).slice(0, 200));
      process.exit(1);
    }
    const buf = Buffer.from(await res.arrayBuffer());
    const file = path.join(OUT, `erd-full.${ext}`);
    fs.writeFileSync(file, buf);
    console.log(`ok ${file} (${(buf.length / 1024).toFixed(1)} KB)`);
  }
})();
