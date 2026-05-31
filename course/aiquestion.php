<?php
/**
 * aiquestion.php — AI Question Authoring endpoint (JSON).
 *
 * Modes:
 *   fix      — explain / diagnose the current draft and suggest fixes (Phase 1).
 *   generate — natural-language → full question (Phase 4, not yet implemented).
 *   solution — draft a detailed solution (Phase 5, not yet implemented).
 *
 * Consumed by the legacy editor course/moddataset.php via vanilla fetch().
 * Returns JSON only. Gated on the AI feature being configured + teacher rights.
 *
 * See .claude/features/ai-question-authoring-plan.md.
 */

require_once "../init.php";
require_once "../includes/aiclient.php";

header('Content-Type: application/json; charset=utf-8');

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

$fields = array(
    'qtype'     => preg_replace('/[^a-zA-Z0-9_]/', '', ai_field('qtype', 40)),
    'control'   => ai_field('control', $MAXFIELD),
    'answerbox' => ai_field('answerbox', $MAXFIELD),   // combined Question Control + Answer
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

ai_log($mode, $fields['qtype'], '', $result['promptlog'], $result['text'],
       0, $result['usage']);

echo json_encode(array(
    'success' => true,
    'mode'    => $mode,
    'text'    => $result['text'],
));
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
        "- Common Control: PHP-like setup code that defines randomized variables (\$a, \$b, ...) " .
        "and may call loadlibrary(\"...\") and randomizers (rand, shuffle, ...).\n" .
        "- Question Text (qtext): what the student sees; references variables as \$a and answer " .
        "boxes as [AB1], [AB2], ....\n" .
        "- Answer area: per-answer-box settings (\$answerbox / \$answer / \$answertype / etc.) plus " .
        "the correct answer(s).\n" .
        "- Detailed Solution: optional worked explanation.\n\n" .
        "Diagnose the draft below. Explain briefly what it does, then list any bugs, undefined " .
        "variables, mismatched answer boxes, library calls that are missing/misused, or likely " .
        "evaluation errors — each with a concrete, minimal fix. If something looks fine, say so. " .
        "Be specific and concise. Do NOT output a full rewritten question; the author will apply " .
        "changes themselves. Use plain text with short headers and bullet points.";

    $draft = "qtype: " . ($fields['qtype'] === '' ? '(unset)' : $fields['qtype']) . "\n\n" .
             "=== Common Control ===\n"   . rtrim($fields['control'])   . "\n\n" .
             "=== Question Text ===\n"     . rtrim($fields['qtext'])     . "\n\n" .
             "=== Answer (Question Control + Answer) ===\n" . rtrim($fields['answerbox']) . "\n\n" .
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

    $res['promptlog'] = $user;   // store the variable part; system prompt is static
    return $res;
}

/**
 * Append a usage-log row when logging is on. Best-effort: a missing table (migration
 * not yet run) or any DB hiccup must never break the response.
 */
function ai_log($mode, $qtype, $libs, $prompt, $response, $retries, $usage) {
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
            ':verified'=> 0,
            ':retries' => (int)$retries,
            ':tin'     => (int)($usage['in'] ?? 0),
            ':tout'    => (int)($usage['out'] ?? 0),
            ':created' => time(),
        ));
    } catch (Exception $e) {
        // swallow — logging is non-critical
    }
}
