/**
 * One-off script: stamps every source file with an `Author:` line.
 *
 * Idempotent — re-running is a no-op for files that already have it.
 * Honors language conventions:
 *   - `'use client'` directive must stay on line 1 (Next.js requires it).
 *   - `<?php` opening tag must stay on line 1.
 *   - `#!/usr/bin/env …` shebangs (if any) stay on line 1.
 *
 * Run from project root:  node sign-files.mjs
 */

import { readFileSync, writeFileSync } from 'node:fs'
import { join, extname } from 'node:path'
import { readdir, stat } from 'node:fs/promises'

const SIG = 'Author: Saleh Akbari <saleh.akbari.programmer@gmail.com>'
const TS_SIG  = `// ${SIG}`
const PHP_SIG = `// ${SIG}`

// Roots to walk + extensions to stamp
const TARGETS = [
  { root: 'frontend/src',                exts: ['.ts', '.tsx'], style: 'ts'  },
  { root: 'backend/app',                 exts: ['.php'],         style: 'php' },
  { root: 'backend/routes',              exts: ['.php'],         style: 'php' },
  { root: 'backend/database/migrations', exts: ['.php'],         style: 'php' },
  { root: 'backend/database/seeders',    exts: ['.php'],         style: 'php' },
]

// Directory names we never descend into
const SKIP_DIRS = new Set([
  'node_modules', '.next', '.git', 'vendor', 'storage', 'public', 'dist', 'build',
])

let stamped = 0, alreadyHad = 0, skipped = 0

async function walk(dir, exts, style) {
  let entries
  try { entries = await readdir(dir, { withFileTypes: true }) }
  catch { return }
  for (const e of entries) {
    const full = join(dir, e.name)
    if (e.isDirectory()) {
      if (SKIP_DIRS.has(e.name)) continue
      await walk(full, exts, style)
    } else if (exts.includes(extname(e.name))) {
      stamp(full, style)
    }
  }
}

function stamp(file, style) {
  let src
  try { src = readFileSync(file, 'utf8') } catch { skipped++; return }

  // Idempotency check — by full signature, not just name, so an unrelated
  // mention of "Saleh" in a comment doesn't trick us.
  if (src.includes(SIG)) { alreadyHad++; return }

  const lines = src.split(/\r?\n/)
  const sig = style === 'ts' ? TS_SIG : PHP_SIG

  // Decide where to insert.
  //   - Right after `'use client'` (Next.js client component directive)
  //   - Right after `<?php` (PHP open tag)
  //   - Right after `#!/...` shebang (rare)
  //   - Otherwise at the very top.
  let insertAt = 0
  const first = (lines[0] ?? '').trim()
  if (/^['"]use (client|server)['"];?$/.test(first)) insertAt = 1
  else if (/^<\?php/.test(first))                     insertAt = 1
  else if (/^#!/.test(first))                         insertAt = 1

  lines.splice(insertAt, 0, sig)
  const out = lines.join('\n')

  try {
    writeFileSync(file, out, 'utf8')
    stamped++
  } catch (err) {
    console.error('skip (write failed):', file, err.message)
    skipped++
  }
}

const start = Date.now()
for (const { root, exts, style } of TARGETS) {
  try {
    await stat(root)
  } catch {
    console.error('skip missing root:', root)
    continue
  }
  await walk(root, exts, style)
}
console.log(
  `stamped=${stamped}  already_had=${alreadyHad}  skipped=${skipped}  ` +
  `(${Date.now() - start} ms)`,
)
