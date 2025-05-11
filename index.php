<?php
/******************************************************************
 * LimeSurvey — Live Leaderboard
 * --------------------------------------------------------------
 * Version : 2.4 (Auth‑source aware, PHP 7.0‑compatible)
 * Updated : 2025‑05‑09
 *
 * • Retrieves survey results through the RemoteControl 2 API
 * • Supports optional 3rd‑parameter auth source (AuthLDAP, AuthCAS…)
 * • Uses an external configuration file (config.php or env‑vars)
 ******************************************************************/

// 1. INCLUDE CONFIGURATION FILE ------------------------------------------------
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} // if file missing we still proceed: all constants may come from env‑vars via entrypoint

// 2. VERIFY REQUIRED CONSTANTS --------------------------------------------------
$required_constants = [
    'LIMESURVEY_API_URL', 'LIMESURVEY_USERNAME', 'LIMESURVEY_PASSWORD',
    'LIMESURVEY_SURVEY_ID', 'COLUMN_NICKNAME', 'COLUMN_SCORE'
];
foreach ($required_constants as $constant) {
    if (!defined($constant)) {
        http_response_code(500);
        error_log("Critical Error: Config constant '$constant' not defined.");
        exit('Server configuration error. Missing setting: ' . htmlspecialchars($constant));
    }
}
// Optional constant: LIMESURVEY_AUTH_SOURCE ( e.g. AuthLDAP, AuthCAS, AuthSAML )
if (!defined('LIMESURVEY_AUTH_SOURCE')) {
    define('LIMESURVEY_AUTH_SOURCE', ''); // empty string → default internal DB auth
}

// -----------------------------------------------------------------------------
$errorMessage = null;
$entries      = [];

/** Executes a JSON‑RPC request against LimeSurvey. */
function call_limesurvey_api(string $apiUrl, array $payload): array
{
    if (!filter_var($apiUrl, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Invalid API URL: ' . htmlspecialchars($apiUrl));
    }
    $payload = array_merge(['jsonrpc' => '2.0', 'id' => random_int(1, 1000000)], $payload);
    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 25,
        CURLOPT_CONNECTTIMEOUT => 15,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($err)  throw new RuntimeException('cURL error: ' . $err);
    if ($code !== 200) throw new RuntimeException('HTTP status ' . $code . ': ' . substr($raw ?: '', 0, 400));
    $resp = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('JSON decode error: ' . json_last_error_msg());
    if (isset($resp['error'])) throw new RuntimeException('API error: ' . json_encode($resp['error']));
    return $resp;
}

try {
    // 3. AUTHENTICATION --------------------------------------------------------
    $params = [LIMESURVEY_USERNAME, LIMESURVEY_PASSWORD];
    if (LIMESURVEY_AUTH_SOURCE !== '') {
        $params[] = LIMESURVEY_AUTH_SOURCE; // e.g. AuthLDAP
    }

    $login = call_limesurvey_api(LIMESURVEY_API_URL, [
        'method' => 'get_session_key',
        'params' => $params,
    ]);
    $sessionKey = $login['result'] ?? null;
    if (!$sessionKey) throw new RuntimeException('Unable to obtain session key. Check credentials and auth source.');

    try {
        // 4. EXPORT RESPONSES ---------------------------------------------------
        $fields = array_unique(array_filter([COLUMN_NICKNAME, COLUMN_SCORE]));
        $export = call_limesurvey_api(LIMESURVEY_API_URL, [
            'method' => 'export_responses',
            'params' => [
                $sessionKey,
                LIMESURVEY_SURVEY_ID,
                'json',
                null,
                'all',
                'code',
                'long',
                null,
                null,
                empty($fields) ? null : $fields,
            ],
        ]);

        // 5. DECODE DATA --------------------------------------------------------
        if (!isset($export['result']) || !is_string($export['result'])) {
            $dump = json_encode($export['result']);
            throw new RuntimeException('Unexpected result type from LimeSurvey (expect base64 string). Payload: ' . substr($dump, 0, 300));
        }
        $jsonData = base64_decode($export['result'], true);
        if ($jsonData === false) throw new RuntimeException('Base64 decode failed.');
        $data = json_decode($jsonData, true);
        if (json_last_error() !== JSON_ERROR_NONE) throw new RuntimeException('JSON decode failed: ' . json_last_error_msg());

        // 6. BUILD LEADERBOARD --------------------------------------------------
        $raw = [];
        if (isset($data['responses'])) {
            foreach ($data['responses'] as $set) foreach ($set as $row) if (is_array($row)) $raw[] = $row;
        } elseif (is_array($data)) {
            $raw = $data;
        }
        foreach ($raw as $row) {
            $nick = $row[COLUMN_NICKNAME] ?? null;
            $score = $row[COLUMN_SCORE] ?? null;
            if ($nick !== null && trim($nick) !== '' && is_numeric($score)) {
                $entries[] = ['nickname' => htmlspecialchars(trim($nick)), 'score' => (int)$score];
            }
        }
        if ($entries) usort($entries, fn($a,$b)=>$b['score']<=>$a['score']);
        else $errorMessage = 'No valid scores to display yet.';
    } finally {
        // always release session key
        call_limesurvey_api(LIMESURVEY_API_URL, ['method'=>'release_session_key','params'=>[$sessionKey]]);
    }
} catch (Exception $e) {
    error_log('Leaderboard runtime error: '.$e->getMessage());
    $errorMessage = 'Failed to retrieve/process data. (Msg: '.htmlspecialchars($e->getMessage()).')';
}

// -----------------------------------------------------------------------------
$leaderboardTitle  = defined('LEADERBOARD_TITLE')  ? htmlspecialchars(LEADERBOARD_TITLE)  : 'Live Leaderboard';
$searchPlaceholder = defined('SEARCH_PLACEHOLDER') ? htmlspecialchars(SEARCH_PLACEHOLDER) : 'Search by nickname...';
$noResultsMessage  = defined('NO_RESULTS_MESSAGE') ? htmlspecialchars(NO_RESULTS_MESSAGE) : 'No nickname found.';
$pointsSuffix      = defined('POINTS_SUFFIX')      ? htmlspecialchars(POINTS_SUFFIX)      : 'points';
$noScoresMessage   = defined('NO_SCORES_MESSAGE')  ? htmlspecialchars(NO_SCORES_MESSAGE)  : 'No valid scores yet.';
?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $leaderboardTitle ?></title>
<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;background:#f0f4f8;margin:0;padding:20px;color:#333} .container{max-width:700px;margin:20px auto;background:#fff;padding:20px 30px;border-radius:12px;box-shadow:0 6px 18px rgba(0,0,0,.1)} h1{text-align:center;font-size:2.3em;color:#2c3e50} #search{width:100%;margin:0 0 25px;padding:12px 18px;font-size:1.05em;border:1px solid #d1d9e0;border-radius:8px;box-sizing:border-box} ol#leaderboard{padding:0;list-style:none} #leaderboard li{background:#fdfdfd;margin-bottom:12px;padding:15px 20px;border-radius:8px;border:1px solid #e9edf0;display:flex;justify-content:space-between;align-items:center} #leaderboard li:nth-child(1){background:#fff8e1;border-left:6px solid #ffd54f} #leaderboard li:nth-child(2){background:#f5f5f5;border-left:6px solid silver} #leaderboard li:nth-child(3){background:#fff0e6;border-left:6px solid #ffab91} .status-message{background:#e9ecef;padding:15px 20px;border-radius:8px;text-align:center;border:1px solid #ced4da;margin-bottom:20px}</style></head><body>
<div class="container">
<h1><?= $leaderboardTitle ?></h1>
<?php if($errorMessage):?><div class="status-message"><?= htmlspecialchars($errorMessage) ?></div><?php endif;?>
<?php if(!$errorMessage && $entries):?>
<input id="search" type="text" placeholder="<?= $searchPlaceholder ?>" aria-label="Search leaderboard"> <ol id="leaderboard">
<?php foreach($entries as $i=>$e):$rank=$i+1;$medal=$rank<=3?['🥇','🥈','🥉'][$rank-1]:'#'.$rank;?>
<li><span><?= $medal ?></span><span><?= $e['nickname'] ?></span><span><?= $e['score'].' '.$pointsSuffix ?></span></li>
<?php endforeach;?></ol><p id="no-results" class="status-message" style="display:none;"><?= $noResultsMessage ?></p>
<?php elseif(!$errorMessage):?><div class="status-message"><?= $noScoresMessage ?></div><?php endif;?></div>
<?php if(!$errorMessage && $entries):?>
<script>(function(){var s=document.getElementById('search');var items=[...document.querySelectorAll('#leaderboard li')];s.addEventListener('input',function(){var q=s.value.toLowerCase().trim(),vis=0;items.forEach(function(it){var m=it.children[1].textContent.toLowerCase().includes(q);it.style.display=m?'':'none';if(m)vis++;});document.getElementById('no-results').style.display=q&&vis===0?'block':'none';});})();</script>
<?php endif;?></body></html>
