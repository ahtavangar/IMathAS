<?php
/**
 * aiquestion.php — AI Question Authoring endpoint (JSON).
 *
 * Modes:
 *   fix      — explain / diagnose the current draft and suggest fixes (Phase 1).
 *   generate — natural-language → full question (Phase 4).
 *   solution — draft a detailed solution (Phase 5, not yet implemented).
 *
 * Consumed by the legacy editor course/moddataset.php via vanilla fetch().
 * Returns JSON only. Gated on the AI feature being configured + teacher rights.
 *
 * See .claude/features/ai-question-authoring-plan.md.
 */

require_once "../init.php";
require_once "../includes/aiclient.php";
require_once "../includes/aiquestion_libs.php";
require_once "../includes/aiquestion_router.php";
require_once "../includes/aiquestion_verify.php";
require_once "../includes/aiquestion_reference.php";

header('Content-Type: application/json; charset=utf-8');

// Generation can take a while: a strong model (Opus) plus the verify loop and a
// self-correction retry can run well past PHP's default 30s max_execution_time,
// which would kill the request mid-flight and surface as a generic failure in the
// editor. Give it room (the curl call has its own 120s timeout in aiclient.php).
@set_time_limit(180);

// --- Gates --------------------------------------------------------------
if (!aiClientEnabled()) {
    http_response_code(503);
    echo json_encode(array('error' => _('The AI assistant is not enabled on this site.')));
    exit;
}

$minrights = isset($CFG['AI']['min_rights']) ? (int)$CFG['AI']['min_rights'] : 20;
if ($myrights < $minrights || !aiUserAllowed()) {
    http_response_code(403);
    echo json_encode(array('error' => _('You do not have permission to use this feature.')));
    exit;
}

// --- Input --------------------------------------------------------------
// Field contents are the author's own draft code; we must NOT html-strip them
// (that would corrupt the IMathAS DSL). We bound length and treat them strictly
// as untrusted user-role content for the model — never as system instructions.
$MAXFIELD = 20000;
function ai_field($key, $max) {
    $v = isset($_POST[$key]) ? (string)$_POST[$key] : '';
    if (strlen($v) > $max) { $v = substr($v, 0, $max); }
    return $v;
}

$mode = preg_replace('/[^a-z]/', '', $_POST['mode'] ?? ($_GET['mode'] ?? 'fix'));

// In the modern editor the Common Control, Question Control, and Answer are
// merged into the single #control field; there is no separate answer textarea.
$fields = array(
    'qtype'     => preg_replace('/[^a-zA-Z0-9_]/', '', ai_field('qtype', 40)),
    'control'   => ai_field('control', $MAXFIELD),
    'qtext'     => ai_field('qtext', $MAXFIELD),
    'solution'  => ai_field('solution', $MAXFIELD),
);
$userprompt = ai_field('userprompt', 4000);

// --- Dispatch -----------------------------------------------------------
switch ($mode) {
    case 'fix':
        $result = ai_mode_fix($fields, $userprompt);
        break;
    case 'generate':
        $result = ai_mode_generate($userprompt);
        break;
    case 'solution':
        http_response_code(501);
        echo json_encode(array('error' => _('This AI mode is not available yet.')));
        exit;
    default:
        http_response_code(400);
        echo json_encode(array('error' => _('Unknown AI mode.')));
        exit;
}

// --- Respond + log ------------------------------------------------------
if (!$result['ok']) {
    http_response_code(502);
    echo json_encode(array('error' => $result['error'] ?: _('The AI request failed.')));
    exit;
}

ai_log($mode,
       isset($result['logqtype']) ? $result['logqtype'] : $fields['qtype'],
       isset($result['loglibs'])  ? $result['loglibs']  : '',
       $result['promptlog'], $result['text'],
       isset($result['retries']) ? $result['retries'] : 0,
       $result['usage'],
       isset($result['verified']) ? $result['verified'] : 0);

echo json_encode($result['response']);
exit;

// ========================================================================

/**
 * Explain / Fix: describe what the draft does and flag concrete problems with
 * suggested fixes. Returns prose for the author to read — it never rewrites the
 * fields (review-gated). No router/reference yet (Phase 1); the field contents
 * are the only context.
 */
function ai_mode_fix($fields, $userprompt) {
    global $CFG;

    $system =
        "You are an expert author of IMathAS / MyOpenMath algorithmic math questions, " .
        "helping an instructor in the question editor.\n\n" .
        "An IMathAS question has these parts:\n" .
        "- Common Control: PHP-like setup code that defines randomized variables (\$a, \$b, ...), " .
        "may call loadlibrary(\"...\") and randomizers (rand, shuffle, ...), and holds the answer " .
        "settings (\$answer, \$answertype, \$answerformat, ...) — in this editor the question control " .
        "and answer all live together in the Common Control field.\n" .
        "- Question Text (qtext): what the student sees; references variables as \$a and answer " .
        "boxes as [AB1], [AB2], ....\n" .
        "- Detailed Solution: optional worked explanation.\n\n" .
        "Diagnose the draft below. Explain briefly what it does, then list any bugs, undefined " .
        "variables, mismatched answer boxes, library calls that are missing/misused, or likely " .
        "evaluation errors — each with a concrete, minimal fix. If something looks fine, say so. " .
        "Be specific and concise. Do NOT output a full rewritten question; the author will apply " .
        "changes themselves. Use plain text with short headers and bullet points.";

    $draft = "qtype: " . ($fields['qtype'] === '' ? '(unset)' : $fields['qtype']) . "\n\n" .
             "=== Common Control (incl. question control + answer) ===\n" . rtrim($fields['control']) . "\n\n" .
             "=== Question Text ===\n"     . rtrim($fields['qtext'])     . "\n\n" .
             "=== Detailed Solution ===\n" . rtrim($fields['solution']);

    $user = "Here is the current question draft:\n\n" . $draft;
    if (trim($userprompt) !== '') {
        $user .= "\n\nThe author specifically asks: " . $userprompt;
    }

    $model = !empty($CFG['AI']['fastmodel']) ? $CFG['AI']['fastmodel'] : $CFG['AI']['model'];

    $res = aiChat(
        array(array('role' => 'user', 'content' => $user)),
        array('system' => $system, 'model' => $model)
    );

    if (!$res['ok']) { return $res; }
    $res['promptlog'] = $user;   // store the variable part; system prompt is static
    $res['response']  = array('success' => true, 'mode' => 'fix', 'text' => $res['text']);
    return $res;
}

/**
 * Generate: natural-language request → full question, validated by the verify loop.
 *
 * Pipeline (see plan): router (stage 1) picks qtype + libraries; generation (stage 2)
 * runs with the cached authoring reference + selected library help; the draft is run
 * headlessly through AssessStandalone (stage 3) and, on failure, re-prompted up to
 * verify_retries — appending the captured errors, and the help text of any library
 * the recovery path implicates (undefined/unallowed macro). Returns the fields for
 * the editor to fill, plus routing + verification status. Never auto-saves; the
 * editor confirms before overwriting.
 */
function ai_mode_generate($userprompt) {
    global $CFG;

    $userprompt = trim($userprompt);
    if ($userprompt === '') {
        return array('ok' => false, 'error' => _('Please describe the question to generate.'));
    }

    // --- Stage 1: route -------------------------------------------------
    $route = aiRouteQuestion($userprompt);
    if (!$route['ok']) {
        return array('ok' => false, 'error' => $route['error'] ?: _('Routing failed.'));
    }
    $qtype = $route['qtype'];
    $libs  = $route['libraries'];   // already validated + capped

    $maxLibs = isset($CFG['AI']['max_libs']) ? (int)$CFG['AI']['max_libs'] : 3;
    $maxRetries = isset($CFG['AI']['verify_retries']) ? (int)$CFG['AI']['verify_retries'] : 1;

    // Token accounting across all calls.
    $usage = $route['usage'];
    $accUsage = function ($u) use (&$usage) {
        foreach (array('in','out','cache_read','cache_write') as $k) {
            $usage[$k] = ($usage[$k] ?? 0) + ($u[$k] ?? 0);
        }
    };

    $model = !empty($CFG['AI']['model']) ? $CFG['AI']['model'] : $CFG['AI']['fastmodel'];

    // --- Build the cached system block ----------------------------------
    // Order: stable core reference (cache it) → selected library help → contract.
    $messages = array(
        array('role' => 'user', 'content' => aiGenerateUserPrompt($userprompt, $qtype, $libs)),
    );

    $lastText = '';
    $retries  = 0;
    $verify   = array('ok' => false, 'errors' => array(), 'missing_libraries' => array());
    $draft    = null;

    for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {

        $res = aiChat($messages, array(
            'system'    => aiGenerateSystemBlocks($qtype, $libs),
            'model'     => $model,
            'json'      => true,
        ));
        $accUsage($res['usage']);
        if (!$res['ok']) {
            return array('ok' => false, 'error' => $res['error'] ?: _('Generation failed.'));
        }
        $lastText = $res['text'];

        $draft = aiNormalizeGeneratedDraft($res['json'], $qtype);
        if ($draft === null) {
            // Couldn't parse a question object — one retry asking for clean JSON.
            $messages[] = array('role' => 'assistant', 'content' => $res['text']);
            $messages[] = array('role' => 'user', 'content' =>
                'That was not valid JSON matching the required output contract. ' .
                'Reply with ONLY the JSON object, no prose or code fences.');
            $retries++;
            continue;
        }

        // --- Stage 3: verify --------------------------------------------
        $verify = aiVerifyDraft(array(
            'qtype'   => $draft['qtype'],
            'control' => $draft['control'],
            'qtext'   => $draft['qtext'],
            'solution'=> $draft['solution'],
        ));

        if ($verify['ok']) {
            break;  // clean draft — done
        }
        if ($attempt >= $maxRetries) {
            break;  // out of retries — return best-effort with warnings
        }

        // --- Re-prompt with errors + library recovery -------------------
        $retries++;
        $correction = "The generated question failed automated verification across test seeds. " .
            "Errors:\n- " . implode("\n- ", array_slice($verify['errors'], 0, 12)) . "\n\n";

        // Library-recovery: if an unloaded library is implicated, add its help and
        // make sure the loadlibrary set includes it next round.
        $newLibs = $verify['missing_libraries'];
        if (!empty($newLibs)) {
            foreach ($newLibs as $nl) {
                if (!in_array($nl, $libs, true) && count($libs) < $maxLibs) {
                    $libs[] = $nl;
                }
            }
            $correction .= "These functions come from macro libraries that must be loaded with " .
                "loadlibrary(\"...\"): " . implode(', ', $newLibs) . ". " .
                "The reference for these libraries is included below.\n\n" .
                aiLibraryHelpBlockText($newLibs) . "\n\n";
        }
        $correction .= "Return a corrected JSON object only, fixing these problems. " .
            "Keep the same question intent.";

        $messages[] = array('role' => 'assistant', 'content' => $res['text']);
        $messages[] = array('role' => 'user', 'content' => $correction);
    }

    if ($draft === null) {
        return array('ok' => false, 'error' => _('The AI did not return a usable question.'),
                     'usage' => $usage, 'promptlog' => $userprompt, 'text' => $lastText);
    }

    // Build editor-facing response. Fields map to the editor's textareas:
    // #qtype (hidden), #control (common control + question control + answer all live
    // here in the modern editor), #qtext, #solution.
    $statusParts = array();
    $statusParts[] = $libs ? sprintf(_('Loaded: %s'), implode(', ', $libs)) : _('No extra libraries');
    if ($verify['ok']) {
        $statusParts[] = sprintf(_('Verified across %d seeds'), count($verify['seeds']));
    } else {
        $statusParts[] = sprintf(_('%d unresolved warning(s) — review carefully'),
                                 count($verify['errors']));
    }

    return array(
        'ok'       => true,
        'usage'    => $usage,
        'retries'  => $retries,
        'verified' => $verify['ok'] ? 1 : 0,
        'logqtype' => $draft['qtype'],
        'loglibs'  => implode(',', $libs),
        'promptlog'=> $userprompt,
        'text'     => $lastText,
        'response' => array(
            'success'   => true,
            'mode'      => 'generate',
            'fields'    => array(
                'qtype'       => $draft['qtype'],
                'description' => $draft['description'],
                'control'     => $draft['control'],
                'qtext'       => $draft['qtext'],
                'solution'    => $draft['solution'],
            ),
            'notes'     => $draft['notes'],
            'libraries' => $libs,
            'verified'  => $verify['ok'],
            'errors'    => $verify['errors'],
            'reasoning' => $route['reasoning'],
            'status'    => implode(' · ', $statusParts),
        ),
    );
}

/**
 * System blocks for generation. To control cost, only the stable CORE block is
 * cached (instructions + DSL rules + contract + core reference) — it's identical
 * for every question, so the cache entry is reused across qtypes/users. The
 * per-qtype answer-format slice and the selected library help are sent uncached
 * (small, request-specific). The chosen qtype is deliberately kept OUT of the
 * cached block so the cache key doesn't vary by qtype.
 */
function aiGenerateSystemBlocks($qtype, $libs) {
    $blocks = array();

    // 1) CACHED CORE: generic instructions + rules + contract + core reference.
    //    No qtype/library specifics here, so this block is byte-identical across
    //    all generations and the cache is shared.
    $instructions =
        "You are an expert author of IMathAS / MyOpenMath algorithmic math questions. " .
        "Generate a complete, correct question from the author's natural-language request, " .
        "using ONLY functions and syntax documented in the reference below (and in any macro " .
        "library help provided). If you are unsure a function exists, do not use it.\n\n" .
        "QUESTION STRUCTURE: output these fields.\n" .
        "- description: a concise one-line description of the question for the author's " .
        "library (NOT shown to students), e.g. \"Compound interest: solve for future value\".\n" .
        "- qtype: the question type (provided to you with this request).\n" .
        "- control: the Common Control code — define randomized variables with \$ (e.g. \$a=rand(2,9)), " .
        "any loadlibrary(\"...\") line at the very top, and the answer settings (\$answer, \$answertype, " .
        "\$answerformat, etc.). In this editor the Common Control, Question Control, and Answer all live " .
        "together in this one field.\n" .
        "- qtext: the Question Text shown to students. Reference variables as \$a; place answer blanks as " .
        "[AB1], [AB2], … matching the answer setup.\n" .
        "- solution: an optional detailed solution (HTML). May be empty.\n\n" .
        "MATH FORMATTING (critical): IMathAS renders math from ASCIIMath, NOT LaTeX. In question text and " .
        "any displayed strings, wrap math in backticks, e.g. `x^2/3` or `sqrt(\$a)`. NEVER use LaTeX math " .
        "delimiters \$...\$ or \\(...\\) — in IMathAS a leading \$ denotes a variable, so \$x\$ would be " .
        "misread as variables. Use backtick/ASCIIMath notation only.\n\n" .
        "ANSWER FORMATTING (critical): NEVER use curly braces { } in \$answer or any answer-value field. " .
        "Curly braces are control-flow syntax (loops/grouping) for Common Control code only, and will cause " .
        "answers to be mis-graded. Use the documented answer format for the chosen qtype. In particular, " .
        "matrices (matrix/calcmatrix/complexmatrix/calccomplexmatrix/algmatrix) use parentheses-and-brackets " .
        "ASCIIMath form: \$answer = \"[(1,2,3),(4,5,6)]\" is a 2x3 matrix (rows in inner parentheses); " .
        "n-tuples use \"(3,5)\"; intervals use \"[2,5)\". Set \$answersize for matrices/tuples per the reference.\n\n" .
        "OUTPUT CONTRACT: respond with ONLY a JSON object, no prose, no code fences:\n" .
        '{"description":"...","qtype":"...","control":"...","qtext":"...","solution":"...",' .
        '"notes":"assumptions or things the author should double-check"}' . "\n\n" .
        "If libraries were selected, your control MUST begin with the matching loadlibrary(\"...\") line.\n\n" .
        "=== IMathAS QUESTION AUTHORING REFERENCE (CORE) ===\n" . aiReferenceCore();

    $blocks[] = aiSystemBlock($instructions, true);  // stable + shared → cache it

    // 2) UNCACHED: the answer-format reference for just this qtype.
    $qtypeRef = aiReferenceForQtype($qtype);
    if ($qtypeRef !== '') {
        $blocks[] = aiSystemBlock(
            "=== ANSWER FORMAT FOR THIS QUESTION TYPE (\"$qtype\") ===\n" . $qtypeRef);
    }

    // 3) UNCACHED: selected library help (small, varies per request).
    if (!empty($libs)) {
        $blocks[] = aiSystemBlock(aiLibraryHelpBlockText($libs));
    }

    return $blocks;
}

/** Concatenated cleaned help text for a set of libraries. */
function aiLibraryHelpBlockText($libs) {
    $out = "=== SELECTED MACRO LIBRARY REFERENCE ===\n";
    foreach ($libs as $lib) {
        $help = get_library_help($lib);
        if ($help !== '') {
            $out .= "\n## Library: $lib\n" . $help . "\n";
        }
    }
    return $out;
}

/** The user-turn prompt for generation. */
function aiGenerateUserPrompt($userprompt, $qtype, $libs) {
    $p = "Create an IMathAS question of type \"$qtype\".\n";
    if (!empty($libs)) {
        $p .= "Selected macro libraries: " . implode(', ', $libs) .
              " (begin Common Control with the matching loadlibrary line).\n";
    }
    $p .= "\nAuthor's request:\n" . $userprompt;
    return $p;
}

/**
 * Validate + normalize a generated question object. Returns an array with keys
 * qtype/control/qtext/solution/notes (strings), or null if the object is unusable.
 * The router's qtype is the authority; the model's echoed qtype is only a fallback.
 */
function aiNormalizeGeneratedDraft($json, $routedQtype) {
    if (!is_array($json)) { return null; }
    // Must have at least control + qtext to be a usable question.
    if (!isset($json['control']) && !isset($json['qtext'])) { return null; }

    $qtype = $routedQtype;
    if (!empty($json['qtype']) && in_array($json['qtype'], aiQuestionQtypes(), true)) {
        $qtype = $json['qtype'];
    }
    return array(
        'qtype'       => $qtype,
        'description' => isset($json['description']) ? (string)$json['description'] : '',
        'control'     => isset($json['control'])  ? (string)$json['control']  : '',
        'qtext'       => isset($json['qtext'])    ? (string)$json['qtext']    : '',
        'solution'    => isset($json['solution']) ? (string)$json['solution'] : '',
        'notes'       => isset($json['notes'])    ? (string)$json['notes']    : '',
    );
}

/**
 * Append a usage-log row when logging is on. Best-effort: a missing table (migration
 * not yet run) or any DB hiccup must never break the response.
 */
function ai_log($mode, $qtype, $libs, $prompt, $response, $retries, $usage, $verified = 0) {
    global $CFG, $DBH;
    if (empty($CFG['AI']['log'])) { return; }
    try {
        $stm = $DBH->prepare(
            "INSERT INTO imas_ai_authoring_log " .
            "(userid, mode, qtype, libs, prompt, response, verified, retries, tokens_in, tokens_out, created) " .
            "VALUES (:userid, :mode, :qtype, :libs, :prompt, :response, :verified, :retries, :tin, :tout, :created)"
        );
        $stm->execute(array(
            ':userid'  => (int)($_SESSION['userid'] ?? 0),
            ':mode'    => substr($mode, 0, 16),
            ':qtype'   => substr($qtype, 0, 20),
            ':libs'    => substr($libs, 0, 254),
            ':prompt'  => $prompt,
            ':response'=> $response,
            ':verified'=> (int)$verified,
            ':retries' => (int)$retries,
            ':tin'     => (int)($usage['in'] ?? 0),
            ':tout'    => (int)($usage['out'] ?? 0),
            ':created' => time(),
        ));
    } catch (Exception $e) {
        // swallow — logging is non-critical
    }
}
