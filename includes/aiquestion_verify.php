<?php
/**
 * aiquestion_verify.php — Phase 3 of the pipeline: headless verification loop.
 *
 * Runs an AI-generated (or author-edited) question draft through the real
 * AssessStandalone evaluator across several random seeds, with no browser and no
 * stored question, and reports any errors. This is the reliability mechanism: a
 * draft that renders + scores cleanly across seeds is far more trustworthy than
 * raw model output. Mirrors the live preview path in
 * course/testquestion2.php:147-204.
 *
 * aiVerifyDraft() is a plain callable so the orchestrator (and a future agent)
 * can drive the verify→re-prompt cycle. The library-recovery hint (undefined
 * function → which library to load) is computed here but acted on by the caller.
 *
 * Safety: generated code is ONLY ever executed inside AssessStandalone — never
 * eval'd directly. Each run is wrapped in an output buffer + a scoped error
 * handler so question code cannot emit to the page or surface a warning as a
 * fatal. Requires init.php to have been loaded (for $DBH, the assess2 stack).
 */

require_once __DIR__ . '/aiquestion_libs.php';
require_once dirname(__DIR__) . '/assess2/AssessStandalone.php';

if (!function_exists('aiVerifyDraft')) {

/**
 * Verify a question draft headlessly.
 *
 * @param array $fields  draft fields: qtype, control, qcontrol, qtext, answer,
 *                       solution (any missing default to '').
 * @param array $opts    'seeds' => int (default $CFG['AI']['verify_seeds'] or 3),
 *                        'fixedseeds' => array<int> to use specific seeds.
 * @return array {
 *     'ok'      => bool,                 // true if no errors across all seeds
 *     'errors'  => string[],             // de-duplicated error messages
 *     'seeds'   => int[],                // seeds actually run
 *     'missing_libraries' => string[],   // libs implicated by undefined-fn errors
 *                                        //   that aren't already loaded (recovery)
 *     'seseeds_with_errors' => int,      // count of seeds that produced errors
 * }
 */
function aiVerifyDraft($fields, $opts = array()) {
    global $DBH, $CFG;

    $numSeeds = isset($opts['seeds']) ? (int)$opts['seeds']
              : (isset($CFG['AI']['verify_seeds']) ? (int)$CFG['AI']['verify_seeds'] : 3);
    if ($numSeeds < 1) { $numSeeds = 1; }

    if (!empty($opts['fixedseeds']) && is_array($opts['fixedseeds'])) {
        $seeds = array_map('intval', array_values($opts['fixedseeds']));
    } else {
        $seeds = array();
        // Deterministic-ish spread plus randomness, to catch edge cases.
        for ($i = 0; $i < $numSeeds; $i++) {
            $seeds[] = ($i === 0) ? 1 : mt_rand(2, 99999);
        }
    }

    $row = aiBuildDraftRow($fields);
    $qn  = 27; // testing question number, matching testquestion2.php

    $allErrors = array();
    $seedsWithErrors = 0;

    foreach ($seeds as $seed) {
        $errs = aiVerifyOneSeed($DBH, $row, $qn, $seed);
        if (!empty($errs)) {
            $seedsWithErrors++;
            foreach ($errs as $e) {
                $allErrors[] = $e;
            }
        }
    }

    // De-duplicate while preserving order.
    $seen = array();
    $errors = array();
    foreach ($allErrors as $e) {
        $key = trim($e);
        if ($key === '' || isset($seen[$key])) { continue; }
        $seen[$key] = true;
        $errors[] = $e;
    }

    $loaded = aiLoadedLibraries($fields['control'] ?? '');
    $missing = aiMissingLibrariesFromErrors($errors, $loaded);

    return array(
        'ok'                 => (count($errors) === 0),
        'errors'             => $errors,
        'seeds'              => $seeds,
        'missing_libraries'  => $missing,
        'seeds_with_errors'  => $seedsWithErrors,
    );
}

/**
 * Run one seed through display + score inside a sandbox; return error strings.
 */
function aiVerifyOneSeed($DBH, $row, $qn, $seed) {
    $errors = array();

    // Scoped error handler: capture warnings/notices the question code emits
    // (undefined functions surface as E_WARNING/E_ERROR via the evaluator, but
    // many control-eval issues come through as PHP warnings).
    $captured = array();
    set_error_handler(function ($errno, $errstr) use (&$captured) {
        $captured[] = $errstr;
        return true; // swallow — don't bubble to PHP's handler / page output
    });
    ob_start();

    try {
        $state = array(
            'seeds'          => array($qn => $seed),
            'qsid'           => array($qn => $row['id']),
            'stuanswers'     => array(),
            'stuanswersval'  => array(),
            'scorenonzero'   => array(($qn + 1) => -1),
            'scoreiscorrect' => array(($qn + 1) => -1),
            'partattemptn'   => array($qn => array()),
            'rawscores'      => array($qn => array()),
        );

        $a2 = new AssessStandalone($DBH);
        $a2->setQuestionData($row['id'], $row);
        $a2->setState($state);

        // Display surfaces control/qcontrol/qtext evaluation errors.
        $disp = $a2->displayQuestion($qn, array('includeans' => true));
        if (!empty($disp['errors']) && is_array($disp['errors'])) {
            foreach ($disp['errors'] as $e) { $errors[] = (string)$e; }
        }

        // Score with empty answers surfaces answer-eval errors.
        $res = $a2->scoreQuestion($qn, true);
        if (!empty($res['errors']) && is_array($res['errors'])) {
            foreach ($res['errors'] as $e) { $errors[] = (string)$e; }
        }
    } catch (Throwable $t) {
        $errors[] = 'Exception: ' . $t->getMessage();
    }

    ob_end_clean();
    restore_error_handler();

    // Fold in captured PHP warnings that look like real problems.
    foreach ($captured as $w) {
        if (aiIsMeaningfulWarning($w)) {
            $errors[] = $w;
        }
    }

    return $errors;
}

/**
 * Build a complete imas_questionset-shaped row from draft fields, so
 * AssessStandalone sees the same columns it would from the DB. id=0 is a
 * synthetic, never-stored id.
 */
function aiBuildDraftRow($fields) {
    $g = function ($k) use ($fields) {
        return isset($fields[$k]) ? (string)$fields[$k] : '';
    };
    return array(
        'id'              => 0,
        'qtype'           => $g('qtype'),
        'control'         => $g('control'),
        'qcontrol'        => $g('qcontrol'),
        'qtext'           => $g('qtext'),
        'answer'          => $g('answer'),
        'solution'        => $g('solution'),
        'solutionopts'    => 0,
        'hasimg'          => 0,
        'a11yalttype'     => 0,
        'a11yalt'         => 0,
        'extref'          => '',
        'ancestors'       => '',
        'ancestorauthors' => '',
        'license'         => 0,
        'description'     => '',
        'ownerid'         => 0,
        'author'          => '',
        'userights'       => 0,
        'isrand'          => 1,
        'deleted'         => 0,
    );
}

/** Which libraries the control code already loads via loadlibrary(...). */
function aiLoadedLibraries($control) {
    $loaded = array();
    if (preg_match_all('/loadlibrary\s*\(\s*["\']([^"\']+)["\']/i', (string)$control, $m)) {
        foreach ($m[1] as $list) {
            foreach (explode(',', $list) as $name) {
                $name = trim($name);
                if ($name !== '') { $loaded[] = $name; }
            }
        }
    }
    return array_unique($loaded);
}

/**
 * Map "undefined function foo()" errors to the macro library that defines foo(),
 * excluding libraries already loaded. Drives the verify-recovery retry: the
 * orchestrator fetches help for these and re-prompts. Returns library names.
 */
function aiMissingLibrariesFromErrors($errors, $loadedLibs) {
    $fns = array();
    foreach ($errors as $e) {
        // IMathAS blocks functions from unloaded libraries before PHP sees them,
        // reporting "Eeek.. unallowed macro <name>" (interpret5.php / mathphp2.php).
        // Also handle the raw PHP shape in case it slips through.
        if (preg_match_all('/unallowed macro\s+([A-Za-z_][A-Za-z0-9_]*)/i', $e, $m)) {
            foreach ($m[1] as $fn) { $fns[strtolower($fn)] = true; }
        }
        if (preg_match_all('/undefined function:?\s*([A-Za-z_][A-Za-z0-9_]*)/i', $e, $m)) {
            foreach ($m[1] as $fn) { $fns[strtolower($fn)] = true; }
        }
    }
    if (empty($fns)) { return array(); }

    $loadedSet = array();
    foreach ($loadedLibs as $l) { $loadedSet[$l] = true; }

    $missing = array();
    foreach (aiKnownLibraries() as $lib) {
        if (isset($loadedSet[$lib])) { continue; }
        if (aiLibraryDefinesAnyFunction($lib, $fns) && !in_array($lib, $missing, true)) {
            $missing[] = $lib;
        }
    }
    return $missing;
}

/**
 * True if a macro library's .php source defines any of the given function names.
 * Functions in IMathAS libs are declared as `function name(` at top level.
 * Cached per library per request.
 */
function aiLibraryDefinesAnyFunction($lib, $fnSet) {
    static $cache = array();
    if (!isset($cache[$lib])) {
        $path = aiLibsDir() . '/' . $lib . '.php';
        $defined = array();
        if (is_readable($path)) {
            $src = file_get_contents($path);
            if (preg_match_all('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $src, $m)) {
                foreach ($m[1] as $fn) { $defined[strtolower($fn)] = true; }
            }
        }
        $cache[$lib] = $defined;
    }
    foreach ($fnSet as $fn => $_) {
        if (isset($cache[$lib][$fn])) { return true; }
    }
    return false;
}

/**
 * Filter out PHP warnings that are noise rather than question defects. We only
 * want things that indicate the draft itself is broken.
 */
function aiIsMeaningfulWarning($w) {
    $w = trim($w);
    if ($w === '') { return false; }
    $meaningful = array(
        'undefined function', 'undefined variable', 'undefined array key',
        'undefined index', 'division by zero', 'too few arguments',
        'must be of type', 'unsupported operand', 'syntax error',
        'call to a member function', 'trying to access array offset',
        'foreach() argument', 'null given', 'unallowed macro',
    );
    $lw = strtolower($w);
    foreach ($meaningful as $needle) {
        if (strpos($lw, $needle) !== false) { return true; }
    }
    return false;
}

} // function_exists guard
