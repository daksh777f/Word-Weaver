<?php
require_once __DIR__ . '/onboarding/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

function printResult(string $label, string $message): void {
    echo "<h3>{$label}</h3><pre>" . htmlspecialchars($message) . "</pre>";
}

function baseUrl(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['PHP_SELF'] ?? '/')), '/');
    if ($dir === '') {
        $dir = '/';
    }
    return $scheme . '://' . $host . ($dir === '/' ? '' : $dir);
}

?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CodeDungeon Cerebras Diagnostics</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0b1220; color: #e5e7eb; padding: 24px; }
        h1 { color: #67e8f9; }
        h2 { color: #93c5fd; margin-top: 28px; }
        pre { background: #111827; border: 1px solid #334155; border-radius: 8px; padding: 12px; white-space: pre-wrap; }
    </style>
</head>
<body>
<h1>CodeDungeon Cerebras Diagnostics</h1>

<?php
// TEST 1 — API KEY CHECK
$test1 = '';
if (!defined('CEREBRAS_API_KEY')) {
    $test1 = "❌ FAIL: CEREBRAS_API_KEY is not defined in config.php";
} else {
    $key = CEREBRAS_API_KEY;
    if (empty($key)) {
        $test1 = "❌ FAIL: CEREBRAS_API_KEY is defined but empty";
    } elseif ($key === 'YOUR_KEY_HERE') {
        $test1 = "❌ FAIL: CEREBRAS_API_KEY is still set to placeholder value";
    } elseif (strlen($key) < 20) {
        $test1 = "❌ FAIL: CEREBRAS_API_KEY looks too short to be valid — check for typos";
    } else {
        $test1 = "✅ PASS: API key found, length = " . strlen($key) . " chars";
    }
}
printResult('TEST 1 — API KEY CHECK', $test1);

// TEST 2 — CURL AVAILABILITY CHECK
$test2 = function_exists('curl_init')
    ? "✅ PASS: cURL is available"
    : "❌ FAIL: cURL is not available on this server. Enable it in php.ini: extension=curl";
printResult('TEST 2 — CURL AVAILABILITY CHECK', $test2);

// TEST 3 — OUTBOUND NETWORK CHECK
$ch = curl_init('https://httpbin.org/get');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$result = curl_exec($ch);
$error = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($error) {
    $test3 = "❌ FAIL: Outbound cURL request failed. Error: {$error}\n"
        . "This means your server is blocking outbound HTTP requests. Check:\n"
        . "- Firewall rules\n- PHP open_basedir restrictions\n- Shared hosting outbound restrictions";
} elseif ($httpCode === 200) {
    $test3 = "✅ PASS: Server can make outbound HTTP requests";
} else {
    $test3 = "⚠️ WARN: Got HTTP {$httpCode} from test endpoint";
}
printResult('TEST 3 — OUTBOUND NETWORK CHECK', $test3);

// TEST 4 — CEREBRAS ENDPOINT REACHABILITY
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://api.cerebras.ai/v1/models',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . (defined('CEREBRAS_API_KEY') ? CEREBRAS_API_KEY : ''),
    ],
]);
$result = curl_exec($ch);
$error = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($error) {
    $test4 = "❌ FAIL: Cannot reach api.cerebras.ai\n"
        . "cURL error: {$error}\n"
        . "Possible causes:\n"
        . "- Server has no DNS resolution for api.cerebras.ai\n"
        . "- SSL certificate verification failing\n"
        . "- Firewall blocking port 443";
} elseif ($httpCode === 401) {
    $test4 = "❌ FAIL: Reached Cerebras API but got 401 Unauthorized.\n"
        . "Your API key is invalid or expired.\n"
        . "Check your Cerebras dashboard for a valid key.";
} elseif ($httpCode === 200) {
    $test4 = "✅ PASS: Cerebras API is reachable and key is valid\n{$result}";
} else {
    $test4 = "⚠️ WARN: Got HTTP {$httpCode}\n{$result}";
}
printResult('TEST 4 — CEREBRAS ENDPOINT REACHABILITY', $test4);

// TEST 5 — FULL CEREBRAS API CALL TEST
$payload = json_encode([
    'model' => 'qwen-3-235b-a22b-instruct-2507',
    'max_tokens' => 100,
    'messages' => [[
        'role' => 'user',
        'content' => 'Reply with exactly this JSON and nothing else: {"status": "working", "message": "Cerebras is connected"}',
    ]],
]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://api.cerebras.ai/v1/chat/completions',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . (defined('CEREBRAS_API_KEY') ? CEREBRAS_API_KEY : ''),
    ],
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlInfo = curl_getinfo($ch);
curl_close($ch);

$test5 = "Raw cURL Info:\n" . print_r($curlInfo, true) . "\nHTTP Status Code: {$httpCode}\n";
if ($curlError) {
    $test5 .= "❌ FAIL: cURL error: {$curlError}";
} elseif ($httpCode !== 200) {
    $test5 .= "❌ FAIL: API returned HTTP {$httpCode}\nRaw Response:\n{$response}\n";
    $decoded = json_decode((string)$response, true);
    if (isset($decoded['error'])) {
        $test5 .= "API Error Message:\n" . print_r($decoded['error'], true);
    }
} else {
    $decoded = json_decode((string)$response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $test5 .= "❌ FAIL: Response is not valid JSON. Raw response:\n{$response}";
    } elseif (!isset($decoded['choices'][0]['message']['content'])) {
        $test5 .= "❌ FAIL: JSON parsed but choices[0].message.content is missing.\nFull response structure:\n" . print_r($decoded, true);
    } else {
        $content = $decoded['choices'][0]['message']['content'];
        $test5 .= "✅ PASS: Full API call successful!\nModel Response:\n{$content}";
    }
}
printResult('TEST 5 — FULL CEREBRAS API CALL TEST', $test5);

// TEST 6 — SSL VERIFICATION CHECK
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => 'https://api.cerebras.ai/v1/models',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . (defined('CEREBRAS_API_KEY') ? CEREBRAS_API_KEY : ''),
    ],
]);
$result = curl_exec($ch);
$error = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if (!$error && $httpCode === 200) {
    $test6 = "⚠️ DIAGNOSIS: SSL verification is the problem. The API works when SSL verification is disabled. Fix:\n"
        . "Update your cacert.pem file, or add CURLOPT_CAINFO pointing to a valid certificate bundle. Do NOT ship with SSL verification disabled.";
} else {
    $test6 = "SSL is not the issue. Error: {$error} HTTP: {$httpCode}";
}
printResult('TEST 6 — SSL VERIFICATION CHECK', $test6);

// TEST 7 — INTENT API ENDPOINT TEST
$intentPayload = json_encode([
    'partial_code' => 'for(let i = 0; i <= arr.length; i++)',
    'language' => 'javascript',
    'challenge_id' => 1,
    'session_token' => 'test',
]);

$intentUrl = baseUrl() . '/play/intent_api.php';
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $intentUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $intentPayload,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
]);
$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    $test7 = "❌ FAIL: Could not reach intent_api.php\nError: {$error}";
} elseif ($httpCode !== 200) {
    $test7 = "❌ FAIL: intent_api.php returned HTTP {$httpCode}\n{$response}";
} else {
    $decoded = json_decode((string)$response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $test7 = "❌ FAIL: intent_api.php did not return valid JSON. Raw output:\n{$response}\n"
            . "Common cause: PHP error or warning being printed before JSON output. Check PHP error_reporting settings.";
    } else {
        $test7 = "✅ PASS: intent_api.php returned valid JSON:\n" . print_r($decoded, true);
    }
}
printResult('TEST 7 — INTENT API ENDPOINT TEST', $test7);

// TEST 8 — OBITUARY ENDPOINT TEST
$obituaryPayload = json_encode([
    'submitted_code' => 'function fix(arr) { for(let i=0;i<arr.length;i++){} return arr; }',
    'challenge_id' => 1,
    'time_taken' => 60,
    'language' => 'javascript',
    'user_id' => 1,
    'game_type' => 'bug_hunt',
]);

$obituaryUrl = baseUrl() . '/play/obituary.php';
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $obituaryUrl,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $obituaryPayload,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
]);
$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    $test8 = "❌ FAIL: Could not reach obituary.php\nError: {$error}";
} elseif ($httpCode !== 200) {
    $test8 = "❌ FAIL: obituary.php returned HTTP {$httpCode}\n{$response}";
} else {
    $decoded = json_decode((string)$response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $test8 = "❌ FAIL: obituary.php did not return valid JSON. Raw output:\n{$response}\n"
            . "Common cause: PHP error or warning being printed before JSON output.";
    } else {
        $test8 = "✅ PASS: obituary.php returned valid JSON:\n" . print_r($decoded, true);
    }
}
printResult('TEST 8 — OBITUARY ENDPOINT TEST', $test8);

// TEST 9 — SESSION CHECK
$_SESSION['debug_test'] = time();
$test9 = isset($_SESSION['debug_test'])
    ? '✅ PASS: PHP sessions are working'
    : '❌ FAIL: PHP sessions not working. Check session.save_path in php.ini';
printResult('TEST 9 — SESSION CHECK', $test9);

// TEST 10 — JSON CONTENT TYPE HEADER CHECK
function inspectPhpHeaderSafety(string $filePath): array {
    $issues = [];
    $raw = (string)file_get_contents($filePath);
    if (strncmp($raw, "<?php", 5) !== 0) {
        $issues[] = 'Whitespace or BOM before <?php';
    }

    $headerPos = stripos($raw, "header('Content-Type: application/json") ;
    if ($headerPos === false) {
        $headerPos = stripos($raw, 'header("Content-Type: application/json');
    }

    if ($headerPos === false) {
        $issues[] = 'Missing header(Content-Type: application/json)';
    } else {
        $before = substr($raw, 0, $headerPos);
        if (preg_match('/\b(echo|print)\b/i', $before)) {
            $issues[] = 'echo/print appears before JSON header';
        }
    }

    return $issues;
}

$intentIssues = inspectPhpHeaderSafety(__DIR__ . '/play/intent_api.php');
$obituaryIssues = inspectPhpHeaderSafety(__DIR__ . '/play/obituary.php');

if (count($intentIssues) || count($obituaryIssues)) {
    $test10 = "❌ FAIL: Output/header safety issue detected.\n";
    if (count($intentIssues)) {
        $test10 .= "intent_api.php:\n- " . implode("\n- ", $intentIssues) . "\n";
    }
    if (count($obituaryIssues)) {
        $test10 .= "obituary.php:\n- " . implode("\n- ", $obituaryIssues) . "\n";
    }
    $test10 .= "This can corrupt JSON responses silently.";
} else {
    $test10 = "✅ PASS: No output/header corruption pattern detected in intent_api.php or obituary.php";
}
printResult('TEST 10 — JSON CONTENT TYPE HEADER CHECK', $test10);
?>

</body>
</html>
