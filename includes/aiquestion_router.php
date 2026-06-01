<?php
/**
 * aiquestion_router.php — Stage 1 of the generation pipeline (classifier router).
 *
 * Given a natural-language question request, a cheap/fast model picks the question
 * type and the macro libraries the question will need (loadlibrary set). This is a
 * classification job over ~46 well-separated library domains, not a similarity
 * search — see .claude/features/ai-question-authoring-plan.md for why no embeddings.
 *
 * aiRouteQuestion() is a plain callable so the verify-recovery path and a future
 * agentic upgrade can reuse it. Output is strictly validated against the real
 * qtype enum and the real installed libraries before it is trusted.
 */

require_once __DIR__ . '/aiclient.php';
require_once __DIR__ . '/aiquestion_libs.php';

if (!function_exists('aiQuestionQtypes')) {

/**
 * Canonical question-type enum, matching the editor's add-type menu (the data-sn
 * values in course/moddataset.php) — these are the strings stored in
 * imas_questionset.qtype. Note the algebraic-expression type is "numfunc".
 */
function aiQuestionQtypes() {
    return array_keys(aiQuestionQtypeLabels());
}

/**
 * qtype code => human-readable label/description for the router. Many families
 * (number, n-tuple, matrix, interval) have subtypes whose code differs only by a
 * prefix; the router MUST pick the right subtype, so each carries a hint:
 *   - plain        = entries are plain integers/decimals
 *   - "calc..."    = entries may be unsimplified calculations (2/3, 5^2)
 *   - "complex..." = entries are complex numbers (a+bi)
 *   - "alg..."     = entries are algebraic expressions with variables (x, 2x+1)
 */
function aiQuestionQtypeLabels() {
    return array(
        'number'            => 'Number — answer is a single plain number (integer/decimal)',
        'calculated'        => 'Calculated Number — answer is a number entered as a calculation (e.g. 2/3, 5^2)',
        'complex'           => 'Complex Number — answer is a complex number a+bi',
        'calccomplex'       => 'Calculated Complex Number — complex number entered as a calculation',
        'choices'           => 'Multiple Choice — pick one option',
        'multans'           => 'Multiple Answer — pick all that apply',
        'matching'          => 'Matching — match items between two lists',
        'numfunc'           => 'Algebraic Expression (Function) — answer is an expression/function with variables (e.g. 2x+1, sin(x))',
        'string'            => 'String — short free-text answer matched against expected text',
        'essay'             => 'Essay — long free-text, manually graded',
        'file'              => 'File Upload — student uploads a file',
        'draw'              => 'Drawing — student draws on a graph/canvas',
        'ntuple'            => 'N-Tuple — ordered tuple/point of plain numbers, e.g. (3,5)',
        'calcntuple'        => 'Calculated N-Tuple — tuple whose entries are calculations',
        'complexntuple'     => 'Complex N-Tuple — tuple of complex numbers',
        'calccomplexntuple' => 'Calculated Complex N-Tuple — tuple of calculated complex numbers',
        'algntuple'         => 'Algebraic N-Tuple — tuple whose entries are algebraic expressions with variables',
        'matrix'            => 'Matrix — matrix of plain numbers',
        'calcmatrix'        => 'Calculated Matrix — matrix whose entries are calculations (2/3, 5^2)',
        'complexmatrix'     => 'Complex Matrix — matrix of complex numbers',
        'calccomplexmatrix' => 'Calculated Complex Matrix — matrix of calculated complex numbers',
        'algmatrix'         => 'Algebraic Matrix — matrix whose entries are algebraic expressions WITH VARIABLES (e.g. x, 2a+1)',
        'interval'          => 'Interval — answer is an interval like [2,5) of plain numbers',
        'calcinterval'      => 'Calculated Interval — interval whose endpoints are calculations',
        'chemeqn'           => 'Chemical Equation — balance/enter a chemical equation',
        'molecule'          => 'Chemical Molecule — enter a molecular structure',
        'multipart'         => 'Multipart — several sub-questions, each its own type (use when the question has multiple distinct answer blanks of differing types)',
        'conditional'       => 'Conditional — multipart with scaffolding/branching',
    );
}

/**
 * Build the router's system prompt: the qtype enum + the library manifest, with
 * a strict output contract. Kept small and stable so it caches well.
 */
function aiRouterSystemPrompt() {
    $manifest = get_library_manifest();

    $qtypeLines = array();
    foreach (aiQuestionQtypeLabels() as $code => $label) {
        $qtypeLines[] = '- ' . $code . ' = ' . $label;
    }
    $qtypeText = implode("\n", $qtypeLines);

    $libLines = array();
    foreach ($manifest as $entry) {
        if (empty($entry['name'])) { continue; }
        $summary = isset($entry['summary']) ? $entry['summary'] : '';
        $libLines[] = '- ' . $entry['name'] . ': ' . $summary;
    }
    $libText = $libLines ? implode("\n", $libLines)
                         : '(library manifest not built yet)';

    return
        "You are a routing classifier for the IMathAS / MyOpenMath question authoring system.\n" .
        "Given a natural-language description of a math question to create, choose:\n" .
        "1. qtype — the single best question type code, chosen from EXACTLY this list. " .
        "Several types come in subtype families (plain / calculated / complex / algebraic). " .
        "Choose the subtype carefully: if the answer entries contain VARIABLES or algebraic " .
        "expressions, pick the \"alg...\" variant (e.g. algmatrix, algntuple, numfunc); if entries " .
        "are unsimplified calculations, pick the \"calc...\" variant; if entries are complex numbers, " .
        "pick the \"complex...\" variant; otherwise pick the plain variant.\n" .
        $qtypeText . "\n\n" .
        "2. libraries — the macro libraries whose functions the question will need, " .
        "chosen ONLY from the list below. Most basic number/algebra questions need NONE " .
        "(return an empty array); only include a library when the topic clearly matches it. " .
        "Prefer the fewest libraries that cover the topic.\n\n" .
        "Available macro libraries:\n" . $libText . "\n\n" .
        "Respond with ONLY a JSON object, no prose:\n" .
        '{"qtype": "<one of the qtype codes>", "libraries": ["<lib>", ...], "reasoning": "<one short sentence>"}';
}

/**
 * Route a request. Returns:
 *   ['ok'=>true, 'qtype'=>..., 'libraries'=>[...], 'reasoning'=>..., 'usage'=>[...]]
 * or
 *   ['ok'=>false, 'error'=>...]
 *
 * @param string $prompt   the author's natural-language request
 * @param array  $opts     optional: 'max_libs' override
 */
function aiRouteQuestion($prompt, $opts = array()) {
    global $CFG;

    if (!aiClientEnabled()) {
        return array('ok' => false, 'error' => 'AI feature is not enabled.');
    }
    $prompt = trim((string)$prompt);
    if ($prompt === '') {
        return array('ok' => false, 'error' => 'Empty request.');
    }

    $maxLibs = isset($opts['max_libs']) ? (int)$opts['max_libs']
             : (isset($CFG['AI']['max_libs']) ? (int)$CFG['AI']['max_libs'] : 3);
    if ($maxLibs < 0) { $maxLibs = 0; }

    $model = !empty($CFG['AI']['routermodel']) ? $CFG['AI']['routermodel']
           : (!empty($CFG['AI']['fastmodel']) ? $CFG['AI']['fastmodel'] : $CFG['AI']['model']);

    // System prompt is the large stable part → mark it cacheable.
    $res = aiChat(
        array(array('role' => 'user', 'content' => $prompt)),
        array(
            'system'    => array(aiSystemBlock(aiRouterSystemPrompt(), true)),
            'model'     => $model,
            'json'      => true,
            'maxtokens' => 512,
        )
    );

    if (!$res['ok']) {
        return array('ok' => false, 'error' => $res['error'] ?: 'Router request failed.');
    }
    $json = $res['json'];
    if (!is_array($json)) {
        return array('ok' => false, 'error' => 'Router returned unparseable output.',
                     'raw' => $res['text']);
    }

    // --- Validate qtype --------------------------------------------------
    $qtype = isset($json['qtype']) ? (string)$json['qtype'] : '';
    if (!in_array($qtype, aiQuestionQtypes(), true)) {
        // Don't fail hard — fall back to 'number', the safest default.
        $qtype = 'number';
    }

    // --- Validate libraries against the real installed set ---------------
    $libraries = array();
    if (!empty($json['libraries']) && is_array($json['libraries'])) {
        foreach ($json['libraries'] as $lib) {
            $lib = is_string($lib) ? trim($lib) : '';
            if ($lib !== '' && aiIsKnownLibrary($lib) && !in_array($lib, $libraries, true)) {
                $libraries[] = $lib;
            }
            if (count($libraries) >= $maxLibs) { break; }
        }
    }

    $reasoning = isset($json['reasoning']) ? (string)$json['reasoning'] : '';

    return array(
        'ok'        => true,
        'qtype'     => $qtype,
        'libraries' => $libraries,
        'reasoning' => $reasoning,
        'usage'     => $res['usage'],
    );
}

} // function_exists guard
