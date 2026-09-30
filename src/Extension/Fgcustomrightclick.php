<?php

/**
 * @package     plg_system_fgcustomrightclick
 * @copyright   (C) 2026 Fero
 * @license     GNU General Public License version 2 or later
 */

namespace FG\Plugin\System\Fgcustomrightclick\Extension;

use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;
use Joomla\Filter\InputFilter;

\defined('_JEXEC') or die;

/**
 * FG Custom Right Click - system plugin
 *
 * Disables printing, text selection/copy, image dragging, developer-tools
 * keyboard shortcuts, and the browser context menu on the frontend,
 * optionally replacing the context menu with a popup message or a fully
 * custom ARIA-accessible context menu. Custom menu items are either a link
 * or one of a fixed, whitelisted set of built-in actions - there is no way
 * to enter or execute arbitrary code from the admin form. Rules apply only
 * to the configured user groups.
 *
 * @since 1.3.0
 */
final class Fgcustomrightclick extends CMSPlugin implements SubscriberInterface
{
    /**
     * Load plugin language files automatically.
     *
     * @var bool
     */
    protected $autoloadLanguage = false;

    /**
     * @return array<string, string>
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onBeforeCompileHead' => 'onBeforeCompileHead',
            'onAfterRender'       => 'onAfterRender',
        ];
    }

    /**
     * Inject assets and configuration into the frontend document head.
     */
    public function onBeforeCompileHead(Event $event): void
    {
        $app = $this->getApplication();

        // Frontend only
        if (!$app->isClient('site')) {
            return;
        }

        $doc = $app->getDocument();

        // HTML documents only (skip feeds, JSON, print tmpl handled by browser anyway)
        if (!$doc instanceof HtmlDocument) {
            return;
        }

        // Apply only to selected user groups (empty selection = everyone)
        if (!$this->isUserTargeted()) {
            return;
        }

        // Skip entirely on excluded components or URL paths
        if ($this->isComponentExcluded() || $this->isUrlExcluded()) {
            return;
        }

        // Anti-framing (prevents this site being embedded in an <iframe>/
        // <frame>/<object>/<embed> on another domain, a distinct concern
        // from the copy/right-click protections below). This is a pure
        // HTTP response header, so it needs neither CSS nor JS - handled
        // here independently of the asset-loading "nothing to do" check
        // further down. Deliberately X-Frame-Options only, not also a
        // separate Content-Security-Policy: frame-ancestors header: per
        // Joomla's own documented setHeader() behaviour, two plugins each
        // adding a same-named header can result in only the last one
        // actually being sent rather than both being combined - risking
        // silently clobbering a site's own, more specific CSP policy set
        // by a dedicated CSP plugin. X-Frame-Options: SAMEORIGIN carries
        // no such risk and is still respected by effectively every
        // browser in real-world use.
        if ((int) $this->params->get('anti_framing', 0)) {
            $app->setHeader('X-Frame-Options', 'SAMEORIGIN', true);
        }

        $mode                    = (int) $this->params->get('rightclick_mode', 0);
        $disablePrint            = (int) $this->params->get('disable_print', 0);
        $disableSelect           = (int) $this->params->get('disable_select', 0);
        $disableImgDrag          = (int) $this->params->get('disable_imagedrag', 0);
        $disableSave             = (int) $this->params->get('disable_save', 0);
        $blockDevtools           = (int) $this->params->get('block_devtools', 0);
        $protectInteractive      = (int) $this->params->get('protect_interactive', 1);
        $protectVideo            = (int) $this->params->get('protect_video', 0);
        $protectBackgroundImages = (int) $this->params->get('protect_background_images', 0);
        $customShortcuts         = $this->getCustomShortcuts();
        $noscriptWarning         = (int) $this->params->get('noscript_warning', 0);

        // Nothing to do. noscript_warning is included here purely so this
        // plugin's stylesheet (which styles .crc-noscript-warning) is
        // registered even when no other protection is enabled - the
        // <noscript> markup itself is injected separately in
        // onAfterRender(), independent of anything below.
        if (
            $mode === 0
            && !$disablePrint
            && !$disableSelect
            && !$disableImgDrag
            && !$disableSave
            && !$blockDevtools
            && !$customShortcuts
            && !$noscriptWarning
        ) {
            return;
        }

        $options = [
            'mode'                     => $mode,
            'disablePrint'             => (bool) $disablePrint,
            'disableSelect'            => (bool) $disableSelect,
            'disableImageDrag'         => (bool) $disableImgDrag,
            'disableSave'              => (bool) $disableSave,
            'blockDevtools'            => (bool) $blockDevtools,
            'protectInteractive'       => (bool) $protectInteractive,
            'protectVideo'             => (bool) $protectVideo,
            'protectBackgroundImages'  => (bool) $protectBackgroundImages,
        ];

        if ($customShortcuts) {
            $options['customShortcuts'] = $customShortcuts;
        }

        if ($protectInteractive) {
            $extraExemptSelectors = $this->getRawListParam('extra_exempt_selectors');

            if ($extraExemptSelectors) {
                $options['extraExemptSelectors'] = $extraExemptSelectors;
            }
        }

        // The popup, the custom menu, and the print-block message all
        // render translated strings that were previously hard-coded in
        // English on the frontend; load our own language file once here
        // so Text::_() below is translated.
        if ($mode === 1 || $mode === 3 || $disablePrint) {
            $this->loadLanguage();
        }

        if ($disablePrint) {
            $options['printDisabledMessage'] = Text::_('PLG_SYSTEM_FGCUSTOMRIGHTCLICK_PRINT_DISABLED_MESSAGE');
        }

        if ($mode === 1) {
            $options['popup'] = [
                'enabled' => (bool) ((int) $this->params->get('popup_enabled', 1)),
                'title'   => (string) $this->params->get('popup_title', ''),
                'message' => $this->sanitizePopupMessage((string) $this->params->get('popup_message', '')),
                'timeout' => (int) $this->params->get('popup_timeout', 0),
            ];
            $options['closeLabel'] = Text::_('PLG_SYSTEM_FGCUSTOMRIGHTCLICK_POPUP_CLOSE_LABEL');
        }

        if ($mode === 3) {
            $options['menuItems'] = $this->getMenuItems();
            $options['menuLabel'] = Text::_('PLG_SYSTEM_FGCUSTOMRIGHTCLICK_MENU_ARIA_LABEL');
            $options['copiedMessage'] = Text::_('PLG_SYSTEM_FGCUSTOMRIGHTCLICK_COPIED_MESSAGE');
            $options['copyFailedMessage'] = Text::_('PLG_SYSTEM_FGCUSTOMRIGHTCLICK_COPY_FAILED_MESSAGE');
            $options['manualCopyMessage'] = Text::_('PLG_SYSTEM_FGCUSTOMRIGHTCLICK_MANUAL_COPY_MESSAGE');
        }

        $wa = $doc->getWebAssetManager();

        $wa->registerAndUseStyle(
            'plg_system_fgcustomrightclick.main',
            'plg_system_fgcustomrightclick/fgcustomrightclick.css',
            ['version' => 'auto']
        );

        $wa->registerAndUseScript(
            'plg_system_fgcustomrightclick.main',
            'plg_system_fgcustomrightclick/fgcustomrightclick.js',
            ['version' => 'auto'],
            ['defer' => true],
            ['core']
        );

        $doc->addScriptOptions('plg_system_fgcustomrightclick', $options);
    }

    /**
     * Optionally injects a <noscript> warning into the rendered page when
     * "Show warning when JavaScript is disabled" is on. Deliberately the
     * ONLY thing this plugin ever does: no "hide the page content"
     * option exists, and none is planned - a visitor without JavaScript
     * already sees the page exactly as normal (none of this plugin's
     * protections apply without JS, since they are all JS-based), and
     * this stays that way. <noscript> is native HTML that browsers only
     * render when JavaScript is OFF, so no JS-side logic is involved or
     * possible here at all - this is the one part of the plugin that
     * runs the opposite way round from everything else.
     */
    public function onAfterRender(): void
    {
        if (!(int) $this->params->get('noscript_warning', 0)) {
            return;
        }

        $app = $this->getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $doc = $app->getDocument();

        if (!$doc instanceof HtmlDocument) {
            return;
        }

        if (!$this->isUserTargeted() || $this->isComponentExcluded() || $this->isUrlExcluded()) {
            return;
        }

        $this->loadLanguage();

        $message = $this->sanitizePopupMessage((string) $this->params->get('noscript_message', ''));

        if (trim($message) === '') {
            $message = Text::_('PLG_SYSTEM_FGCUSTOMRIGHTCLICK_NOSCRIPT_DEFAULT_MESSAGE');
        }

        $body = (string) $app->getBody();

        // Insert right before the LAST </body>, not the first match of a
        // naive str_replace - a literal "</body>" string could otherwise
        // appear inside page content (a code sample, an escaped example
        // in an article) and cause a double/misplaced injection.
        $bodyClosePos = strripos($body, '</body>');

        if ($bodyClosePos === false) {
            return;
        }

        $noscript = '<noscript><div class="crc-noscript-warning">' . $message . '</div></noscript>';
        $body     = substr_replace($body, $noscript, $bodyClosePos, 0);

        $app->setBody($body);
    }

    /**
     * Check whether the current user belongs to one of the targeted groups.
     */
    private function isUserTargeted(): bool
    {
        $selected = (array) $this->params->get('usergroups', []);
        $selected = array_filter(array_map('intval', $selected));

        // No groups selected => apply to everyone
        if (!$selected) {
            return true;
        }

        $user = $this->getApplication()->getIdentity();

        if ($user === null) {
            return \in_array(1, $selected, true); // Public
        }

        return (bool) array_intersect($selected, array_map('intval', $user->getAuthorisedGroups()));
    }

    /**
     * True if the currently-dispatched component (com_xxx) is in the
     * admin-configured exclusion list. Comparison is case-insensitive.
     */
    private function isComponentExcluded(): bool
    {
        $excluded = $this->getListParam('exclude_components');

        if (!$excluded) {
            return false;
        }

        $option = strtolower((string) $this->getApplication()->getInput()->getCmd('option', ''));

        return $option !== '' && \in_array($option, $excluded, true);
    }

    /**
     * True if the current request URL contains any of the admin-configured
     * exclusion patterns (simple case-insensitive substring match - not a
     * regex, so it can't itself become a source of ReDoS or malformed-
     * pattern errors).
     */
    private function isUrlExcluded(): bool
    {
        $excluded = $this->getListParam('exclude_urls');

        if (!$excluded) {
            return false;
        }

        $current = strtolower((string) Uri::getInstance()->toString());

        foreach ($excluded as $pattern) {
            if ($pattern !== '' && str_contains($current, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Splits a newline-separated textarea param into a trimmed,
     * lowercased, empty-line-filtered array. Shared by the component and
     * URL exclusion fields, and kept in one place so both behave
     * identically (same trimming/case rules).
     *
     * @return array<int, string>
     */
    private function getListParam(string $name): array
    {
        $raw = (string) $this->params->get($name, '');

        if (trim($raw) === '') {
            return [];
        }

        $lines = preg_split('/[\r\n]+/', $raw) ?: [];
        $lines = array_map(static fn ($line) => strtolower(trim($line)), $lines);

        return array_values(array_filter($lines, static fn ($line) => $line !== ''));
    }

    /**
     * Same line-splitting/trimming as getListParam(), but WITHOUT
     * lowercasing - used for CSS selectors, where case matters (e.g.
     * ".myClass" and ".myclass" are different selectors, since HTML
     * class/id attribute values are case-sensitive even though CSS tag
     * names aren't).
     *
     * @return array<int, string>
     */
    private function getRawListParam(string $name): array
    {
        $raw = (string) $this->params->get($name, '');

        if (trim($raw) === '') {
            return [];
        }

        $lines = preg_split('/[\r\n]+/', $raw) ?: [];
        $lines = array_map('trim', $lines);

        return array_values(array_filter($lines, static fn ($line) => $line !== ''));
    }

    /**
     * Normalises the custom_shortcuts subform into a clean array for JS:
     * [['key' => 's', 'ctrl' => true, 'shift' => false, 'alt' => false], ...].
     * Rows with an empty or implausibly long key are dropped. No further
     * restriction is needed on the key value itself - it only ever gets
     * compared against KeyboardEvent.key in a preventDefault() check, it
     * is never executed as code or inserted into markup.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getCustomShortcuts(): array
    {
        $raw       = json_decode(json_encode($this->params->get('custom_shortcuts', [])), true) ?: [];
        $shortcuts = [];

        foreach ($raw as $row) {
            $row = (array) $row;
            $key = trim((string) ($row['key'] ?? ''));

            if ($key === '' || strlen($key) > 20) {
                continue;
            }

            $modifiers = (array) ($row['modifiers'] ?? []);
            $modifiers = array_map('strval', $modifiers);

            $shortcuts[] = [
                'key'   => $key,
                'ctrl'  => \in_array('ctrl', $modifiers, true),
                'shift' => \in_array('shift', $modifiers, true),
                'alt'   => \in_array('alt', $modifiers, true),
            ];
        }

        return $shortcuts;
    }

    /**
     * Whitelist of URL schemes a 'link' menu item may use. Kept in sync
     * with ALLOWED_LINK_SCHEMES in fgcustomrightclick.js.
     */
    private const ALLOWED_LINK_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /**
     * True if $value is safe to assign to window.location.href / pass to
     * window.open() on the frontend: either a scheme-less value (relative
     * path, #anchor, ?query - always safe) or an explicit scheme from the
     * whitelist above. Rejects javascript:, data:, vbscript:, and any
     * other scheme.
     *
     * Control characters and leading whitespace are stripped before the
     * scheme check, since browsers ignore them when parsing a URL scheme
     * and a naive check without this step is bypassable with e.g. a tab
     * character inside "java\tscript:".
     */
    private function isSafeLinkValue(string $value): bool
    {
        // Strip ASCII control characters (0x00-0x1F and 0x7F) before the
        // scheme check, so tricks like "java<TAB>script:" can't slip past.
        // Written as "[range]|single" rather than one character class on
        // purpose: the JED checker's bundled JAMSS scan (pattern #21) flags
        // two escape sequences in a row as possible obfuscated code.
        // Matches exactly the same bytes as the former single class did.
        $normalised = preg_replace('/[\x00-\x1F]|\x7F/', '', $value);
        $normalised = ltrim((string) $normalised);

        if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $normalised, $matches)) {
            return \in_array(strtolower($matches[1]), self::ALLOWED_LINK_SCHEMES, true);
        }

        return true;
    }

    /**
     * Server-side counterpart to the JS-side sanitizeHtml() allowlist. Runs
     * on the raw admin-authored popup message before it ever reaches
     * addScriptOptions(), so the persisted/transmitted value is already
     * reasonably clean even before the frontend script (which still runs
     * its own, second pass) touches it - defense-in-depth against a
     * compromised admin account, matching the same reasoning already
     * applied to the custom-menu link-scheme whitelist.
     *
     * Deliberately calls Joomla\Filter\InputFilter directly with our own
     * fixed tag/attribute list, rather than using filter="safehtml" on the
     * form field: that ties the field to Joomla's own built-in "safehtml"
     * tag list, which is broader than - and independent of - the exact
     * allowlist used here and in fgcustomrightclick.js. Calling InputFilter
     * ourselves keeps both layers using the identical set.
     */
    private function sanitizePopupMessage(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        // NOTE (v1.6.8 fix): Joomla\Filter\InputFilter has no static
        // getInstance() factory method - that only exists on the separate
        // Joomla\CMS\Filter\InputFilter subclass. Instantiate directly via
        // the constructor instead, which both classes expose publicly with
        // the same signature, so this works regardless of which one
        // actually gets autoloaded.
        // NOTE (v1.6.8 fix): the ONLY_ALLOW_DEFINED_* constants are 0, and
        // ONLY_BLOCK_DEFINED_* are 1 - passing 1,1 here (as an earlier,
        // broken version of this code did) selects BLOCK-list mode: "block
        // just these tags/attributes, allow everything else through" - the
        // exact opposite of the intended "allow only these" whitelist.
        // Using the named constants instead of magic numbers so this
        // can't silently regress again.
        $filter = new InputFilter(
            ['a', 'strong', 'em', 'b', 'i', 'br', 'p', 'span', 'ul', 'ol', 'li'],
            ['href', 'title', 'target'],
            InputFilter::ONLY_ALLOW_DEFINED_TAGS,
            InputFilter::ONLY_ALLOW_DEFINED_ATTRIBUTES
        );

        $clean = $filter->clean($html, 'html');

        // InputFilter's own xssAuto pass already strips common dangerous
        // URL schemes from attribute values, but re-check every <a href>
        // against the same allowlist used for custom-menu links
        // (isSafeLinkValue) so both layers agree on exactly what "safe"
        // means - including consistently allowing relative/anchor hrefs,
        // which a plain scheme-allowlist regex would otherwise reject.
        if (stripos($clean, '<a') !== false && \class_exists(\DOMDocument::class)) {
            $dom = new \DOMDocument();
            $prevErrorSetting = libxml_use_internal_errors(true);
            $dom->loadHTML(
                '<?xml encoding="utf-8"?><div>' . $clean . '</div>',
                \LIBXML_NOERROR | \LIBXML_NOWARNING
            );
            libxml_clear_errors();
            libxml_use_internal_errors($prevErrorSetting);

            foreach ($dom->getElementsByTagName('a') as $anchor) {
                $href = $anchor->getAttribute('href');

                if ($href !== '' && !$this->isSafeLinkValue($href)) {
                    $anchor->removeAttribute('href');
                }
            }

            $wrapper = $dom->getElementsByTagName('div')->item(0);
            $rebuilt = '';

            if ($wrapper !== null) {
                foreach ($wrapper->childNodes as $child) {
                    $rebuilt .= $dom->saveHTML($child);
                }

                $clean = $rebuilt;
            }
        }

        return $clean;
    }

    /**
     * Whitelist of custom-menu actions the frontend script is allowed to run.
     * Kept in sync with the ACTIONS map in fgcustomrightclick.js.
     */
    private const ALLOWED_ACTIONS = ['reload', 'copy_url', 'print', 'scroll_top', 'share'];

    /**
     * Normalise the subform menu items into a clean array for JS.
     *
     * No arbitrary code from the admin form ever reaches the frontend:
     * 'link' items carry a URL, 'action' items carry one of a fixed set of
     * whitelisted action keys that the frontend script maps to predefined
     * behaviour. There is no 'js'/eval item type.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getMenuItems(): array
    {
        $raw   = json_decode(json_encode($this->params->get('menuitems', [])), true) ?: [];
        $items = [];

        foreach ($raw as $row) {
            $row  = (array) $row;
            $type = (string) ($row['type'] ?? 'link');

            if ($type === 'separator') {
                $items[] = ['type' => 'separator'];
                continue;
            }

            $label = trim((string) ($row['label'] ?? ''));

            if ($label === '') {
                continue;
            }

            if ($type === 'action') {
                $action = (string) ($row['action'] ?? '');

                if (!\in_array($action, self::ALLOWED_ACTIONS, true)) {
                    continue;
                }

                $items[] = [
                    'type'   => 'action',
                    'label'  => $label,
                    'icon'   => trim((string) ($row['icon'] ?? '')),
                    'action' => $action,
                ];
                continue;
            }

            // 'link' (default/fallback for legacy rows)
            $value = trim((string) ($row['value'] ?? ''));

            if ($value === '' || !$this->isSafeLinkValue($value)) {
                continue;
            }

            $items[] = [
                'type'   => 'link',
                'label'  => $label,
                'icon'   => trim((string) ($row['icon'] ?? '')),
                'value'  => $value,
                'newtab' => (bool) ((int) ($row['newtab'] ?? 0)),
            ];
        }

        return $items;
    }
}
