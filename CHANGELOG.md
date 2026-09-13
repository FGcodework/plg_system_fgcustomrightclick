# Changelog - plg_system_fgcustomrightclick

## 1.13.1 (2026-08-29)

### Admin UX - reordered the Plugin tab's fields for a more logical flow

- **"Disable right click" (`rightclick_mode`) moved to the top**, right
  after "Apply to user groups" - it is the main mode selector that most
  of the rest of the plugin's behaviour depends on, and was previously
  buried after several unrelated toggles (print, select, drag, etc.),
  which made the form read out of order relative to how the settings
  actually relate to each other.
- New field order: user groups -> right-click mode -> its two
  mode-2-specific sub-options (video/background-image protection, right
  where they logically belong, immediately under the setting that
  reveals them) -> the independent protections (print, select, drag,
  anti-framing, save, devtools) -> the interactive-elements exemption and
  its own sub-option (extra exception selectors) last, since those are
  fine-tuning that applies across every mode rather than a primary
  choice.
- **Purely cosmetic**: field order in the XML has no effect on parameter
  names, storage, or any PHP/JS logic - `$this->params->get(...)` reads
  by name regardless of position. Confirmed via a direct count that
  every field appears in the manifest exactly once after reordering (no
  field lost or duplicated), and the full test suite passes unchanged.

## 1.13.0 (2026-08-29)

### New feature: "Prevent framing on other sites" (anti-framing / anti-clickjacking)

- Added `anti_framing`, off by default: when enabled, sends the
  `X-Frame-Options: SAMEORIGIN` HTTP response header, so another
  website cannot embed this site's pages inside their own
  `<frame>`/`<iframe>`/`<object>`/`<embed>` - a distinct, page-level
  protection from the copy/right-click features, and one that works at
  the HTTP level regardless of whether JavaScript runs at all. Added
  after comparing against a competing extension (AntiCopy /
  jextbox.com) that offers this as one of its features.
- **Deliberately X-Frame-Options only, not also a separate
  `Content-Security-Policy: frame-ancestors` header** (verified this is
  the modern equivalent, and that `frame-ancestors` cannot be delivered
  via a `<meta>` tag at all - confirmed directly against MDN and the
  OWASP CSP cheat sheet - it only works as a real HTTP header). Per
  Joomla's own documented `setHeader()` behaviour, two plugins each
  adding a same-named header can result in only the last one actually
  being sent rather than both being combined, which risks silently
  clobbering a site's own, more specific CSP policy if it already has a
  dedicated CSP plugin. `X-Frame-Options: SAMEORIGIN` carries no such
  collision risk and remains respected by effectively every browser in
  real-world use.
- The header is sent independently of every other feature's "nothing to
  do" check - it needs no CSS or JS at all, so it fires even with
  `rightclick_mode` set to "No" and nothing else enabled, and correctly
  respects the same user-group/component/URL exclusion guards as
  everything else in the plugin.
- Sent with `replace=true`, so repeated calls (or an unusual request
  lifecycle invoking the hook more than once) cannot result in duplicate
  header entries.
- Added `test_fg_crc_anti_framing_php.php` (6 PHP assertions, with a
  stub `setHeader()` capturing name/value/replace): the header is sent
  correctly when enabled (even with no other protection active), never
  sent when disabled, never accompanied by a separate CSP header, and
  the exclusion guards don't crash when combined with this option.

## 1.12.3 (2026-08-29)

### Admin UX - clarified why the Popup/Custom menu tabs can appear empty

- **Not a bug, but a real point of confusion**: the Popup and Custom menu
  tabs' fields are all conditionally shown via `showon`, tied to the
  "Disable right click" selection on the Plugin tab (`1` for Popup,
  `3` for Custom menu). With any other mode selected, both tabs
  legitimately show nothing at all - which looks identical to something
  being broken, especially right after the real missing-tabs incident
  fixed in 1.12.2.
- Added a permanently-visible blue info note (Joomla's built-in `note`
  form field type, `class="alert alert-info"`) at the top of both tabs,
  explaining exactly which "Disable right click" value each tab depends
  on. Unlike a regular field's `description` (hidden by default behind
  the "Toggle Inline Help" button since Joomla 4), a `note` field's text
  is always shown - the right choice for something that needs to be
  seen immediately, not discovered via a toggle.
- The note fields carry no value and are never read by the plugin's PHP
  code - confirmed nothing references them, consistent with how Joomla's
  `note` field type works (display only, nothing submitted or stored).
- Both new strings were written and verified against
  `test_fg_crc_language_ini_lint.js` before being added, specifically to
  avoid any repeat of the exact class of bug fixed in 1.12.2.

## 1.12.2 (2026-08-29)

### ACTUAL root cause found and fixed - v1.12.1 addressed a real but different bug

- **The real culprit**: the "Accessibility" tab's `noscript_warning` field
  description contained the literal, unclosed text `<noscript>` as plain
  English prose ("...HTML's native `<noscript>` tag..."). Joomla renders
  field descriptions as raw HTML, not escaped text. Per the HTML5 spec,
  `<noscript>` is a "raw text" element when scripting is enabled -
  meaning the browser treats *everything* from that point onward as
  inert text, not markup, until it finds a matching `</noscript>`
  string. With none anywhere later in the page, this silently swallowed
  the rest of the DOM - including the Popup and Custom menu tabs, and
  evidently enough of the page's structure to also break the Save/
  Save & Close toolbar buttons - with zero console errors, since the
  browser was correctly following the spec rather than hitting a parse
  error. Confirmed directly from a full page-source dump: both tabs'
  markup was present and completely correct server-side, proving this
  was a client-side HTML-parsing consequence, not a PHP/XML/language-
  loading bug.
- A second instance was found and fixed at the same time: the
  `protect_video` field description contained a literal `<video>` -
  `<video>` is a normal (non-raw-text) element so it likely didn't cause
  the same severity of breakage, but the same class of risk, so fixed
  regardless.
- **Fix**: both descriptions now name the tag/feature in quotes
  ('noscript', 'video') instead of literal angle brackets.
- **v1.12.1 was not wrong** - the backslash-escaped-quote issue it fixed
  was real and worth fixing, it just was not *this* bug. Both fixes
  stand independently.
- Extended `test_fg_crc_language_ini_lint.js` (4 new assertions, one per
  language file) to scan every language file for any literal
  HTML-tag-like sequence (`<letter...`) and fail if one is found. Verified
  to actually catch the exact original incident by temporarily
  reintroducing the precise broken string and confirming the test fails,
  then restoring the fix.
- **Lesson for all future field descriptions**: never write a literal
  `<tagname>` in a language file value, for any reason, even purely as
  descriptive prose - Joomla does not escape this text before rendering
  it as HTML. Refer to tags/elements in quotes instead.

## 1.12.1 (2026-08-29)

### Critical fix - Popup/Custom menu admin tabs vanishing entirely

- **Reported from real-world testing**: the "Popup" and "Custom menu"
  tabs disappeared completely from the plugin's configuration screen -
  only Plugin, Exclusions, Custom Shortcuts, and Accessibility remained
  visible.
- **Root cause**: `PLG_SYSTEM_FGCUSTOMRIGHTCLICK_FIELD_PROTECT_INTERACTIVE_DESC`
  used backslash-escaped double quotes (`role=\"button\"`) - the officially
  documented Joomla language-file escaping method, and one that parses
  perfectly fine with plain PHP `parse_ini_file()` (verified directly).
  However, Joomla changed how `\` and `$` are handled in language files
  around 4.4.1/5.0.1 while moving toward a "raw" language parser for
  6.0, and evidently Joomla's own runtime parser handled this line badly
  enough to corrupt parsing of the rest of the file - silently dropping
  the fieldset labels needed for two entire tabs, even though the exact
  same file parsed with 83/83 keys matching under plain PHP.
- **Fixed by eliminating the escaping entirely**: replaced `\"button\"`
  with `'button'` (single quotes need no escaping in INI values at all).
  Both language files were also swept for any other literal `\` or `$`
  character - none remained.
- Added `test_fg_crc_language_ini_lint.js` (12 assertions): every
  language file (both locales, both `.ini` and `.sys.ini`) is checked
  for backslash-escaped quotes and bare `\`/`$` characters. Verified to
  actually catch the regression by temporarily reintroducing the exact
  broken line and confirming the test fails, then restoring the fix.
- **Lesson for future edits**: this plugin's language strings must never
  need backslash-escaping. If a value needs a quoted term, use single
  quotes ('like this') instead of the officially-documented-but-apparently-
  fragile-in-practice `\"escaped\"` double-quote form.

## 1.12.0 (2026-08-29)

### New feature: optional JavaScript-disabled warning (new "Accessibility" tab)

- **Added `noscript_warning`**, off by default: shows a small banner via
  HTML's native `<noscript>` tag when a visitor has JavaScript turned
  off. Considered after comparing against a competing extension that
  offers "do nothing / warn / hide page content" as three choices for
  this scenario.
- **Deliberately implemented ONLY the warning, never the "hide content"
  option** - and none is planned. None of this plugin's protections
  apply without JavaScript in the first place (they are all JS-based), so
  a visitor without JS already sees the page completely normally; hiding
  it from them would make a real accessibility/graceful-degradation
  problem worse in exchange for zero actual protection gained, which
  contradicts this plugin's stated position throughout its changelog
  (see the "Discourage" naming already used for the devtools and
  save-page options).
- **Implementation**: a new `onAfterRender()` event handler (the plugin's
  first use of this event) reads the final rendered HTML via
  `$app->getBody()`, inserts the `<noscript>` block immediately before
  the LAST `</body>` tag (via `strripos()`/`substr_replace()`, not a
  naive `str_replace()` - a literal `</body>` string could otherwise
  appear inside page content, e.g. a code sample, and cause a
  double/misplaced insertion), and writes it back with `$app->setBody()`.
  Respects the same user-group/component/URL exclusion guards as the
  rest of the plugin. The warning message reuses the existing popup
  message sanitizer (same safe-tag allowlist), and falls back to a
  translated default when left empty.
- The "nothing to do" early-return in `onBeforeCompileHead()` now also
  accounts for `noscript_warning`, so this plugin's stylesheet (which
  styles the banner) still loads even when no other protection is
  enabled.
- Added `test_fg_crc_noscript_php.php` (12 PHP assertions): the banner is
  only inserted when the option is on, appears before `</body>` with the
  configured (or default) message and styling class, a literal
  `</body>`-like string inside page content does not cause double
  injection, original page content is always fully preserved, the
  administrator client is never touched, and a malformed body with no
  `</body>` at all doesn't crash and is left unchanged.

## 1.11.1 (2026-08-29)

### Devtools-shortcut coverage gaps found comparing against Contona's "No Right Click, No Copy"

- **Added Ctrl+Shift+K / Cmd+Opt+K** to the "Discourage developer-tools
  shortcuts" option - Firefox's Web Console shortcut, distinct from J
  (Chrome's JS console), was simply missing from both the Windows/Linux
  and macOS key lists.
- **Added Cmd+Opt+U** (Safari's own View Source shortcut on macOS) - the
  existing Ctrl+U check explicitly required `!altKey`, which correctly
  matches the Windows/Linux/Chrome/Firefox shortcut but silently excluded
  Safari's variant, which uses Option as part of the combo.
- **Confirmed (no change needed): "Prevent printing via File → Print",**
  a feature this competitor advertises, is already covered by this
  plugin's existing `@media print` CSS (since v1.6.7) - that rule applies
  to any print rendering context regardless of what triggered it
  (Ctrl+P, the browser's own Print menu item, or a page calling
  `window.print()`), not just the keyboard shortcut.
- Updated the field description to list the now-complete shortcut set.
- Added `test_fg_crc_devtools_coverage_gap.js` (10 jsdom assertions):
  both newly-added combos are blocked, and every previously-working
  combo (F12, Ctrl+U, Ctrl+Shift+I/J/C, Cmd+Opt+I/J/C, plain Ctrl+C
  unaffected) still works exactly as before.

## 1.11.0 (2026-08-29)

### Three new features, closing gaps found comparing against JoomlaX's "Right Click Disable"

All three were identified during a feature comparison against a more
mature competing extension and implemented after explicit confirmation.

**1. Exclude components/URL paths** (new "Exclusions" tab)
- `exclude_components`: one component name per line (e.g. `com_contact`).
  The plugin does nothing at all - not even print/devtools blocking - on
  pages rendered by a listed component.
- `exclude_urls`: one pattern per line, matched as a case-insensitive
  substring against the current page's full URL.
- Both are checked as new guard clauses immediately after the existing
  client/document-type/user-group checks, before any parameter reading
  or asset registration happens.

**2. Admin-defined custom keyboard shortcuts** (new "Custom Shortcuts" tab)
- A repeatable subform: pick modifiers (Ctrl/Cmd, Shift, Alt) and a key
  (a single character or a named key like `Escape`/`F1`/`ArrowLeft`).
  Modifier matching is exact - a shortcut configured as plain Ctrl+K does
  not also match Ctrl+Shift+K. Ctrl and Cmd (metaKey) are treated as the
  same modifier for cross-platform Mac/Windows support, consistent with
  every other keyboard handler in this plugin. No
  `stopImmediatePropagation()`, for the same reason as the existing
  devtools/print/save handlers.

**3. Additional CSS "exception selectors"** (extends "Skip on interactive
elements")
- One CSS selector per line, added to the built-in interactive-elements
  exemption list. This is the general escape hatch for the Shadow DOM
  limitation already documented on that option: a custom element/web
  component this plugin doesn't recognise by default can now be exempted
  by an admin who knows their own site's markup, without waiting for a
  plugin update.
- Selectors are validated once at script startup (tested against an
  inert, detached `DocumentFragment` - cheap, touches no real DOM) rather
  than on every event; an invalid selector is dropped with a console
  warning naming it, instead of ever risking a thrown `SyntaxError`
  inside a live event handler. A single invalid entry in the list does
  not affect the other, valid entries.
- Deliberately kept case-sensitive (unlike the component/URL exclusion
  lists, which are lower-cased): CSS class/ID selectors are
  case-sensitive, since HTML `class`/`id` attribute values themselves
  are case-sensitive even though CSS tag-name selectors aren't.

Added `test_fg_crc_exclusions_php.php` (11 PHP assertions),
`test_fg_crc_custom_shortcuts.js` (15 assertions) +
`test_fg_crc_custom_shortcuts_php.php` (12 PHP assertions), and
`test_fg_crc_extra_exempt_selectors.js` (9 assertions, including the
invalid-selector-is-dropped-safely case) - 47 new assertions across five
files, on top of the existing 25-suite regression sweep, all passing with
no regressions.

## 1.10.1 (2026-08-29)

### Honesty fix - "Disable saving the page" relabeled

- **Reworded the v1.10.0 "Disable saving the page" option** (label and
  description only - the underlying `disable_save` setting key is
  unchanged, so existing configurations keep whatever they had). Raised
  directly by the plugin's own maintainer testing the feature: it only
  blocks the Ctrl/Cmd+S keyboard shortcut, but the browser's own menu
  (e.g. the three-dot menu -> "Save page as...") offers the identical
  action and cannot be blocked from a web page at all - clicking a
  browser's own chrome/menu never fires any event a page's JavaScript
  can see or prevent, the same fundamental limitation the devtools
  option already documents.
- Renamed "Disable saving the page" to "Discourage saving the page" and
  the description now states this limitation explicitly, matching the
  wording already used for "Discourage developer-tools shortcuts" since
  v1.6.3 - both are minor deterrents against the keyboard shortcut only,
  not a way to actually prevent the browser's built-in save/inspect
  functionality, which no page-level code can reach.

## 1.10.0 (2026-08-29)

### New feature: "Disable saving the page" (Ctrl/Cmd+S)

- Added a new option, `disable_save`, that blocks the browser's "Save
  Page As" shortcut (Ctrl+S / Cmd+S). Off by default.
- Implemented consistently with the existing Ctrl+P print-blocking
  handler: `preventDefault()` alone is enough to stop the browser's own
  save dialog, and deliberately no `stopImmediatePropagation()` - so an
  unrelated site feature bound to the same key combo (e.g. an in-page
  editor with its own save shortcut) still receives the event, matching
  the v1.6.3 fix applied to the devtools/print handlers.
- This was added after comparing the plugin against a competing Joomla
  module ("Ol No Right Click") that offers the same shortcut - the one
  feature it had that this plugin didn't.
- Added `test_fg_crc_disable_save.js` (8 jsdom assertions): Ctrl+S and
  Cmd+S are both blocked when enabled, the option is off by default and
  does nothing when left off, a plain "s" keypress is never affected,
  the no-`stopImmediatePropagation()` behaviour is verified the same way
  as the earlier devtools test, and the option alone (with the right-click
  mode set to "No" and nothing else enabled) is enough to activate the
  script rather than being silently skipped.

## 1.9.2 (2026-08-28)

### CSS specificity hardening for "Disable text selection"

- **Raised the specificity of the `user-select` blocking/exemption
  selectors** by repeating the `.crc-noselect`/
  `.crc-noselect-interactive-exempt` class token 4 times in each
  selector (e.g. `.crc-noselect.crc-noselect.crc-noselect.crc-noselect
  body *`) - a standard, browser-supported technique. This still matches
  the exact same single class JS adds to `<html>`, it just makes the
  selector count for more classes in the specificity calculation, so it
  now reliably wins against a realistic template override using one or
  two classes, even one also marked `!important` (both sides being
  `!important` means specificity is what decides the winner).
- **Documented the ceiling this cannot get past**, in the CSS comments
  and in a new README "Known limitations" section: CSS specificity
  always ranks ID selectors above any number of class selectors,
  regardless of `!important` - so a template rule like
  `body #main-content p { user-select: text !important; }` will still
  win no matter how many times a class-only selector repeats. No
  selector this plugin could ship is guaranteed to beat an arbitrary,
  unknown competing stylesheet; a template that specifically overrides
  this needs its own matching-or-higher-specificity fix, not a plugin
  update.
- Added `test_fg_crc_css_specificity.js` (10 assertions with a small
  purpose-built specificity calculator): confirms the 4x repetition is
  present on both selector groups, computes that the boosted selector
  now outranks a realistic single/double-class template override, and -
  just as importantly - confirms an ID-based override still wins, proving
  that documented ceiling is real rather than an oversight.

## 1.9.1 (2026-08-28)

### Third clipboard fallback tier - future-proofed against execCommand going away

- **Added a third and final fallback tier for "Copy URL"/"Share"**, on
  top of the existing Clipboard API -> `execCommand('copy')` chain:
  when neither is available (a future browser having dropped
  `execCommand` entirely - it is formally deprecated today - or a
  Permissions-Policy blocking scripted clipboard access), the link is
  now shown in a small, pre-selected read-only field so the visitor can
  copy it with their own Ctrl+C/Cmd+C. Native keyboard copy doesn't
  depend on any scripted clipboard API at all, so this tier can't be
  broken the same way the other two theoretically could be in the
  future.
  - `execCommand` availability is now feature-detected
    (`typeof document.execCommand === 'function'`) rather than only
    try/catch-ed, so a browser that has removed it entirely is treated
    the same as one that never had it, instead of relying on a thrown
    error.
  - The fallback box stays open (unlike the transient toast) until
    dismissed - via its close button, clicking outside, or Escape -
    since the visitor needs a moment to actually press the key. Reuses
    the same focus-capture/restore helpers as the popup and menu, so
    focus returns to whatever had it beforehand once the box closes.
  - Today's common case is unaffected: on any browser where the
    Clipboard API or `execCommand` succeeds (effectively all current
    browsers), the one-click copy-with-toast experience from v1.7.2/
    v1.9.0 is unchanged - the manual box is a fallback of last resort,
    not a replacement for the one-click flow.
- Added `test_fg_crc_manual_copy_fallback.js` (11 jsdom assertions):
  the input is focused and its full text pre-selected on open, the close
  button/outside-click/Escape all dismiss it (clicking inside it does
  not), focus returns correctly afterwards, and the localized message
  and URL both render correctly. Updated `test_fg_crc_copy_feedback.js`'s
  no-Clipboard-API case to match the new tier-3 behaviour (jsdom has
  neither the Clipboard API nor `execCommand`, so it now correctly
  exercises the manual fallback box instead of the old "copy failed"
  toast).

## 1.9.0 (2026-08-28)

### Touch/mobile support - long-press now reliably opens the popup/custom menu

- **Added `touchstart`/`touchmove`/`touchend`-based long-press detection
  (500ms, 10px movement tolerance) as a second trigger path alongside the
  desktop `contextmenu` event**, both feeding into the same shared
  decision logic (`handleContextTrigger()`, extracted from the previous
  single `contextmenu` handler). Needed because relying on `contextmenu`
  alone is unreliable on touch devices: iOS Safari largely does not fire
  a `contextmenu` event on long-press at all (a long-standing WebKit
  limitation, separate from `-webkit-touch-callout`, which only
  suppresses the native "Save Image" sheet and does nothing to open the
  plugin's own popup/menu); Android Chrome fires it more consistently,
  but not every mobile browser does.
- A quick tap, a scroll/swipe (movement past the 10px tolerance), or a
  multi-touch gesture (pinch-to-zoom) all correctly cancel the timer and
  never trigger the popup/menu - only a stationary single-finger touch
  held for the full 500ms counts as a long-press.
- Guards against double-handling: if a native `contextmenu` still fires
  shortly after a long-press this code already handled (observed on some
  Android browsers), it's suppressed rather than triggering the
  popup/menu a second time.
- All existing rules apply identically on the touch path: the plugin's
  own UI is never mistaken for site content (see the v1.8.2 fix),
  "Skip on interactive elements" is respected, "Only for images" mode
  still only blocks rather than opening a menu, and mode 3 with no
  usable menu items still falls back to letting the native behaviour
  through instead of leaving touch input dead.
- Note on `preventDefault()`: touch listeners are registered
  `{ passive: true }` (required for scroll performance, and correct
  since we only need to *detect* the gesture, not block scrolling
  itself), and by the time the 500ms timer fires it is too late for a
  delayed `preventDefault()` call to retroactively change the browser's
  already-committed scroll/selection decision anyway. Suppressing the
  native callout/selection on touch continues to be handled the way it
  already was - synchronously via CSS (`-webkit-touch-callout`,
  `user-select`) - this addition is purely about reliably showing our
  *own* UI, not about additionally suppressing native touch behaviour.
- Added `test_fg_crc_touch_longpress.js` (11 jsdom assertions): a held
  long-press opens the menu, a quick tap does not, movement past/within
  tolerance behaves correctly, multi-touch is ignored, the
  double-handling guard against a follow-up native `contextmenu` works,
  mode 2 and the plugin's-own-UI guard both still apply correctly on the
  touch path.

## 1.8.2 (2026-08-28)

### Critical bug fix - right-click on the plugin's own menu leaked the native menu

- **Reported from real-world testing**: with "Custom menu" mode active,
  right-clicking a second time - this time ON the already-open custom
  menu itself (one of its items), or on the popup's close button in mode
  1 - showed the native browser context menu instead of being blocked.
  Exactly the behaviour the plugin exists to prevent, happening on the
  plugin's own UI.
- **Root cause**: v1.5.0's "Skip on interactive elements" feature
  (`protectInteractive`, on by default) exempts `<button>` elements from
  right-click blocking so a site's own buttons keep working normally.
  The custom menu's own items - and the popup's close button - are also
  implemented as `<button>` elements. Since the exemption check has no
  way to tell "the site's own button" apart from "the plugin's own
  rendered UI, which happens to use a button internally", right-clicking
  the plugin's own menu/close button was incorrectly treated as
  interactive site content and let through.
- **Fix**: added a guard, checked before the interactive-elements
  exemption, that recognises the plugin's own UI (`.crc-overlay` -
  covers the popup and its backdrop, `.crc-menu`, `.crc-toast`) and
  always blocks the native menu there regardless of what element was
  clicked, never deferring to `isInteractiveExempt()` for it.
- Added `test_fg_crc_own_ui_rightclick.js` (7 jsdom assertions)
  reproducing the exact report: a second right-click on a custom-menu
  item, on the popup's close button, and on the popup backdrop are all
  now correctly blocked, while a sanity check confirms a real site
  button (outside the plugin's own UI) remains correctly exempted as
  before - this was verified to actually catch the regression by
  temporarily reverting the fix and confirming the test suite fails.

## 1.8.1 (2026-08-28)

### Packaging/repo fixes

- **The install ZIP was missing LICENSE.** GPL distribution expects a
  copy of the license text to accompany the software; it existed in the
  repo but the build command never included it in the ZIP. Fixed.
- **All 17 test suites referenced by name throughout this changelog since
  v1.4.0 existed only in the development sandbox, never in the git repo**
  - meaning the specific tests this changelog cites as evidence for each
    fix were never actually verifiable by anyone cloning the repository,
    and there was no regression safety net for future changes at all
    once a given work session ended. Moved into a new `tests/` directory
    in the repo (included in the repo archive, deliberately excluded from
    the Joomla install ZIP - these are development tooling, not something
    that should ship to end users). All hard-coded absolute sandbox paths
    were converted to paths relative to `tests/` (`__dirname`/`__DIR__`
    based) so they run correctly regardless of where the repo is cloned -
    every test was re-run from its new location to confirm this.
    Added `tests/README.md` (what each file covers, requirements, a note
    on why the PHP stubs are kept deliberately in sync with Joomla's real
    API rather than the plugin's assumptions - see the v1.7.0 incident)
    and `tests/run.sh`, a one-command runner for the full suite.
- **Investigated `media/joomla.asset.json`** (the newer declarative
  Web Asset Manager registration format) as a possible replacement for
  the current `registerAndUseStyle()`/`registerAndUseScript()` calls.
  Per Joomla's own documentation, automatic `joomla.asset.json` discovery
  is only implemented for `media/vendor`, `media/system`, `media/legacy`,
  and the currently-dispatched component's `media/{com_x}` folder -
  modules are explicitly documented as NOT auto-discovered, and plugins
  aren't mentioned in the discovery list either, implying the same
  applies to them. Using it would require an additional explicit
  `$wr->addRegistryFile()` call, and running both registration paths
  side by side risks a duplicate-asset-name conflict with the
  already-working explicit registration, for no functional benefit given
  this plugin's simple single-script/single-style asset footprint.
  Not changed; documented here so the reasoning isn't lost if revisited.
- **Confirmed `updates.xml`'s `<client>site</client>` is already
  correct** for a plugin (verified directly against Joomla's official
  update-server documentation, which states plugins are always installed
  with a client of site/client_id=0, and that update XML must explicitly
  say so or Joomla defaults to administrator and never matches). No
  change needed.
- The CSS/JS bundle still loads whenever any protection is active, even
  if only "Discourage developer-tools shortcuts" (which needs no CSS at
  all) is on with the right-click mode set to "No". Left as-is: the
  stylesheet is roughly 9KB uncompressed (a few hundred bytes gzipped),
  and splitting it into multiple conditionally-loaded files to shave that
  off in an already-narrow edge case was judged not worth the added
  complexity and maintenance burden.

## 1.8.0 (2026-08-28)

### Theming/accessibility fixes + admin UX cleanup

**Dark mode now follows the site's own template theme, not just the OS:**
- The popup/menu previously used `@media (prefers-color-scheme: dark)`
  unconditionally, so a light-themed site rendered a mismatched dark
  popup/menu whenever the visitor's OS happened to be set to dark (and a
  dark-themed site got a mismatched light popup for a light-OS visitor).
  Now checks, in priority order: Bootstrap 5's `data-bs-theme` (used by
  Joomla 5/6's default Cassiopeia template and most Bootstrap-5-based
  third-party templates), then `data-color-scheme` (an alternate
  convention some templates use), and only falls back to
  `prefers-color-scheme` when the page declares neither attribute.
  Implemented via CSS custom properties so the light/dark values live in
  exactly one place each.

**Accessibility:**
- Added a `prefers-reduced-motion: reduce` override that disables every
  transition this plugin adds (popup fade/slide, menu scale, toast
  fade), for visitors who have that OS-level accessibility setting on.

**RTL support:**
- Replaced hard-coded physical CSS properties with logical ones so the
  popup/menu render correctly in right-to-left languages: `text-align:
  left` → `text-align: start`, the close button's `right` position →
  `inset-inline-end`, and the title/body's `margin-right` spacing →
  `margin-inline-end`. The custom menu's pop-out animation now anchors
  from the correct corner via `:dir(rtl)`. (Positioning driven by JS from
  click/touch coordinates - the menu and toast's `left`/`top` - is
  unaffected, since mouse/touch coordinates are never mirrored for RTL.)

**Admin UX:**
- The "Popup" and "Custom menu" fieldsets' fields are now hidden unless
  the matching right-click mode is actually selected
  (`showon="rightclick_mode:1"` / `:3"`, combined with the existing
  `popup_enabled` condition via `[AND]` where applicable), instead of
  always being visible regardless of the selected mode. Fieldset-level
  `showon` (hiding the whole tab) was considered but not used - its
  Joomla-core support could not be confirmed with the same confidence as
  field-level `showon`, which is thoroughly documented back to Joomla
  3.2.4; the field-level approach achieves the same practical outcome
  (irrelevant settings hidden) without depending on that.
- "Apply to user groups" now uses
  `layout="joomla.form.field.list-fancy-select"` (Joomla's improved
  checkbox-tree-with-search widget for multi-select fields) instead of a
  plain HTML multi-select box, confirmed against Joomla's own
  documentation and a core pull request that made this exact change to
  the same field type.
- Removed the hard-coded `1.3.0` left over in all four language file
  header comments since the FG rebrand (should have been updated on every
  release since - this is why it's better not to duplicate the version
  number in more than one place: the header comment no longer states a
  version at all, so it can't go stale again).
- Added `test_fg_crc_css_theming.js` (21 structural assertions checking
  the stylesheet's content directly): confirms the explicit-theme
  selectors and the prefers-color-scheme fallback scoping, that every
  animated element is covered by the reduced-motion override, that no
  hard-coded `text-align: left` remains, and that the theming custom
  properties are both defined and actually used by the components that
  need to change colour for dark mode.

## 1.7.2 (2026-08-28)

### Print/action conflict warning + clipboard fix on HTTP

- **Documented the "Disable printing" + "Print the page" menu-action
  interaction.** Both features work correctly together technically - the
  menu item still opens the browser's print dialog, and the print CSS
  still shows the "printing is disabled" message on the printed output -
  but an admin combining both without realising it would get a "Print"
  button that visibly does nothing useful, with no explanation why. Added
  an explicit note to the action field's description rather than silently
  dropping the menu item (which would have been its own kind of
  confusing: a configured item quietly missing from the menu, with no
  indication why).
- **Fixed "Copy URL" and "Share" (clipboard fallback) failing completely
  silently on plain HTTP sites.** `navigator.clipboard` requires a secure
  context (HTTPS or localhost) and is simply `undefined` otherwise - the
  previous code checked for it and did nothing at all if absent, so
  clicking the menu item on an HTTP site appeared to do nothing, with no
  error and no way to tell whether it worked.
  - Added a `document.execCommand('copy')`-based fallback (deprecated but
    still supported by every major browser, with no secure-context
    requirement) for when the modern Clipboard API isn't available.
  - Added a small toast/status message ("Copied to clipboard" /
    "Could not copy to clipboard", both translated via `Text::_()`) shown
    after every copy attempt, success or failure, so the action always
    gives visible feedback instead of silently doing nothing either way.
- Added `test_fg_crc_copy_feedback.js` (9 jsdom assertions): the modern
  Clipboard API path still works and shows the success toast, the
  no-Clipboard-API path attempts the fallback and always shows a result
  toast (jsdom doesn't implement `execCommand` at all, so this exercises
  and confirms the failure-feedback path specifically), and the English
  fallback when a localized message is missing.

## 1.7.1 (2026-08-28)

### "Skip on interactive elements" was too narrow

- **Expanded the interactive-elements exemption** (`protectInteractive`,
  default on) beyond `input, textarea, select, button, a,
  [contenteditable]`. It now also covers: `iframe` (embedded YouTube/Maps/
  cookie-consent widgets - though a right-click *inside* such an embed's
  own content was never actually intercepted in the first place, since
  contextmenu events never cross a frame boundary, same-origin or not;
  this specifically fixes a right-click landing on the iframe's own
  border/padding area in the parent document), `canvas` (canvas-rendered
  map libraries such as Leaflet, OpenLayers, and MapLibre GL, some of
  which use right-click-drag for panning/rotation), `audio` (native
  player controls), `summary`/`details` (natively interactive disclosure
  widgets), `label`, and `[role="button" i]` (common in component
  libraries that style a `div`/`span` as a button rather than using a
  native `<button>`; matched case-insensitively).
- **`video` is exempt by the same reasoning, but only while "Also protect
  video" is off.** If that option is explicitly enabled, video keeps
  being treated as protected content (blocked), matching the admin's
  clear intent - it does not silently become exempt again just because
  `protectInteractive` is on.
- **Fixed a related CSS gap**: the JS-side exemption already recognised
  both `[contenteditable="true"]` and `[contenteditable=""]` (per the
  HTML spec, an empty value means the same "true" state), but the CSS
  rule that keeps text selectable inside those elements only listed
  `[contenteditable="true"]`. `[contenteditable=""]` elements could
  therefore still have visually-blocked text selection even though the
  copy/right-click JS logic already correctly allowed them - fixed by
  adding the missing selector.
- Known, documented limitation: this is a plain CSS selector, so it
  cannot see inside a web component's Shadow DOM. A right-click event
  originating inside a custom element's shadow tree is retargeted by the
  browser to the custom element host before this code ever runs, so an
  arbitrary custom element (e.g. `<my-map-widget>`) is only exempted if
  the host element itself matches one of the selectors above (most
  commonly by exposing `role="button"`) - there is no general way to
  detect "this custom element wraps something interactive" from outside
  it. This is now documented in the field description and in the code
  comments rather than silently unhandled.
- Added `test_fg_crc_interactive_exempt_expanded.js` (15 jsdom
  assertions): every newly-added element type, the conditional video
  behaviour in both directions, `protectInteractive=false` still disabling
  all of it, and that the copy/selection-blocking path picks up the same
  expanded list automatically (since it's built on the same helper).

## 1.7.0 (2026-08-28) - CRITICAL HOTFIX

**If you are running 1.6.6 or 1.6.7, upgrade immediately.** Both versions
have a fatal error in `sanitizePopupMessage()` that crashes every single
page load when the "Disable right click" mode is set to "Yes" (mode 1),
regardless of whether a popup message is actually configured - the method
still runs and throws before the page can render. This is a site-breaking
bug, reported directly from a production installation.

### Root cause and fix

- **`Joomla\Filter\InputFilter::getInstance()` does not exist.** That
  static factory method only exists on the separate, CMS-specific
  `Joomla\CMS\Filter\InputFilter` subclass - the plain framework class
  (`Joomla\Filter\InputFilter`, which is what was imported and used) only
  has a public constructor. Calling the nonexistent static method threw
  `Error: Call to undefined method Joomla\Filter\InputFilter::getInstance()`
  on every request that reached it, which - because this runs inside
  `onBeforeCompileHead`, before the page body is built - took the entire
  page down with a fatal error, not just the popup feature.
  Fixed by instantiating the class directly with `new InputFilter(...)`
  instead, which is available and has an identical signature on both the
  framework and the CMS subclass.
- **A second, more serious bug was found while fixing the first one**: the
  broken code passed `1, 1` for the `tagsMethod`/`attrMethod` constructor
  arguments, intending "whitelist mode" (allow only the listed tags/
  attributes). Per Joomla's own API documentation, `1` is actually
  `ONLY_BLOCK_DEFINED_TAGS`/`ONLY_BLOCK_DEFINED_ATTRIBUTES` - **blacklist**
  mode - the exact opposite of the intended behaviour. Had the crash not
  happened, this would have silently let `<script>`, `<iframe>`, and
  everything else *through* the "safe" tag list is meant to block, while
  stripping the tags that were actually meant to be allowed. Fixed by
  using the named constants (`InputFilter::ONLY_ALLOW_DEFINED_TAGS`,
  `InputFilter::ONLY_ALLOW_DEFINED_ATTRIBUTES`, both value `0`) instead of
  magic numbers, specifically to make this mistake structurally harder to
  repeat.
- **Root cause of both bugs**: the PHP-side unit test written for this
  method in 1.6.6 used a hand-written stub of `Joomla\Filter\InputFilter`
  that itself incorrectly modelled a `getInstance()` method (matching the
  broken assumption, not the real class), so it gave false confidence -
  the test passed while the real code was fatally broken. The stub has
  been corrected to match Joomla's actual public API exactly (verified
  directly against `api.joomla.org`'s framework documentation for this
  release), and now captures the constructor's `tagsMethod`/`attrMethod`
  arguments so the test asserts the *correct constant* is used, not just
  that the call succeeds. Deliberately reintroducing both original bugs
  during development of this fix confirmed the corrected test suite now
  catches them.
- No other file changed in this release - this is a pure hotfix to
  `Fgcustomrightclick.php` and its test suite.

## 1.6.7 (2026-08-28)

### Localization/CSP fix + iOS long-press fix

- **The print-disabled message ("Printing is disabled on this website.")
  was hard-coded in English**, unlike the ARIA strings which already go
  through `Text::_()`. Worse, it was delivered via a dynamically-injected
  `<style>` tag - on a site running a Content-Security-Policy without
  `'unsafe-inline'` in `style-src` (e.g. via a Joomla CSP plugin), that
  inline style is silently dropped by the browser and the print-block
  simply doesn't apply. Fixed both problems together: the CSS moved into
  the plugin's own external stylesheet (already loaded via
  `<link rel="stylesheet">`, which ordinary CSP configurations don't
  restrict the way inline `<style>` is), gated behind a class JS toggles
  on `<html>`. The message text itself is translated server-side via
  `Text::_()` and delivered as a `data-crc-print-message` attribute on
  `<body>`, read in CSS with `attr()` - so no localized string needs to
  live in CSS at all, and there is no inline style content of any kind
  left for this feature.
- **Added `-webkit-touch-callout: none` for images (and video, when
  "Also protect video" is on).** `contextmenu`/`dragstart` prevention has
  no effect on iOS Safari's (and other WebKit-on-iOS browsers') native
  long-press "Save Image"/"Save Video" action sheet - that callout isn't
  routed through either DOM event on iOS. Without this, the "Only for
  images" mode in particular did almost nothing on an iPhone/iPad. Applied
  via CSS classes (`crc-touch-callout-off`, `crc-touch-callout-off-video`)
  toggled whenever right-click protection or "Disable image dragging" is
  active, rather than per-element inline styles.
- As before, this remains a deterrent, not DRM - a long-press screenshot
  or any other capture method is unaffected and always will be.
- Added `test_fg_crc_print_and_touch.js` (12 jsdom assertions): confirms
  no `<style>` element is ever dynamically created, the localized message
  is correctly delivered via the data attribute with an English fallback,
  Ctrl+P blocking still works, and both touch-callout classes are added
  under exactly the right conditions.

## 1.6.6 (2026-08-28)

### Popup HTML sanitizer fixes (server-side hardening + two consistency bugs)

- **Added server-side sanitization of the popup message, before it ever
  reaches `addScriptOptions()`.** Previously the field used `filter="raw"`
  in the manifest and the ONLY sanitization was client-side JS
  (`sanitizeHtml()`), so the raw admin-authored HTML was always present
  in the page's script-options payload regardless. A new
  `sanitizePopupMessage()` method runs `Joomla\Filter\InputFilter` with
  the same tag/attribute allowlist as the JS side, as a second,
  independent layer - defense-in-depth against a compromised admin
  account, matching the reasoning already applied elsewhere in this
  plugin (e.g. the link-scheme whitelist). The manifest field keeps
  `filter="raw"` rather than switching to `filter="safehtml"`: Joomla's
  built-in "safehtml" filter uses its own broader, fixed tag list that we
  don't control, which would drift from the exact allowlist used here and
  in the JS sanitizer. Calling `InputFilter` ourselves keeps both layers
  using the identical set.
- **Fixed an inconsistency: relative/anchor/query `<a href>` values in the
  popup message were being stripped**, while the exact same kind of value
  in a custom-menu link item was correctly allowed. The popup sanitizer's
  own `SANITIZE_SAFE_URL` regex required an explicit `http(s)/mailto/tel`
  prefix on every href; a value like `/kontakt` or `#sekcia` has no
  scheme at all and so failed that check and lost its href. Fixed by
  reusing the exact same `isSafeLinkValue()` function already used for
  custom-menu links in the JS sanitizer (and its PHP counterpart in the
  new server-side pass above), which correctly treats schemeless values
  as safe.
- **Fixed content loss on disallowed tags**: `<h2>Ahoj</h2>` previously
  vanished entirely (tag *and* text), since the sanitizer called
  `node.remove()` on anything not in the tag allowlist. It now unwraps
  disallowed-but-harmless tags instead - keeping their text/child content
  and dropping only the wrapping tag - while a small set of genuinely
  dangerous tags (`script`, `style`, `iframe`, `object`, `embed`,
  `noscript`, `template`, `svg`) are still removed entirely, content
  included, exactly as before. This required rewriting the sanitizer's
  tree walk from a snapshot-based loop to one that resumes scanning from
  any newly-promoted children after an unwrap, so nested cases (a
  disallowed tag inside another disallowed tag, itself containing a
  dangerous tag) are still fully sanitised in one pass rather than being
  silently skipped.
- On the specific claim that `javascript:` hrefs could bypass the popup
  sanitizer via the same control-character trick used against the
  menu-link whitelist: on inspection this particular check was an
  *allow-list* match (`must start with http(s)/mailto/tel`), not a
  deny-list one, so the described bypass direction did not apply here.
  The real, fixed issue was the duplicated/divergent logic itself
  (previous two bullets) - unifying both paths onto `isSafeLinkValue()`
  closes that gap regardless and keeps any future scheme-check hardening
  automatically shared between both.
- Added `test_fg_crc_popup_sanitize_php.php` (9 PHP-side assertions,
  reflection-based with a pass-through `InputFilter` stub targeting only
  the new href re-validation logic) and
  `test_fg_crc_sanitizer_consistency.js` (17 jsdom assertions covering
  relative-href preservation, unwrap-keeps-text, dangerous tags still
  fully removed, and a deeply-nested unwrap-then-remove case).

## 1.6.5 (2026-08-28)

### Bug fix - mode 3 ("Custom menu") with no usable items

- **Fixed a dead-end: with the right-click mode set to "Custom menu" but
  no usable menu items, visitors lost the context menu entirely** - not
  the native one (blocked by `preventDefault()`), and not the custom one
  (nothing to show, since `showMenu()` just logged a console warning and
  returned). This could happen either because no items were ever
  configured, or - more subtly - because every configured item got
  filtered out at runtime by the security whitelist (e.g. all links used
  a disallowed URL scheme), which an admin could trigger without any
  visible warning in the plugin's own configuration screen.
- Fixed by checking whether there is anything to show *before* calling
  `preventDefault()`: with zero usable menu items, the handler now leaves
  the event alone entirely and the visitor gets the normal browser
  context menu instead of no menu at all. This covers both causes above
  in one fix, including the "items were filtered out after saving" case
  that admin-side "require at least one item" validation on its own would
  not have caught.
- The mode field's description now documents this fallback behaviour.
- Added `test_fg_crc_empty_menu_fallback.js` (5 jsdom assertions):
  no items configured, every item filtered out by the safety whitelist,
  and the normal (non-empty) case is unaffected.

## 1.6.4 (2026-08-28)

### Bug fix - {url} placeholder in custom-menu link items

- **A link item whose value was nothing but `{url}` (e.g. a "link to this
  page" menu entry) was broken.** The placeholder was always
  percent-encoded before substitution, which is correct when `{url}` sits
  inside a URL component (e.g. `?text={url}`) but wrong when it *is* the
  whole destination: the result (`https%3A%2F%2Fexample.com%2F...`) has no
  `://`, so the browser treated it as a broken relative path instead of
  navigating to the page.
- Fixed by special-casing the whole-value case: when the (trimmed) value
  is exactly `{url}`, the raw, unencoded page URL is used directly.
  Everywhere else - the common case of `{url}` embedded inside a link,
  e.g. a share-intent query string - it is still percent-encoded exactly
  as before, so existing configurations using that pattern are unaffected.
- This is a targeted fix for the reported case rather than a general
  templating engine: `{url}` combined with extra text that is *not* a
  query string (e.g. `{url}#section`) still falls back to the
  percent-encoded substitution, which is documented as a known limitation
  in both the field description and the new test suite below, rather than
  silently mishandled.
- Added `test_fg_crc_url_placeholder.js` (5 jsdom assertions) covering the
  whole-value case (with and without surrounding whitespace), the
  unaffected query-string case, and the documented partial-value
  limitation.

## 1.6.3 (2026-08-28)

### Honesty & UX fix - developer-tools deterrent

- **Renamed and re-explained the "Block developer tools keys" option**
  (now "Discourage developer-tools shortcuts") to stop implying it's a
  security measure. It never protected source code or page content - it
  only removes a handful of keyboard shortcuts. The description now says
  so explicitly and lists what it does *not* stop: the browser's own
  menu, extensions, external tools, disabling JavaScript, cached copies,
  a plain HTTP client, or a screenshot/OCR. The underlying setting key
  (`block_devtools`) is unchanged, so existing installs keep whatever
  they had configured across the upgrade - only the label and description
  text changed.
- **Removed `stopImmediatePropagation()` from both the devtools-shortcut
  handler and the Ctrl/Cmd+P print-blocking handler** (same issue, same
  fix, applied consistently to both). `preventDefault()` alone is
  sufficient to stop the browser's own devtools/print action;
  `stopImmediatePropagation()` additionally prevented *any other*
  listener on the page - a third-party widget, another script - from ever
  seeing that keydown at all, which is a much bigger side effect than
  this feature's modest goal justifies.
- Added `test_fg_crc_devtools_propagation.js` (5 jsdom assertions):
  confirms no `.stopImmediatePropagation(` call remains anywhere in the
  shipped script, that an unrelated listener bound to the exact same key
  combo (e.g. Ctrl+Shift+C) now still receives the event, and that F12/
  Ctrl+U/Ctrl+P are still blocked as before with no functional regression.

## 1.6.2 (2026-08-28)

### Accessibility & localization fix

- **Popup dialog now has complete ARIA bindings.** `role="dialog"` and
  `aria-modal="true"` were already present, but the accessible name/
  description were missing: `aria-labelledby` and `aria-describedby` are
  now set on the overlay, pointing at real `id`s on the title and body
  elements. When there is no title (it's an optional field),
  `aria-labelledby` is simply omitted rather than pointing at a
  nonexistent element - a screen reader still gets the description via
  `aria-describedby` either way.
- **The popup's close button and the custom menu's `aria-label` were
  hard-coded in English** (`"Close"` / `"Context menu"`) regardless of
  the site's language. Both are now translated via the plugin's own
  language file (`Text::_()`), loaded explicitly with `$this->loadLanguage()`
  only when the popup or custom menu mode is actually active - consistent
  with `autoloadLanguage = false` and the reasoning already documented on
  that property. Both strings fall back to their English default if a
  script-options payload somehow lacks them (e.g. a stale cache from
  before this upgrade), so nothing breaks visually either way.
- Added `test_fg_crc_aria.js` (12 jsdom assertions): the `aria-labelledby`/
  `aria-describedby` ids resolve to the real elements in the DOM (not just
  string-matched), the no-title case doesn't fabricate a label, and both
  localized strings use the configured value with a verified English
  fallback when absent.

## 1.6.1 (2026-08-28)

### CSS robustness fix

- Added `!important` to the `disableSelect` CSS rules (both the blocking
  rule and the `input`/`textarea`/`select`/`[contenteditable]`/link/button
  exemptions). Without it, the exemption relied entirely on the two rules
  having equal CSS specificity (`.crc-noselect body *` and e.g.
  `.crc-noselect input` are both `(0,1,1)`) and this plugin's stylesheet
  happening to be the *last* one loaded with that specificity for the
  affected elements. A template's own CSS setting `user-select` on form
  fields with the same or higher specificity, loaded after this
  stylesheet in the page's `<head>`, could silently win and leave inputs
  unselectable even though the JS-level copy/cut/select event handling
  (`isProtectionExempt()`) already correctly allowed it. `!important` on
  both sides removes that dependency on load order.
- JS-level behaviour is unchanged - `isProtectionExempt()` already
  correctly exempted these elements from the `copy`/`cut`/`selectstart`
  event handlers and the Ctrl+C/X/A shortcut block; this fix closes the
  matching gap on the purely visual/native-selection side.

## 1.6.0 (2026-08-28)

### Scope fix (behaviour change) - "Only for images" mode

- **"Only for images" now means exactly `<img>`/`<picture>`.** It
  previously also matched `svg`, `canvas`, and `video`, plus any element
  with a CSS `background-image` found by walking up to 4 ancestor
  elements. That caused two kinds of surprise: (1) `canvas` was included
  even though no browser offers "Save image as" on a canvas via
  right-click in the first place, so blocking it protected nothing;
  inline `<svg>` icons inside otherwise-interactive buttons were also
  frequently caught, and (2) the ancestor walk meant right-clicking
  *anywhere* inside a card, banner, or button that merely had a
  background image further up the DOM - including unrelated nested text,
  links, or other buttons - could get blocked.
- `svg` and `canvas` are dropped from image detection entirely (not
  reintroduced even as an opt-in), for the reasons above.
- Added two new options, both **off by default**, only shown when the
  right-click mode is "Only for images":
  - **"Also protect video"** - extends the protection (and, if
    "Disable image dragging" is on, drag prevention too) to `<video>`
    elements.
  - **"Also protect CSS background images"** - extends the protection to
    elements whose *own* computed style has a background image. Unlike
    the old behaviour, only the exact element that was right-clicked is
    checked - never its ancestors - so a button or link nested inside a
    background-image banner/card is never affected, only the
    banner/card element itself when clicked directly.
- `disableImageDrag` follows the same narrowed scope: it always covers
  `<img>`/`<picture>`, and now also covers `<video>` when "Also protect
  video" is enabled. Background-image elements are not natively
  draggable by browsers in the first place, so no change was needed
  there.
- Added `test_fg_crc_image_scope.js` (13 jsdom assertions) covering the
  narrowed default scope, both new opt-in toggles independently, that a
  background-image container's own nested button is no longer
  collaterally blocked, and that drag-prevention follows the same rules.

## 1.5.0 (2026-08-28)

### UX/usability fix (behaviour change)

- **Right-click blocking (mode "Yes") and the custom menu (mode "Custom
  menu") no longer apply, by default, to links, form fields, buttons, or
  editable content.** Previously every mode-1/mode-3 protection called
  `e.preventDefault()` unconditionally, which also broke the extremely
  common "right-click a link -> open in new tab" browser gesture, could
  interfere with pasting into search/contact-form fields, and disrupted
  third-party editors, maps, and video embeds relying on their own
  right-click behaviour.
- Added a new "Skip on interactive elements" option (`protect_interactive`),
  **enabled by default**. When on, `input`, `textarea`, `select`, `button`,
  `a`, and `[contenteditable]` elements keep their normal browser
  right-click menu and text-selection/copy behaviour, even while the
  protections above are active on the rest of the page. Images remain
  fully protected regardless of this setting - the point of the plugin
  (deterring easy image saving) is unaffected.
- Turning the option off restores the previous "block truly everywhere,
  including links and forms" behaviour, for site owners who deliberately
  want that.
- The `disableSelect` (copy/cut/selection blocking) protection now honours
  the same exemption for links and buttons; form fields and editable
  content were already exempt from it regardless of this setting (that
  part of the behaviour, from earlier versions, is unchanged).
- Added `test_fg_crc_interactive_exempt.js` (16 jsdom assertions) covering
  both states of the new toggle, the "images only" mode being unaffected,
  and that the `disableSelect` copy-blocking behaviour follows the same
  exemption rules.

## 1.4.2 (2026-08-28)

### Security fix

- **Corrects an inconsistency left by the 1.4.0 security fix.** Removing
  the `js` menu-item type closed the direct `eval`/`new Function` path,
  but `link` item values were never scheme-validated. Assigning
  `window.location.href` to a `javascript:` URL executes it exactly like
  `eval` would - so an admin-authored (or a compromised-admin-authored)
  "link" pointing at `javascript:...` was an equivalent code-execution
  path that the earlier fix did not close.
- Added a strict scheme whitelist for `link` item values, enforced in
  **two places** (PHP at menu-build time, JS again right before
  navigation as defense-in-depth): schemeless values (relative paths,
  `#anchors`, `?query` strings) are always allowed; an explicit scheme
  must be one of `http:`, `https:`, `mailto:`, `tel:`. Everything else -
  `javascript:`, `data:`, `vbscript:`, `file:`, and any other scheme - is
  rejected. Items with a rejected URL are dropped from the menu entirely,
  the same way invalid `action` items already were.
- The scheme check strips ASCII control characters and leading whitespace
  before matching, since browsers ignore these when parsing a URL scheme
  and a naive check without this step is bypassable with tricks like
  `"java\tscript:"` or a leading NUL byte.
- Added `test_fg_crc_link_scheme.php` (16 PHP-side assertions via
  reflection, including the control-character/whitespace bypass attempts)
  and extended the jsdom suite with `test_fg_crc_link_scheme.js` (9
  assertions) confirming unsafe-scheme items never render and safe ones
  are unaffected.

## 1.4.1 (2026-08-28)

### Hardening

- The popup message field (`popup_message`) still accepts admin-authored
  HTML as before, but it is now run through a minimal allowlist sanitiser
  before being injected into the page. Only `a`, `strong`, `em`, `b`, `i`,
  `br`, `p`, `span`, `ul`, `ol`, `li` survive; every other tag (`script`,
  `iframe`, `object`, `embed`, `style`, ...) is stripped entirely. All
  attributes are stripped except `href`/`title`/`target` on `<a>`, event
  handler attributes (`onclick`, `onerror`, ...) included. `<a href>` is
  additionally validated against an `https?:`/`mailto:`/`tel:` allowlist -
  a `javascript:` or other unsafe scheme is dropped, and `rel="noopener
  noreferrer"` is always forced.
- This is defense-in-depth, not a fix for an exploitable vulnerability:
  the message field is admin-only configuration with no visitor-supplied
  input reaching it, the same trust model Joomla itself uses for article
  content and the Custom HTML module. The hardening specifically narrows
  the blast radius if an admin account is ever compromised.
- The popup title was already rendered via `textContent` (never HTML) and
  is unaffected.
- Added a dedicated jsdom test suite (`test_fg_crc_sanitizer.js`, 19
  assertions) covering script-tag stripping, event-handler-attribute
  stripping, `javascript:`-URL stripping with safe-URL preservation,
  `iframe`/`object`/`style` stripping, and that the allowlisted formatting
  tags still render correctly.

## 1.4.0 (2026-08-28)

### Security fix (breaking change)

- **Removed the "JavaScript" custom-menu item type entirely.** It previously
  built a code string from the admin-configured value (with the `{url}`
  placeholder replaced by the raw, unescaped current page URL) and executed
  it via `new Function()`. This meant: (1) a compromised admin account
  could inject arbitrary code that runs on every page view, (2) the plugin
  required `unsafe-eval` in any Content-Security-Policy, and (3) because
  the page URL was spliced into the JS source text rather than passed as a
  function argument, a crafted URL fragment (e.g. containing `'`, `;`, or
  backticks - characters browsers do not URL-encode in a fragment) could
  break out of the intended snippet and inject additional code.
- **Replaced it with a fixed, audited whitelist of built-in actions**:
  reload page, copy page URL to clipboard, print page, scroll to top,
  share page (Web Share API with clipboard fallback). The admin form now
  offers a dropdown of these actions instead of a free-text code field -
  there is no field left anywhere in the plugin that can reach `eval`,
  `new Function`, or similar.
- Existing "Custom menu" configurations using the old JavaScript item type
  are dropped (not migrated) on upgrade - `getMenuItems()` only recognises
  `link`, `action`, and `separator` types now. Re-create those items using
  the new "Built-in action" type after upgrading.
- Link items are unaffected: the `{url}` placeholder there was already
  `encodeURIComponent()`-escaped and only ever used as a URL, never as code.
- Added a dedicated jsdom security-regression suite
  (`test_fg_crc_security.js`) that asserts `new Function(` does not appear
  anywhere in the shipped script, that whitelisted actions run the real
  (stubbed) browser API and nothing else, that a forged/out-of-whitelist
  action key is a silent no-op, and that a deliberately crafted
  `{url}`-breaking page URL is only ever used as a URL string.

## 1.3.0 (2026-08-27)

Rebranded into the FG series as `plg_system_fgcustomrightclick` (previously
a standalone `plg_system_customrightclick` build). Same functionality,
carried forward from v1.2.1:

- Element/folder/namespace renamed to the `fg` convention: PHP namespace
  `FG\Plugin\System\Fgcustomrightclick`, class `Fgcustomrightclick`
- Language files renamed to the Joomla-required `plg_<group>_<element>.*`
  pattern (`plg_system_fgcustomrightclick.ini` / `.sys.ini`, en-GB + sk-SK)
- Media files renamed (`fgcustomrightclick.js` / `.css`); `addScriptOptions`
  key updated to match
- Added `<updateservers>` block pointing at `updates.xml` on GitHub
  (`FGcodework/plg_system_fgcustomrightclick`, master branch)
- Author/copyright set to Fero
- No functional changes to plugin behaviour in this release

### Carried-forward feature set (from the pre-rebrand v1.0.0-v1.2.1 history)

- User-group targeting (empty selection = applies to everyone)
- Disable printing (blocks Ctrl/Cmd+P, hides content via `@media print`)
- Disable text selection & copy (mouse + Ctrl+C/X/A; form fields stay usable)
- Disable image dragging
- Block developer-tools keyboard shortcuts (F12, Ctrl+Shift+I/J/C,
  Cmd+Opt+I/J/C, Ctrl/Cmd+U) - keyboard shortcuts only, DevTools remains
  reachable via the browser's own menu
- Four right-click modes: default browser menu / disabled with optional
  popup / disabled for images only / custom menu
- Popup: title, HTML message, optional auto-close timer
- Custom context menu builder (subform): link / JavaScript / separator
  items, emoji or CSS-class icons, `{url}` placeholder, open-in-new-tab
- Custom menu accessibility: `role="menu"`/`role="menuitem"`, roving
  tabindex, Arrow Up/Down navigation with wrap-around, Home/End, Tab closes
  the menu, Escape closes and returns focus to the previously focused
  element
- Popup accessibility: `role="dialog"`, focus trapped inside via Tab,
  initial focus on the close button, focus returned to the trigger element
  on close
- Keyboard-triggered `contextmenu` events (Shift+F10 / Menu key) that
  report `clientX`/`clientY` as `0,0` are anchored to the focused element's
  bounding rect instead of the viewport corner
- Custom menu positioned with `position: fixed`, clamped to stay inside the
  viewport, closes on outside click/scroll/resize
- ES6+ frontend JS (`const`/`let`, arrow functions, template literals,
  optional chaining) - safe for Joomla 6 native (no IE11 support required)
- Config loaded via `Joomla.getOptions()` with a fallback that reads the
  `script.joomla-script-options` JSON element directly, and a `['core']`
  script dependency so `Joomla.getOptions` is guaranteed to exist before
  this script runs
- Verified throughout via a jsdom behavioural test suite (41 assertions
  covering all modes, accessibility, and edge cases) rather than manual
  testing alone
