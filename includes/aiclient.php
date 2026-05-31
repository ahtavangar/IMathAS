<?php
/**
 * aiclient.php — model-agnostic chat client for the AI Question Authoring feature.
 *
 * All AI logic in IMathAS funnels through aiChat(). The provider is selected by
 * $CFG['AI']['provider'] (default 'anthropic'); add a aiChat_<provider>() function
 * to support another backend. Nothing here knows about questions — it is a thin,
 * swappable transport that speaks chat-completions with prompt caching.
 *
 * Usage:
 *   require_once __DIR__ . '/aiclient.php';
 *   $res = aiChat(
 *     array(array('role' => 'user', 'content' => 'Say hello.')),
 *     array(
 *       'system' => array(
 *         aiSystemBlock($bigStableReference, true),   // cached prefix
 *         aiSystemBlock($smallDynamicSuffix),         // not cached
 *       ),
 *       'json'  => true,        // parse the model's reply as JSON into $res['json']
 *       'model' => $CFG['AI']['routermodel'],
 *     )
 *   );
 *   if ($res['ok']) { ... use $res['text'] / $res['json'] ... }
 *
 * Return shape (always an array):
 *   [
 *     'ok'    => bool,
 *     'text'  => string,          // assistant text (empty on error)
 *     'json'  => mixed|null,      // decoded JSON when 'json'=>true and parse succeeds
 *     'usage' => ['in'=>int, 'out'=>int, 'cache_read'=>int, 'cache_write'=>int],
 *     'error' => string|null,     // human-readable error when ok=false
 *     'raw'   => array|null,      // decoded provider response (for debugging/logging)
 *   ]
 */

if (!function_exists('aiClientEnabled')) {

/** True when the AI feature is configured and switched on. Gate all callers on this. */
function aiClientEnabled() {
    global $CFG;
    return !empty($CFG['AI']['enabled']) && !empty($CFG['AI']['apikey']);
}

/**
 * Per-instructor rollout gate (decision: AI is admin-flagged per instructor, not
 * network-wide). Returns true only when the feature is enabled AND this user is
 * permitted. Permission is granted by either:
 *   $CFG['AI']['allowall']   => true            (open to all teachers), or
 *   $CFG['AI']['allowusers'] => array(uid, ...) (explicit allowlist).
 * With neither set, no one sees it until an admin opts an instructor in.
 * Callers must still apply their own rights gate ($myrights >= min_rights).
 */
function aiUserAllowed($userid = null) {
    global $CFG;
    if (!aiClientEnabled()) { return false; }
    if (!empty($CFG['AI']['allowall'])) { return true; }
    if ($userid === null) {
        $userid = isset($_SESSION['userid']) ? $_SESSION['userid'] : 0;
    }
    if (!empty($CFG['AI']['allowusers']) && is_array($CFG['AI']['allowusers'])) {
        return in_array((int)$userid, array_map('intval', $CFG['AI']['allowusers']), true);
    }
    return false;
}

/**
 * Build a single system content block, optionally flagged for prompt caching.
 * Mark the large, stable prefix (the authoring reference) cacheable; leave small
 * per-request material uncached. $ttl may be '5m' (default) or '1h'.
 */
function aiSystemBlock($text, $cache = false, $ttl = '5m') {
    $block = array('type' => 'text', 'text' => (string)$text);
    if ($cache) {
        $cc = array('type' => 'ephemeral');
        if ($ttl === '1h') { $cc['ttl'] = '1h'; }
        $block['cache_control'] = $cc;
    }
    return $block;
}

/**
 * Main entry point. Dispatches to the configured provider.
 *
 * @param array $messages chat turns: [['role'=>'user'|'assistant', 'content'=>string], ...]
 * @param array $opts     system|model|maxtokens|temperature|json|timeout
 * @return array          see file header for shape
 */
function aiChat($messages, $opts = array()) {
    global $CFG;
    if (!aiClientEnabled()) {
        return aiChatError('AI feature is not enabled.');
    }
    $provider = isset($CFG['AI']['provider']) ? $CFG['AI']['provider'] : 'anthropic';
    switch ($provider) {
        case 'anthropic':
            return aiChat_anthropic($messages, $opts);
        default:
            return aiChatError('Unknown AI provider: ' . $provider);
    }
}

/** Uniform error result. */
function aiChatError($msg, $raw = null) {
    return array(
        'ok'    => false,
        'text'  => '',
        'json'  => null,
        'usage' => array('in' => 0, 'out' => 0, 'cache_read' => 0, 'cache_write' => 0),
        'error' => $msg,
        'raw'   => $raw,
    );
}

/**
 * Anthropic (Claude) Messages API implementation.
 * https://docs.anthropic.com/en/api/messages
 */
function aiChat_anthropic($messages, $opts) {
    global $CFG;

    $model     = !empty($opts['model'])     ? $opts['model']     : $CFG['AI']['model'];
    $maxtokens = !empty($opts['maxtokens']) ? (int)$opts['maxtokens']
                                            : (!empty($CFG['AI']['maxtokens']) ? (int)$CFG['AI']['maxtokens'] : 4096);
    $timeout   = !empty($opts['timeout'])   ? (int)$opts['timeout'] : 120;

    // Normalize the system param: accept a plain string or an array of blocks.
    $system = null;
    $usedExtendedTtl = false;
    if (isset($opts['system'])) {
        if (is_string($opts['system'])) {
            $system = array(aiSystemBlock($opts['system']));
        } else {
            $system = $opts['system'];
        }
        foreach ($system as $blk) {
            if (isset($blk['cache_control']['ttl']) && $blk['cache_control']['ttl'] === '1h') {
                $usedExtendedTtl = true;
            }
        }
    }

    $body = array(
        'model'      => $model,
        'max_tokens' => $maxtokens,
        'messages'   => aiNormalizeMessages($messages),
    );
    if ($system !== null)            { $body['system'] = $system; }
    if (isset($opts['temperature'])) { $body['temperature'] = (float)$opts['temperature']; }

    $apiurl = !empty($CFG['AI']['apiurl']) ? $CFG['AI']['apiurl']
                                           : 'https://api.anthropic.com/v1/messages';

    $headers = array(
        'x-api-key: ' . $CFG['AI']['apikey'],
        'anthropic-version: 2023-06-01',
        'content-type: application/json',
    );
    if ($usedExtendedTtl) {
        $headers[] = 'anthropic-beta: extended-cache-ttl-2025-04-11';
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiurl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    if (!empty($CFG['AI']['skipsslverify'])) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    }

    $result   = curl_exec($ch);
    $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlerr  = curl_error($ch);
    if (PHP_VERSION_ID >= 80000) { unset($ch); } else { curl_close($ch); }

    if ($result === false) {
        return aiChatError('Request to AI provider failed: ' . $curlerr);
    }

    $resp = json_decode($result, true);
    if ($resp === null) {
        return aiChatError('Could not decode AI provider response.', $result);
    }

    if ($httpcode < 200 || $httpcode >= 300 || isset($resp['error'])) {
        $msg = isset($resp['error']['message']) ? $resp['error']['message']
                                                : ('HTTP ' . $httpcode);
        return aiChatError('AI provider error: ' . $msg, $resp);
    }

    // Concatenate text blocks from the assistant reply.
    $text = '';
    if (!empty($resp['content']) && is_array($resp['content'])) {
        foreach ($resp['content'] as $blk) {
            if (isset($blk['type']) && $blk['type'] === 'text') {
                $text .= $blk['text'];
            }
        }
    }

    $usage = array(
        'in'         => isset($resp['usage']['input_tokens'])               ? (int)$resp['usage']['input_tokens'] : 0,
        'out'        => isset($resp['usage']['output_tokens'])              ? (int)$resp['usage']['output_tokens'] : 0,
        'cache_read' => isset($resp['usage']['cache_read_input_tokens'])    ? (int)$resp['usage']['cache_read_input_tokens'] : 0,
        'cache_write'=> isset($resp['usage']['cache_creation_input_tokens'])? (int)$resp['usage']['cache_creation_input_tokens'] : 0,
    );

    $json = null;
    if (!empty($opts['json'])) {
        $json = aiExtractJson($text);
    }

    return array(
        'ok'    => true,
        'text'  => $text,
        'json'  => $json,
        'usage' => $usage,
        'error' => null,
        'raw'   => $resp,
    );
}

/**
 * Coerce caller messages into the provider's expected shape. Each turn must have a
 * role ('user'/'assistant') and string content; anything malformed is skipped.
 */
function aiNormalizeMessages($messages) {
    $out = array();
    if (!is_array($messages)) { return $out; }
    foreach ($messages as $m) {
        if (!isset($m['role']) || !isset($m['content'])) { continue; }
        $role = ($m['role'] === 'assistant') ? 'assistant' : 'user';
        $out[] = array('role' => $role, 'content' => (string)$m['content']);
    }
    return $out;
}

/**
 * Best-effort JSON extraction from a model reply. Models sometimes wrap JSON in
 * ```json fences or add prose; pull the first balanced {...} or [...] and decode it.
 * Returns the decoded value, or null if nothing parses.
 */
function aiExtractJson($text) {
    $text = trim($text);
    if ($text === '') { return null; }

    $decoded = json_decode($text, true);
    if ($decoded !== null) { return $decoded; }

    // Strip a ```json ... ``` (or plain ```) fence if present.
    if (preg_match('/```(?:json)?\s*(.*?)```/s', $text, $m)) {
        $decoded = json_decode(trim($m[1]), true);
        if ($decoded !== null) { return $decoded; }
        $text = trim($m[1]);
    }

    // Fall back to the first balanced object/array span.
    $start = null; $startChar = null;
    $objPos = strpos($text, '{');
    $arrPos = strpos($text, '[');
    if ($objPos === false && $arrPos === false) { return null; }
    if ($arrPos === false || ($objPos !== false && $objPos < $arrPos)) {
        $start = $objPos; $open = '{'; $close = '}';
    } else {
        $start = $arrPos; $open = '['; $close = ']';
    }

    $depth = 0; $inStr = false; $esc = false; $len = strlen($text);
    for ($i = $start; $i < $len; $i++) {
        $c = $text[$i];
        if ($inStr) {
            if ($esc) { $esc = false; }
            elseif ($c === '\\') { $esc = true; }
            elseif ($c === '"') { $inStr = false; }
        } else {
            if ($c === '"') { $inStr = true; }
            elseif ($c === $open) { $depth++; }
            elseif ($c === $close) {
                $depth--;
                if ($depth === 0) {
                    $candidate = substr($text, $start, $i - $start + 1);
                    return json_decode($candidate, true);
                }
            }
        }
    }
    return null;
}

} // function_exists guard
