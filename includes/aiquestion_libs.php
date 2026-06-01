<?php
/**
 * aiquestion_libs.php — macro-library reference access for AI Question Authoring.
 *
 * Pure functions (no init/gettext/DB dependency) so they can be used from both the
 * web endpoint and the CLI build script (includes/ai/build_reference.php).
 *
 *   aiCleanHelpHtml($html)   → readable plain text from an IMathAS help .html
 *   aiKnownLibraries()       → the real list of macro libraries (validates names)
 *   get_library_manifest()   → the committed manifest (router's menu)
 *   get_library_help($name)  → one library's cleaned help text (name-validated)
 *
 * Library help lives in assessment/libs/<name>.html. The manifest + the sliced
 * core reference are build artifacts produced by includes/ai/build_reference.php.
 */

if (!function_exists('aiLibsBaseDir')) {

/** Absolute path to the IMathAS web root (this file lives in includes/). */
function aiLibsBaseDir() {
    return dirname(__DIR__);
}

/** Directory holding the macro-library help files. */
function aiLibsDir() {
    return aiLibsBaseDir() . '/assessment/libs';
}

/** Directory holding the AI build artifacts. */
function aiRefDir() {
    return __DIR__ . '/ai';
}

/**
 * Convert an IMathAS help HTML fragment to readable plain text suitable for an
 * LLM context block. Drops scripts/styles, turns list items and block ends into
 * newlines, keeps heading text, strips remaining tags, decodes entities, and
 * collapses runaway whitespace. Not a general-purpose sanitizer — input here is
 * trusted repo content, and the output is text, never re-rendered as HTML.
 */
function aiCleanHelpHtml($html) {
    // Drop elements whose contents are not useful as text.
    $html = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', '', $html);

    // Headings → markdown-ish so structure survives.
    $html = preg_replace_callback('#<h([1-6])\b[^>]*>(.*?)</h\1>#is', function ($m) {
        $level = (int)$m[1];
        return "\n\n" . str_repeat('#', $level) . ' ' . trim(strip_tags($m[2])) . "\n";
    }, $html);

    // List items → "- "; block-level ends → newlines.
    $html = preg_replace('#<li\b[^>]*>#i', "\n- ", $html);
    $html = preg_replace('#<br\s*/?>#i', "\n", $html);
    $html = preg_replace('#</(p|div|ul|ol|li|tr|table|h[1-6])\s*>#i', "\n", $html);
    $html = preg_replace('#<tr\b[^>]*>#i', "\n", $html);
    $html = preg_replace('#</td>\s*<td[^>]*>#i', " | ", $html);

    // Remaining tags gone; entities decoded.
    $text = strip_tags($html);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Normalize whitespace: trim line-trailing spaces, collapse 3+ blank lines.
    $text = str_replace("\r\n", "\n", $text);
    $text = preg_replace('/[ \t]+\n/', "\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    // Collapse long runs of spaces (help files use many &nbsp;).
    $text = preg_replace('/[ \t]{2,}/', ' ', $text);

    return trim($text);
}

/**
 * Macro libraries the AI assistant is ALLOWED to load. Core libraries are always
 * available (never need loadlibrary) and are covered by the core reference; this
 * is the curated set of *loadable* libraries the router may select. Kept small to
 * control prompt size/cost and steer the model toward well-supported domains.
 * To offer more, add the library name here (it must exist in assessment/libs/).
 */
function aiAllowedLibraries() {
    return array('matrix', 'radicals', 'shapes', 'jsxgraph', 'finance2', 'complex');
}

/**
 * Macro libraries the AI assistant must NOT use, even though they exist on disk.
 * Superseded/deprecated libraries go here so the router never selects them.
 *   finance — superseded by finance2; use finance2 only.
 * (The allowlist already restricts selection; this is a belt-and-suspenders guard.)
 */
function aiExcludedLibraries() {
    return array('finance');
}

/**
 * The set of macro libraries the assistant may use: the curated allowlist,
 * intersected with what's actually installed on disk (has a backing .php), minus
 * any excluded ones. Used to validate any name the router/recovery hands us, and
 * to build the manifest. Cached per request.
 */
function aiKnownLibraries() {
    static $libs = null;
    if ($libs !== null) { return $libs; }
    $excluded = aiExcludedLibraries();
    $allowed  = aiAllowedLibraries();
    $libs = array();
    foreach ($allowed as $name) {
        if (in_array($name, $excluded, true)) { continue; }
        // Must exist as a real library (html + backing php), matching libhelp.php.
        if (is_readable(aiLibsDir() . '/' . $name . '.html') &&
            file_exists(aiLibsDir() . '/' . $name . '.php')) {
            $libs[] = $name;
        }
    }
    sort($libs);
    return $libs;
}

/** True if $name is a real, loadable macro library. */
function aiIsKnownLibrary($name) {
    return in_array($name, aiKnownLibraries(), true);
}

/**
 * Load the committed library manifest (router's menu). Returns a decoded array of
 * {name, summary} entries, or array() if the artifact hasn't been built yet.
 */
function get_library_manifest() {
    $path = aiRefDir() . '/library-manifest.json';
    if (!is_readable($path)) { return array(); }
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : array();
}

/**
 * Cleaned help text for one macro library, or '' if the name is unknown/unsafe.
 * Name is validated against the real library list before any file access.
 */
function get_library_help($name) {
    if (!is_string($name) || !aiIsKnownLibrary($name)) { return ''; }
    $path = aiLibsDir() . '/' . $name . '.html';
    if (!is_readable($path)) { return ''; }
    return aiCleanHelpHtml(file_get_contents($path));
}

/**
 * Short summary line for a library, parsed from its help file: the text between
 * the "Macro Library X" heading and the first function list. Used by the build
 * script to populate the manifest. Truncated to keep the manifest small.
 */
function aiLibrarySummary($name) {
    $path = aiLibsDir() . '/' . $name . '.html';
    if (!is_readable($path)) { return ''; }
    $html = file_get_contents($path);

    // Grab from after the "Macro Library ..." h1 up to the first <ul>.
    if (preg_match('#Macro Library[^<]*</h1>(.*?)<ul\b#is', $html, $m)) {
        $chunk = $m[1];
    } else if (preg_match('#</h1>(.*?)(<ul\b|<h[23]\b)#is', $html, $m)) {
        $chunk = $m[1];
    } else {
        $chunk = '';
    }
    $summary = aiCleanHelpHtml($chunk);
    $summary = trim(preg_replace('/\s+/', ' ', $summary));
    if (strlen($summary) > 200) {
        $summary = rtrim(substr($summary, 0, 200)) . '…';
    }
    return $summary;
}

} // function_exists guard
