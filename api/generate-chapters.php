<?php
/**
 * InnoverseAI - YouTube Chapter Generator
 * DEBUG VERSION - shows exactly what each method returns
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit();
}

$input    = json_decode(file_get_contents('php://input'), true);
$videoUrl = trim($input['videoUrl'] ?? '');
$debug    = $input['debug'] ?? false;

if (empty($videoUrl)) {
    http_response_code(400);
    echo json_encode(['error' => 'YouTube URL is required']);
    exit();
}

function extractVideoId(string $url): ?string {
    $patterns = [
        '/[?&]v=([\w-]{11})/',
        '/youtu\.be\/([\w-]{11})/',
        '/embed\/([\w-]{11})/',
        '/shorts\/([\w-]{11})/',
    ];
    foreach ($patterns as $p) {
        if (preg_match($p, $url, $m)) return $m[1];
    }
    return null;
}

$videoId = extractVideoId($videoUrl);
if (!$videoId) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid YouTube URL.']);
    exit();
}

$debugLog = ['videoId' => $videoId];

// ── METHOD 1: Tactiq ──────────────────────────────────────────────────────────
function tryTactiq(string $videoUrl): array {
    $ch = curl_init('https://tactiq-apps-prod.tactiq.io/transcript');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Origin: https://tactiq.io',
            'Referer: https://tactiq.io/',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ],
        CURLOPT_POSTFIELDS     => json_encode(['videoUrl' => $videoUrl, 'langCode' => 'en']),
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    return [
        'httpCode' => $httpCode,
        'error'    => $error,
        'preview'  => substr($response ?? '', 0, 300),
        'data'     => json_decode($response ?? '', true),
    ];
}

// ── METHOD 2: YouTube watch page → captionTracks ─────────────────────────────
function tryYouTubePage(string $videoId): array {
    $ch = curl_init("https://www.youtube.com/watch?v={$videoId}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => [
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Accept-Language: en-US,en;q=0.9',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ],
    ]);
    $html     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);

    $result = ['httpCode' => $httpCode, 'error' => $error, 'htmlLength' => strlen($html ?? '')];

    if ($html && preg_match('/"captionTracks":(\[.*?\])/', $html, $m)) {
        $tracks = json_decode($m[1], true);
        $result['tracksFound'] = count($tracks ?? []);
        $result['firstTrackUrl'] = $tracks[0]['baseUrl'] ?? 'none';
        $result['languages'] = array_column($tracks ?? [], 'languageCode');

        // Try fetching the first track
        if (!empty($tracks[0]['baseUrl'])) {
            $xmlCh = curl_init($tracks[0]['baseUrl']);
            curl_setopt_array($xmlCh, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 10,
                CURLOPT_SSL_VERIFYPEER => false,
            ]);
            $xml     = curl_exec($xmlCh);
            $xmlCode = curl_getinfo($xmlCh, CURLINFO_HTTP_CODE);
            curl_close($xmlCh);
            $result['xmlHttpCode'] = $xmlCode;
            $result['xmlPreview']  = substr($xml ?? '', 0, 200);
        }
    } else {
        $result['tracksFound'] = 0;
        $result['hasCaptionKeyword'] = strpos($html ?? '', 'captionTracks') !== false;
        $result['htmlPreview'] = substr($html ?? '', 0, 200);
    }

    return $result;
}

// ── METHOD 3: video.google.com timedtext ─────────────────────────────────────
function tryTimedtext(string $videoId): array {
    $results = [];
    foreach (['en', 'a.en'] as $lang) {
        $url = "https://video.google.com/timedtext?lang={$lang}&v={$videoId}";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0',
        ]);
        $xml  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $results[$lang] = ['httpCode' => $code, 'length' => strlen($xml ?? ''), 'preview' => substr($xml ?? '', 0, 200)];
    }
    return $results;
}

// ── METHOD 4: youtubetranscript.com ──────────────────────────────────────────
function tryYTTranscriptCom(string $videoId): array {
    $ch = curl_init("https://www.youtubetranscript.com/?server_vid2={$videoId}");
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);
    return ['httpCode' => $httpCode, 'error' => $error, 'preview' => substr($response ?? '', 0, 300)];
}

// Run all methods and return debug info
$debugLog['method1_tactiq']       = tryTactiq("https://www.youtube.com/watch?v={$videoId}");
$debugLog['method2_youtubePage']  = tryYouTubePage($videoId);
$debugLog['method3_timedtext']    = tryTimedtext($videoId);
$debugLog['method4_ytTranscript'] = tryYTTranscriptCom($videoId);

echo json_encode(['debug' => $debugLog], JSON_PRETTY_PRINT);
