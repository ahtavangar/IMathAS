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
    return array(
        'number', 'calculated', 'complex', 'calccomplex',
        'choices', 'multans', 'matching',
        'numfunc',
        'string', 'essay', 'file', 'draw',
        'ntuple', 'calcntuple', 'complexntuple', 'calccomplexntuple', 'algntuple',
        'matrix', 'calcmatrix', 'complexmatrix', 'calccomplexmatrix', 'algmatrix',
        'interval', 'calcinterval',
        'chemeqn', 'molecule',
        'multipart', 'conditional',
    );
}

/**
 * Build the router's system prompt: the qtype enum + the library manifest, with
 * a strict output contract. Kept small and stable so it caches well.
 */
function aiRouterSystemPrompt() {
    $qtypes = aiQuestionQtypes();
    $manifest = get_library_manifest();

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
        "1. qtype — the single best question type, from EXACTLY this list:\n   " .
        implode(', ', $qtypes) . "\n" .
        "2. libraries — the macro libraries whose functions the question will need, " .
        "chosen ONLY from the list below. Most basic number/algebra questions need NONE " .
        "(return an empty array); only include a library when the topic clearly matches it. " .
        "Prefer the fewest libraries that cover the topic.\n\n" .
        "Available macro libraries:\n" . $libText . "\n\n" .
        "Respond with ONLY a JSON object, no prose:\n" .
        '{"qtype": "<one of the qtypes>", "libraries": ["<lib>", ...], "reasoning": "<one short sentence>"}';
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
