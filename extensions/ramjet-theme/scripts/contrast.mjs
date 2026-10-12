/**
 * WCAG contrast check for src/client/theme.css (hex tokens only).
 *
 *   npm run contrast     # prints every pair, exits 1 on any failure
 *
 * Text pairs need 4.5:1, graphics (focus ring, chart series) 3:1.
 */
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const read = (rel) => readFileSync(fileURLToPath(new URL(rel, import.meta.url)), 'utf8');
const css = read('../src/client/theme.css').replace(/\/\*[\s\S]*?\*\//g, '');
const tokens = Object.fromEntries([...css.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)].map((m) => [m[1], m[2].trim()]));

function hex(name) {
    let v = tokens[name];
    for (let i = 0; v?.startsWith('var('); i++) {
        if (i > 5) throw new Error(`alias loop at ${name}`);
        v = tokens[v.slice(4, -1).trim()];
    }
    if (!v || !/^#[0-9a-f]{6}$/i.test(v)) throw new Error(`${name} is not a 6-digit hex (${v})`);
    return v;
}
function lum(h) {
    const [r, g, b] = [1, 3, 5].map((i) => {
        const c = parseInt(h.slice(i, i + 2), 16) / 255;
        return c <= 0.04045 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    });
    return 0.2126 * r + 0.7152 * g + 0.0722 * b;
}
const ratio = (a, b) => {
    const [x, y] = [lum(hex(a)), lum(hex(b))];
    return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
};

const TEXT = 4.5;
const GRAPHIC = 3;
const surfaces = ['--background', '--card', '--muted', '--popover', '--secondary'];
const checks = [];
for (const s of surfaces) {
    checks.push(['--foreground', s, TEXT], ['--muted-foreground', s, TEXT], ['--primary', s, TEXT], ['--accent', s, TEXT]);
    checks.push(['--destructive', s, TEXT], ['--success', s, TEXT], ['--warning', s, TEXT]);
}
checks.push(
    ['--primary-foreground', '--primary', TEXT],
    ['--accent-foreground', '--accent', TEXT],
    ['--destructive-foreground', '--destructive', TEXT],
    ['--success-foreground', '--success', TEXT],
    ['--warning-foreground', '--warning', TEXT],
    ['--caution-foreground', '--caution', TEXT],
    ['--ring', '--popover', GRAPHIC],
    ['--chart-1', '--card', GRAPHIC],
    ['--chart-2', '--card', GRAPHIC],
    ['--chart-3', '--card', GRAPHIC],
    ['--chart-4', '--card', GRAPHIC],
    ['--chart-5', '--card', GRAPHIC],
    ['--terminal-foreground', '--terminal-background', TEXT],
    ['--terminal-ansi-bright-black', '--terminal-background', TEXT],
    ['--terminal-selection-foreground', '--terminal-selection', TEXT],
    ['--editor-foreground', '--editor-background', TEXT],
    ['--editor-muted', '--editor-background', TEXT],
    ['--editor-foreground', '--editor-selection', TEXT],
    ['--editor-foreground', '--editor-search-match-selected', TEXT],
);
for (const n of ['red', 'green', 'yellow', 'blue', 'magenta', 'cyan', 'white']) {
    checks.push([`--terminal-ansi-${n}`, '--terminal-background', TEXT], [`--terminal-ansi-bright-${n}`, '--terminal-background', TEXT]);
}
for (const n of ['keyword', 'name', 'function', 'constant', 'definition', 'type', 'operator', 'string', 'invalid']) {
    checks.push([`--editor-syntax-${n}`, '--editor-background', TEXT]);
}

let failures = 0;
for (const [fg, bg, min] of checks) {
    const r = ratio(fg, bg);
    const ok = r >= min;
    if (!ok) failures++;
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${r.toFixed(2).padStart(5)}:1 (min ${min})  ${fg} on ${bg}`);
}

const serverColor = /THEME_COLOR\s*=\s*'([^']+)'/.exec(read('../src/RamjetThemeProvider.php'))?.[1];
if (serverColor?.toLowerCase() !== hex('--theme-color').toLowerCase() || hex('--theme-color') !== hex('--background')) {
    console.log('FAIL --theme-color, --background and RamjetThemeProvider::THEME_COLOR must be equal');
    failures++;
}

console.log(failures ? `\n${failures} failing pair(s)` : '\nall pairs pass');
process.exit(failures ? 1 : 0);
