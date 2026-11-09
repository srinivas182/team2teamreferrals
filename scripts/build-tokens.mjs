// Generates resources/css/theme.css from design/tokens.json (the single source of truth, ADR 0003).
// Run: npm run tokens. CI fails if the committed file is out of date.
import { readFileSync, writeFileSync } from 'node:fs';

const tokens = JSON.parse(readFileSync(new URL('../design/tokens.json', import.meta.url)));
const get = (path) => path.split('.').reduce((o, k) => o[k], tokens);
const resolve = (v) => (typeof v === 'string' && v.startsWith('{') ? resolve(get(v.slice(1, -1)).$value) : v);

const out = ['/* Generated from design/tokens.json by scripts/build-tokens.mjs — do not edit by hand. */', ''];

// Theme-dependent values become CSS variables switched by light/dark.
const themeVars = (mode) => Object.entries(tokens.color.theme[mode]).map(([k, t]) => `    --t-${k}: ${resolve(t.$value)};`).join('\n');
out.push(':root {', themeVars('light'), '    color-scheme: light;', '}');
out.push('@media (prefers-color-scheme: dark) {', '    :root:not([data-theme="light"]) {', themeVars('dark').replace(/^/gm, '    '), '        color-scheme: dark;', '    }', '}');
out.push(':root[data-theme="dark"] {', themeVars('dark'), '    color-scheme: dark;', '}', '');

// Dark variant follows the system setting unless the user picked a theme.
out.push('@custom-variant dark {');
out.push('    @media (prefers-color-scheme: dark) {', '        &:where(:root:not([data-theme="light"]), :root:not([data-theme="light"]) *) {', '            @slot;', '        }', '    }');
out.push('    &:where([data-theme="dark"], [data-theme="dark"] *) {', '        @slot;', '    }', '}', '');

const theme = [];
for (const scale of ['cobalt', 'orange', 'navy']) {
    for (const [step, t] of Object.entries(tokens.color[scale])) theme.push(`    --color-${scale}-${step}: ${t.$value};`);
}
for (const [k, t] of Object.entries(tokens.color.semantic)) theme.push(`    --color-${k}: ${t.$value};`);
for (const [k, t] of Object.entries(tokens.color.temperature)) theme.push(`    --color-${k}: ${t.$value};`);
theme.push(`    --color-sender: ${resolve(tokens.color.role.sender.$value)};`, `    --color-receiver: ${resolve(tokens.color.role.receiver.$value)};`);
for (const [k, t] of Object.entries(tokens.radius)) theme.push(`    --radius-${k}: ${t.$value};`);
for (const [k, t] of Object.entries(tokens.shadow)) theme.push(`    --shadow-${k}: ${t.$value};`);
for (const [k, t] of Object.entries(tokens.motion.duration)) theme.push(`    --duration-${k}: ${t.$value};`);
for (const [k, t] of Object.entries(tokens.motion.easing)) theme.push(`    --ease-${k}: cubic-bezier(${t.$value.join(', ')});`);
theme.push(`    --container-content: ${tokens.layout['content-max'].$value};`);
out.push('@theme {', ...theme, '}', '');

// Semantic, theme-aware colours used by components (bg-surface, text-muted, border-line…).
const inline = Object.keys(tokens.color.theme.light).map((k) => `    --color-${k}: var(--t-${k});`);
// Short aliases so components read naturally: text-ink, text-muted, border-line, bg-raised.
inline.push('    --color-ink: var(--t-text);', '    --color-muted: var(--t-text-muted);', '    --color-subtle: var(--t-text-subtle);', '    --color-line: var(--t-border);', '    --color-raised: var(--t-surface-raised);');
out.push('@theme inline {', ...inline, '}', '');

writeFileSync(new URL('../resources/css/theme.css', import.meta.url), out.join('\n'));
console.log('theme.css written');
