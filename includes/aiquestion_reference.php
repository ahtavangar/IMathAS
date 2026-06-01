<?php
/**
 * aiquestion_reference.php — slice the authoring reference into a cacheable CORE
 * part and a small per-qtype answer-spec part.
 *
 * Cost optimization: the full reference (~46k tokens) is ~60% per-qtype answer
 * specs that are irrelevant to any single question. We cache only the stable CORE
 * (syntax + macros + math entry — identical for every question, so the cache entry
 * is reused across all qtypes/users) and send just the relevant qtype's answer
 * format as uncached input.
 *
 * The split point is the "## Question Types" heading; everything before it is
 * CORE, everything after is per-qtype "### <Type>" subsections.
 */

require_once __DIR__ . '/aiquestion_libs.php';

if (!function_exists('aiReferenceText')) {

/** Raw reference artifact text (or '' if not built). Cached per request. */
function aiReferenceText() {
    static $txt = null;
    if ($txt !== null) { return $txt; }
    $path = aiRefDir() . '/authoring-reference.md';
    $txt = is_readable($path) ? file_get_contents($path) : '';
    return $txt;
}

/**
 * Split the reference into [core, qtypeSpecsRegion]. The qtype-specs region starts
 * at the "Question Types and answer formats" heading. If that heading isn't found
 * (older artifact), everything is core and the specs region is ''.
 */
function aiReferenceSplit() {
    static $split = null;
    if ($split !== null) { return $split; }

    $text = aiReferenceText();
    if ($text === '') { return $split = array('', ''); }

    $lines = explode("\n", $text);
    $idx = null;
    foreach ($lines as $i => $line) {
        // The core "## Question Types" section header (NOT "### Number" etc, and
        // not the "Conditional Test Macros" core section). Match a level-2 heading
        // whose text is exactly "Question Types".
        if (preg_match('/^##\s+Question Types\s*$/i', $line)) {
            $idx = $i;
            break;
        }
    }
    if ($idx === null) {
        return $split = array($text, '');
    }
    $core  = implode("\n", array_slice($lines, 0, $idx));
    $specs = implode("\n", array_slice($lines, $idx));
    return $split = array($core, $specs);
}

/** The cacheable CORE reference (syntax + macros), shared across all questions. */
function aiReferenceCore() {
    list($core, ) = aiReferenceSplit();
    return $core;
}

/**
 * qtype => list of "### <Type>" subsection heading substrings (within the specs
 * region) to include. Matched case-insensitively. Names match the actual headings
 * in the reference (e.g. "Numerical Matrix", "Algebraic Matrix", "Function").
 * Multipart/conditional use the '*' sentinel to pull the whole region (parts can
 * be any type).
 */
function aiQtypeReferenceMap() {
    return array(
        'number'            => array('Number'),
        'calculated'        => array('Calculated'),
        'complex'           => array('Complex'),
        'calccomplex'       => array('Calculated Complex'),
        'choices'           => array('Multiple-Choice'),
        'multans'           => array('Multiple-Answer'),
        'matching'          => array('Matching'),
        'numfunc'           => array('Function'),
        'string'            => array('String'),
        'essay'             => array('Essay'),
        'file'              => array('File Upload'),
        'draw'              => array('Drawing'),
        'ntuple'            => array('N-Tuple'),
        'calcntuple'        => array('Calculated N-Tuple'),
        'complexntuple'     => array('Complex N-Tuple'),
        'calccomplexntuple' => array('Calculated Complex N-Tuple'),
        'algntuple'         => array('Algebraic N-Tuple'),
        'matrix'            => array('Numerical Matrix'),
        'calcmatrix'        => array('Calculated Matrix'),
        'complexmatrix'     => array('Complex Numerical Matrix'),
        'calccomplexmatrix' => array('Calculated Complex Matrix'),
        'algmatrix'         => array('Algebraic Matrix'),
        'interval'          => array('Interval'),
        'calcinterval'      => array('Calculated Interval'),
        'chemeqn'           => array('Chemical Equation'),
        'molecule'          => array('Chemical Molecule'),
        // Parts can be any type → pull the whole specs region.
        'multipart'         => array('*'),
        'conditional'       => array('*'),
    );
}

/**
 * The per-qtype answer-format reference slice (uncached). Returns the specs intro
 * + the subsection(s) relevant to $qtype + the formatting tips. For multipart /
 * conditional (parts may be any type) returns the whole specs region.
 */
function aiReferenceForQtype($qtype) {
    list(, $specs) = aiReferenceSplit();
    if ($specs === '') { return ''; }

    $map = aiQtypeReferenceMap();
    $wanted = isset($map[$qtype]) ? $map[$qtype] : array('*');

    // '*' => return the full specs region (multipart/conditional need everything).
    if (in_array('*', $wanted, true)) {
        return $specs;
    }
    $subs = aiSplitIntoSubsections($specs);
    // The intro text before the first ### lists every anstype code — always keep.
    $out = isset($subs['__intro__']) ? rtrim($subs['__intro__']) . "\n\n" : '';

    // Match headings EXACTLY (case-insensitive, trimmed) to avoid over-matching
    // (e.g. "Complex" must not pull "Calculated Complex" / "Complex N-Tuple").
    $want = array();
    foreach ($wanted as $w) { $want[strtolower(trim($w))] = true; }

    foreach ($subs as $heading => $body) {
        if ($heading === '__intro__') { continue; }
        if (isset($want[strtolower(trim($heading))])) {
            $out .= $body . "\n\n";
        }
    }
    return rtrim($out);
}

/**
 * Split a specs region into subsections keyed by "### <Type>" heading text. The
 * text before the first ### is stored under '__intro__' (it includes the
 * "## Question Types" header and the anstype code list). Each value includes its
 * own heading line.
 */
function aiSplitIntoSubsections($specs) {
    $lines = explode("\n", $specs);
    $subs = array('__intro__' => '');
    $cur = '__intro__';
    $buf = array();
    foreach ($lines as $line) {
        if (preg_match('/^###\s+(.*)$/', $line, $m)) {
            // flush previous
            $subs[$cur] = implode("\n", $buf);
            $cur = trim($m[1]);
            $buf = array($line);
        } else {
            $buf[] = $line;
        }
    }
    $subs[$cur] = implode("\n", $buf);
    return $subs;
}

} // function_exists guard
