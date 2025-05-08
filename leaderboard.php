<?php
/******************************************************************
 * LimeSurvey — Live Leaderboard
 * --------------------------------------------------------------
 * Version : 2.3 (stable, PHP 7.0 compatible)
 * Updated : 2025‑05‑08
 *
 * • Retrieves survey results through the RemoteControl 2 API
 * • Decodes the Base64‑encoded JSON into a PHP array
 * • Displays a real‑time leaderboard with a client‑side filter
 * • Uses an external configuration file (config.php)
 ******************************************************************/

// 1. CONFIGURATION (LOCAL or ENV) ---------------------------------------------
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    define('LIMESURVEY_API_URL', getenv('LIMESURVEY_API_URL'));
    define('LIMESURVEY_USERNAME', getenv('LIMESURVEY_USERNAME'));
    define('LIMESURVEY_PASSWORD', getenv('LIMESURVEY_PASSWORD'));
    define('LIMESURVEY_SURVEY_ID', getenv('LIMESURVEY_SURVEY_ID'));

    define('COLUMN_NICKNAME', getenv('COLUMN_NICKNAME'));
    define('COLUMN_SCORE', getenv('COLUMN_SCORE'));

    define('LEADERBOARD_TITLE', getenv('LEADERBOARD_TITLE') ?: '🏆 Live Leaderboard');
    define('SEARCH_PLACEHOLDER', getenv('SEARCH_PLACEHOLDER') ?: 'Search by nickname...');
    define('NO_RESULTS_MESSAGE', getenv('NO_RESULTS_MESSAGE') ?: 'No nickname found.');
    define('POINTS_SUFFIX', getenv('POINTS_SUFFIX') ?: 'points');
}

// 2. VERIFY REQUIRED CONSTANTS --------------------------------------------------
$required_constants = [
    'LIMESURVEY_API_URL', 'LIMESURVEY_USERNAME', 'LIMESURVEY_PASSWORD',
    'LIMESURVEY_SURVEY_ID', 'COLUMN_NICKNAME', 'COLUMN_SCORE'
];
foreach ($required_constants as $constant) {
    if (!defined($constant)) {
        http_response_code(500);
        error_log("Critical Error: Configuration constant '$constant' is not defined in 'config.php'.");
        exit('Server configuration error. Missing setting: ' . htmlspecialchars($constant) . '. Please contact the administrator.');
    }
}

// -----------------------------------------------------------------------------
// INITIAL SETUP
// -----------------------------------------------------------------------------
$errorMessage = null;
$entries      = [];

/**
 * Executes a JSON‑RPC request against the LimeSurvey API.
 *
 * @param string $apiUrl  LimeSurvey API endpoint.
 * @param array  $payload JSON‑RPC payload.
 * @return array         Decoded response.
 * @throws RuntimeException         For cURL/HTTP/API errors.
 * @throws InvalidArgumentException For invalid URL.
 */
function call_limesurvey_api(string $apiUrl, array $payload): array
{
    if (!filter_var($apiUrl, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Invalid API URL supplied: ' . htmlspecialchars($apiUrl));
    }

    $payload = array_merge([
        'jsonrpc' => '2.0',
        // Plain integer literal for broader compatibility (underscores require PHP 7.4+)
        'id'      => random_int(1, 1000000),
    ], $payload);

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

    $rawResponse      = curl_exec($ch);
    $curlErrorNumber  = curl_errno($ch);
    $curlErrorMessage = curl_error($ch);
    $httpStatusCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($curlErrorNumber) {
        throw new RuntimeException('cURL error (' . $curlErrorNumber . '): ' . htmlspecialchars($curlErrorMessage));
    }
    if ($httpStatusCode !== 200) {
        throw new RuntimeException('HTTP server error: Status ' . $httpStatusCode . '. Response: ' . htmlspecialchars(substr($rawResponse ?: '', 0, 500)));
    }
    if ($rawResponse === false || $rawResponse === '') {
        throw new RuntimeException('Empty or invalid API response received.');
    }

    $response = json_decode($rawResponse, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new RuntimeException('Failed to decode JSON response: ' . json_last_error_msg());
    }
    if (isset($response['error'])) {
        $apiError = is_array($response['error']) ? ($response['error']['message'] ?? json_encode($response['error'])) : $response['error'];
        throw new RuntimeException('LimeSurvey API error: ' . htmlspecialchars($apiError));
    }

    return $response;
}

try {
    // -------------------------------------------------------------------------
    // 3. AUTHENTICATION
    // -------------------------------------------------------------------------
    $loginResponse = call_limesurvey_api(LIMESURVEY_API_URL, [
        'method' => 'get_session_key',
        'params' => [LIMESURVEY_USERNAME, LIMESURVEY_PASSWORD],
    ]);
    $sessionKey = isset($loginResponse['result']) ? $loginResponse['result'] : null;
    if (!$sessionKey) {
        throw new RuntimeException('Unable to obtain session key. Check your credentials, API URL and user permissions in config.php.');
    }

    try {
        // ---------------------------------------------------------------------
        // 4. EXPORT RESPONSES
        // ---------------------------------------------------------------------
        $fieldsToExport = array_unique(array_filter([COLUMN_NICKNAME, COLUMN_SCORE]));

        $exportResponse = call_limesurvey_api(LIMESURVEY_API_URL, [
            'method' => 'export_responses',
            'params' => [
                $sessionKey,                     // 1 Session Key
                LIMESURVEY_SURVEY_ID,            // 2 Survey ID
                'json',                          // 3 Document type
                null,                            // 4 Language code (all)
                'all',                           // 5 Completion status
                'code',                          // 6 Heading type
                'long',                          // 7 Response type
                null,                            // 8 From response id
                null,                            // 9 To response id
                empty($fieldsToExport) ? null : $fieldsToExport, // 10 Fields
            ],
        ]);

        // ---------------------------------------------------------------------
        // 5. DECODE DATA
        // ---------------------------------------------------------------------
        $base64EncodedData = isset($exportResponse['result']) ? $exportResponse['result'] : '';
        if ($base64EncodedData === '') {
            $errorMessage = 'No response data received. The survey may be empty or the filters returned no data.';
        } else {
            $jsonData = base64_decode($base64EncodedData, true);
            if ($jsonData === false) {
                throw new RuntimeException('Failed to Base64‑decode the response data.');
            }

            $responseData = json_decode($jsonData, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException('Failed to decode JSON response data: ' . json_last_error_msg());
            }

            $rawEntriesData = [];

            if (isset($responseData['responses']) && is_array($responseData['responses'])) {
                // Nested structure
                foreach ($responseData['responses'] as $responseSet) {
                    foreach ($responseSet as $responseRecord) {
                        if (is_array($responseRecord)) {
                            $rawEntriesData[] = $responseRecord;
                        }
                    }
                }
            } elseif (is_array($responseData)) {
                // Flat structure
                $rawEntriesData = $responseData;
            } else {
                throw new RuntimeException('Unrecognised response data format.');
            }

            // -----------------------------------------------------------------
            // 6. BUILD LEADERBOARD
            // -----------------------------------------------------------------
            foreach ($rawEntriesData as $row) {
                $nickname = isset($row[COLUMN_NICKNAME]) ? $row[COLUMN_NICKNAME] : null;
                $score    = isset($row[COLUMN_SCORE])    ? $row[COLUMN_SCORE]    : null;
                if ($nickname !== null && trim((string)$nickname) !== '' && is_numeric($score)) {
                    $entries[] = [
                        'nickname' => htmlspecialchars(trim((string)$nickname), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                        'score'    => (int)$score,
                    ];
                }
            }

            if (empty($entries) && !empty($rawEntriesData)) {
                $errorMessage = 'Responses found but none contain valid nickname/score data (check COLUMN_NICKNAME / COLUMN_SCORE in config.php).';
            } elseif (empty($entries) && empty($rawEntriesData) && $errorMessage === null) {
                $errorMessage = 'No responses found or none matched the criteria.';
            }

            if (!empty($entries)) {
                usort($entries, function ($a, $b) {
                    if ($a['score'] === $b['score']) return 0;
                    return ($a['score'] < $b['score']) ? 1 : -1; // Descending
                });
            }
        }
    } finally {
        // ---------------------------------------------------------------------
        // 7. LOGOUT (ALWAYS EXECUTE)
        // ---------------------------------------------------------------------
        if (isset($sessionKey)) {
            try {
                call_limesurvey_api(LIMESURVEY_API_URL, [
                    'method' => 'release_session_key',
                    'params' => [$sessionKey],
                ]);
            } catch (Exception $logoutException) {
                error_log('Warning: Failed to release LimeSurvey session key: ' . $logoutException->getMessage());
            }
        }
    }
} catch (InvalidArgumentException $e) {
    error_log('Leaderboard configuration error: ' . $e->getMessage());
    $errorMessage = 'Configuration error. (Ref: ' . htmlspecialchars($e->getMessage()) . ')';
} catch (RuntimeException $e) {
    error_log('Leaderboard runtime error: ' . $e->getMessage());
    $errorMessage = 'Failed to retrieve/process data. Try again later. (Msg: ' . htmlspecialchars(substr($e->getMessage(), 0, 150)) . '...)';
} catch (Exception $e) {
    error_log('Unexpected leaderboard error: ' . $e->getMessage());
    $errorMessage = 'Unexpected error. Please contact support.';
}

// -----------------------------------------------------------------------------
// TEXT STRINGS (override in config.php)
// -----------------------------------------------------------------------------
$leaderboardTitle  = defined('LEADERBOARD_TITLE')  ? htmlspecialchars(LEADERBOARD_TITLE)  : 'Live Leaderboard';
$searchPlaceholder = defined('SEARCH_PLACEHOLDER') ? htmlspecialchars(SEARCH_PLACEHOLDER) : 'Search by nickname...';
$noResultsMessage  = defined('NO_RESULTS_MESSAGE') ? htmlspecialchars(NO_RESULTS_MESSAGE) : 'No nickname found.';
$pointsSuffix      = defined('POINTS_SUFFIX')      ? htmlspecialchars(POINTS_SUFFIX)      : 'points';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $leaderboardTitle ?></title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; background-color: #f0f4f8; margin: 0; padding: 20px; color: #333; line-height: 1.6; }
        .container { max-width: 700px; margin: 20px auto; background-color: #fff; padding: 20px 30px; border-radius: 12px; box-shadow: 0 6px 18px rgba(0,0,0,0.1); }
        h1 { margin-top: 0; margin-bottom: 25px; text-align: center; font-size: 2.3em; color: #2c3e50; font-weight: 600; }
        #search { display: block; width: 100%; margin: 0 auto 25px; padding: 12px 18px; font-size: 1.05em; border: 1px solid #d1d9e0; border-radius: 8px; box-sizing: border-box; transition: border-color 0.2s, box-shadow 0.2s; }
        #search:focus { border-color: #007bff; outline: 0; box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25); }
        ol#leaderboard { padding: 0; list-style: none; }
        #leaderboard li { background-color: #fdfdfd; margin-bottom: 12px; padding: 15px 20px; border-radius: 8px; border: 1px solid #e9edf0; display: flex; justify-content: space-between; align-items: center; transition: background-color 0.2s, transform 0.15s; }
        #leaderboard li:hover { transform: translateY(-2px); box-shadow: 0 4px 10px rgba(0,0,0,0.08); }
        #leaderboard li:nth-child(1) { background-color: #fff8e1; border-left: 6px solid #ffd54f; }
        #leaderboard li:nth-child(2) { background-color: #f5f5f5; border-left: 6px solid silver; }
        #leaderboard li:nth-child(3) { background-color: #fff0e6; border-left: 6px solid #ffab91; }
        #leaderboard li .rank-medal { font-size: 1.6em; margin-right: 18px; min-width: 40px; text-align: center; color: #555; }
        #leaderboard li .nickname { font-weight: 500; color: #343a40; flex-grow: 1; padding-right: 15px; word-break: break-word; }
        #leaderboard li .score { font-weight: 700; font-size: 1.15em; color: #0056b3; white-space: nowrap; }
        #leaderboard li.highlight { background-color: #e6f7ff !important; border-color: #91d5ff !important; transform: scale(1.01); }
        .status-message { background-color: #e9ecef; color: #495057; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; text-align: center; border: 1px solid #ced4da; }
        .error-message { background-color: #f8d7da; color: #721c24; border-color: #f5c6cb; }
        #no-results { display: none; }
    </style>
</head>
<body>
    <div class="container">
        <h1><?= $leaderboardTitle ?></h1>
        <?php if ($errorMessage): ?>
            <div class="status-message error-message"><?= htmlspecialchars($errorMessage) ?></div>
        <?php endif; ?>

        <?php if (!$errorMessage && !empty($entries)): ?>
            <input type="text" id="search" placeholder="<?= $searchPlaceholder ?>" aria-label="Filter leaderboard by nickname">
            <ol id="leaderboard">
                <?php foreach ($entries as $index => $entry):
                    $rank = $index + 1;
                    $medal = '';
                    if ($rank === 1) { $medal = '🥇'; }
                    elseif ($rank === 2) { $medal = '🥈'; }
                    elseif ($rank === 3) { $medal = '🥉'; }
                    else { $medal = '#' . $rank; }
                ?>
                <li>
                    <span class="rank-medal"><?= $medal ?></span>
                    <span class="nickname"><?= $entry['nickname'] ?></span>
                    <span class="score"><?= $entry['score'] ?> <?= $pointsSuffix ?></span>
                </li>
                <?php endforeach; ?>
            </ol>
            <p id="no-results" class="status-message" style="display:none;"><?= $noResultsMessage ?></p>
        <?php elseif (!$errorMessage && empty($entries)): ?>
            <p class="status-message">No valid scores to display yet.</p>
        <?php endif; ?>
    </div>

    <?php if (!$errorMessage && !empty($entries)): ?>
    <script>
        (function () {
            var searchInput = document.getElementById('search');
            var items       = document.querySelectorAll('#leaderboard li');
            var noResults   = document.getElementById('no-results');
            if (!searchInput) return;

            var debounceTimer;
            searchInput.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(filterList, 250);
            });

            function filterList() {
                var query   = searchInput.value.toLowerCase().trim();
                var visible = 0;
                items.forEach(function (item) {
                    var nameEl = item.querySelector('.nickname');
                    if (!nameEl) return;
                    var match = nameEl.textContent.toLowerCase().indexOf(query) !== -1;
                    item.style.display = match ? '' : 'none';
                    item.classList.toggle('highlight', match && query !== '');
                    if (match) visible++;
                });
                if (noResults) {
                    noResults.style.display = (query !== '' && visible === 0) ? 'block' : 'none';
                }
            }
        })();
    </script>
    <?php endif; ?>
</body>
</html>
