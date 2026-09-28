const fs = require('fs');

const files = [
    __dirname + '/../language/en-GB/plg_system_fgcustomrightclick.ini',
    __dirname + '/../language/sk-SK/plg_system_fgcustomrightclick.ini',
    __dirname + '/../language/en-GB/plg_system_fgcustomrightclick.sys.ini',
    __dirname + '/../language/sk-SK/plg_system_fgcustomrightclick.sys.ini',
];

let failures = 0;
function assert(cond, msg) {
    if (cond) { console.log('  PASS: ' + msg); }
    else { failures++; console.log('  FAIL: ' + msg); }
}

console.log('TEST: no language file uses backslash-escaped double quotes or a bare backslash/dollar sign');
{
    // Real-world incident: a line using \"button\" (the officially
    // documented Joomla escaping method) rendered fine when parsed with
    // plain PHP parse_ini_file(), but broke Joomla's own runtime language
    // parser badly enough that two entire admin tabs (Popup, Custom menu)
    // silently vanished from the plugin's configuration screen - Joomla
    // changed how \ and $ are handled in language files around 4.4.1/
    // 5.0.1 while moving toward a "raw parser" for 6.0. The only fully
    // safe fix is to never need escaping at all: use single quotes for
    // any quoted text inside a value, and avoid literal \ or $ entirely.
    files.forEach((file) => {
        const content = fs.readFileSync(file, 'utf8');
        const relative = file.replace(__dirname + '/../', '');

        assert(!content.includes('\\"'), `${relative}: no backslash-escaped double quote (\\") anywhere`);
        assert(!content.includes('\\'), `${relative}: no literal backslash character anywhere`);
        assert(!content.includes('$'), `${relative}: no literal dollar sign anywhere`);
    });
}

// Keys whose value is DELIBERATE, well-formed admin-UI markup (the FG
// support/Ko-fi block, identical to the one used across the FG plugin
// series). Everything else must contain no markup at all.
const HTML_ALLOWED_KEYS = new Set([
    'PLG_SYSTEM_FGCUSTOMRIGHTCLICK_FIELD_SUPPORT_DESC',
]);

// HTML5 "raw text" / escapable-raw-text elements: once opened, the
// browser treats EVERYTHING after them as inert text until the matching
// closing tag. An unclosed one silently swallows the rest of the page -
// this is exactly what broke the admin form in v1.12.2 (an unclosed
// "<noscript>" in a field description). These must ALWAYS be closed
// within the same value, even in allowlisted keys.
const RAW_TEXT_ELEMENTS = ['script', 'style', 'noscript', 'textarea', 'title', 'iframe', 'xmp', 'noembed', 'noframes', 'plaintext'];
const VOID_ELEMENTS = new Set(['br', 'img', 'hr', 'input', 'meta', 'link', 'wbr', 'source', 'area', 'col', 'embed', 'param', 'track']);

function parseIni(content) {
    const entries = [];
    content.split(/\r?\n/).forEach((line) => {
        const m = line.match(/^([A-Z0-9_]+)="(.*)"\s*$/);
        if (m) {
            entries.push({ key: m[1], value: m[2] });
        }
    });
    return entries;
}

console.log('TEST: no literal HTML-tag-like sequence (<letter...) outside the explicit allowlist of deliberate-markup keys');
{
    // CONFIRMED root cause of a real production incident (v1.12.2): a
    // field description containing the literal, unclosed text
    // "<noscript>" (meant as plain prose, not markup) silently broke the
    // entire rest of the admin edit page. Joomla renders field
    // descriptions as raw HTML, not escaped text. Refer to tags/features
    // in quotes ('noscript', 'video') instead - never literal angle
    // brackets - except in keys that are deliberately markup.
    files.forEach((file) => {
        const relative = file.replace(__dirname + '/../', '');
        const offenders = parseIni(fs.readFileSync(file, 'utf8'))
            .filter((e) => !HTML_ALLOWED_KEYS.has(e.key) && /<[a-zA-Z]/.test(e.value))
            .map((e) => e.key);
        assert(
            offenders.length === 0,
            `${relative}: no literal HTML-tag-like sequence outside allowlisted keys` + (offenders.length ? ` (found in: ${offenders.join(', ')})` : '')
        );
    });
}

console.log('TEST: every raw-text element (script/style/noscript/...) opened in ANY value is closed within that same value');
{
    files.forEach((file) => {
        const relative = file.replace(__dirname + '/../', '');
        const problems = [];
        parseIni(fs.readFileSync(file, 'utf8')).forEach((e) => {
            RAW_TEXT_ELEMENTS.forEach((tag) => {
                const opens = (e.value.match(new RegExp('<' + tag + '(\\s|>|/)', 'gi')) || []).length;
                const closes = (e.value.match(new RegExp('</' + tag + '\\s*>', 'gi')) || []).length;
                if (opens !== closes) {
                    problems.push(`${e.key} (<${tag}>: ${opens} opened, ${closes} closed)`);
                }
            });
        });
        assert(problems.length === 0, `${relative}: all raw-text elements balanced` + (problems.length ? ` - ${problems.join('; ')}` : ''));
    });
}

console.log('TEST: allowlisted markup keys are well-formed (every non-void tag closed, correctly nested)');
{
    files.forEach((file) => {
        const relative = file.replace(__dirname + '/../', '');
        parseIni(fs.readFileSync(file, 'utf8'))
            .filter((e) => HTML_ALLOWED_KEYS.has(e.key))
            .forEach((e) => {
                // Strip the contents of <style> blocks first - CSS like
                // "a[target='_blank']" is not markup to be balanced.
                const html = e.value.replace(/<style>[\s\S]*?<\/style>/gi, '');
                const stack = [];
                let error = null;
                const tagRe = /<(\/?)([a-zA-Z][a-zA-Z0-9]*)\b[^>]*?(\/?)>/g;
                let m;
                while ((m = tagRe.exec(html)) !== null && !error) {
                    const closing = m[1] === '/';
                    const name = m[2].toLowerCase();
                    if (VOID_ELEMENTS.has(name) || m[3] === '/') {
                        continue;
                    }
                    if (!closing) {
                        stack.push(name);
                    } else if (stack.pop() !== name) {
                        error = `unexpected </${name}>`;
                    }
                }
                if (!error && stack.length) {
                    error = `unclosed: ${stack.join(', ')}`;
                }
                assert(!error, `${relative}: ${e.key} is well-formed markup` + (error ? ` (${error})` : ''));
            });
    });
}

console.log(failures === 0 ? '\nALL LANGUAGE-INI-LINT TESTS PASSED' : '\n' + failures + ' TEST(S) FAILED');
process.exit(failures ? 1 : 0);
