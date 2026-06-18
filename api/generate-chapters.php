<?php
ob_start();

function sendJson($data, $code = 200) {
    ob_end_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data);
    exit();
}

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    ob_end_clean();
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(['error' => 'Method not allowed. Use POST.'], 405);
}

// Read raw body
$rawBody = file_get_contents('php://input');
$input   = json_decode($rawBody, true);
$videoUrl = isset($input['videoUrl']) ? trim($input['videoUrl']) : '';

if (empty($videoUrl)) {
    sendJson(['error' => 'YouTube URL is required', 'raw' => $rawBody], 400);
}

// Extract video ID
$videoId = null;
$patterns = [
    '/[?&]v=([\w-]{11})/',
    '/youtu\.be\/([\w-]{11})/',
    '/embed\/([\w-]{11})/',
    '/shorts\/([\w-]{11})/',
];
foreach ($patterns as $p) {
    if (preg_match($p, $videoUrl, $m)) { $videoId = $m[1]; break; }
}
if (!$videoId) sendJson(['error' => 'Invalid YouTube URL.'], 400);

// cURL helper
function httpPost($url, $headers, $body, $timeout = 15) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array($code, $resp);
}

function httpGet($url, $headers = array(), $timeout = 15) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return array($code, $resp);
}

function parseXml($xml) {
    $segments = array();
    $parts    = array();
    if (preg_match_all('/<text\s+start="([\d.]+)"[^>]*>(.*?)<\/text>/si', $xml, $m)) {
        foreach ($m[1] as $i => $start) {
            $text = html_entity_decode(strip_tags($m[2][$i]), ENT_QUOTES, 'UTF-8');
            $text = trim(preg_replace('/\s+/', ' ', $text));
            if ($text) {
                $segments[] = array('start' => round((float)$start, 2), 'text' => $text);
                $parts[]    = $text;
            }
        }
    }
    if (empty($parts)) return array();
    return array('text' => implode(' ', $parts), 'segments' => $segments);
}

// ── Transcript: Method 1 — Tactiq ────────────────────────────────────────────
$transcript = '';
$segments   = array();
$method     = '';

list($code, $resp) = httpPost(
    'https://tactiq-apps-prod.tactiq.io/transcript',
    array('Content-Type: application/json', 'Origin: https://tactiq.io', 'Referer: https://tactiq.io/'),
    json_encode(array('videoUrl' => 'https://www.youtube.com/watch?v=' . $videoId, 'langCode' => 'en'))
);
if ($code === 200) {
    $td = json_decode($resp, true);
    if (!empty($td['captions'])) {
        foreach ($td['captions'] as $cap) {
            $text = trim(isset($cap['text']) ? $cap['text'] : '');
            if ($text) {
                $segments[] = array('start' => round((isset($cap['offset']) ? $cap['offset'] : 0) / 1000, 2), 'text' => $text);
            }
        }
        $transcript = implode(' ', array_column($segments, 'text'));
        $method = 'tactiq';
    }
}

// ── Transcript: Method 2 — YouTube watch page ─────────────────────────────────
if (strlen($transcript) < 50) {
    list($code, $html) = httpGet(
        'https://www.youtube.com/watch?v=' . $videoId,
        array('Accept-Language: en-US,en;q=0.9')
    );
    if ($code === 200 && preg_match('/"captionTracks":(\[.*?\])/', $html, $cm)) {
        $tracks = json_decode($cm[1], true);
        $track  = null;
        foreach ($tracks as $t) {
            $lang = isset($t['languageCode']) ? $t['languageCode'] : '';
            if (strpos($lang, 'en') === 0) { $track = $t; break; }
        }
        if (!$track && !empty($tracks)) $track = $tracks[0];
        if (!empty($track['baseUrl'])) {
            list($xcode, $xml) = httpGet($track['baseUrl']);
            if ($xcode === 200) {
                $parsed = parseXml($xml);
                if (!empty($parsed['text'])) {
                    $transcript = $parsed['text'];
                    $segments   = $parsed['segments'];
                    $method     = 'youtube_page';
                }
            }
        }
    }
}

// ── Transcript: Method 3 — timedtext direct ───────────────────────────────────
if (strlen($transcript) < 50) {
    foreach (array('en', 'a.en', 'en-US') as $lang) {
        list($code, $xml) = httpGet('https://video.google.com/timedtext?lang=' . $lang . '&v=' . $videoId);
        if ($code === 200 && strlen($xml) > 100) {
            $parsed = parseXml($xml);
            if (!empty($parsed['text'])) {
                $transcript = $parsed['text'];
                $segments   = $parsed['segments'];
                $method     = 'timedtext_' . $lang;
                break;
            }
        }
    }
}

if (strlen($transcript) < 50) {
    sendJson(array('error' => 'No transcript available for this video. It must have subtitles or auto-generated captions enabled on YouTube.'), 400);
}

// ── Build prompt ──────────────────────────────────────────────────────────────
$promptBody = '';
if (!empty($segments)) {
    foreach (array_slice($segments, 0, 220) as $seg) {
        $secs = (int)$seg['start'];
        $h = (int)($secs / 3600);
        $mn = (int)(($secs % 3600) / 60);
        $s  = $secs % 60;
        $ts = $h > 0 ? sprintf('%d:%02d:%02d', $h, $mn, $s) : sprintf('%02d:%02d', $mn, $s);
        $promptBody .= '[' . $ts . '] ' . $seg['text'] . "\n";
    }
} else {
    $promptBody = substr($transcript, 0, 7000);
}

// ── OpenAI ────────────────────────────────────────────────────────────────────
$openaiKey = getenv('OPENAI_API_KEY');
if (!$openaiKey) sendJson(array('error' => 'OPENAI_API_KEY not set in Vercel environment variables.'), 500);

$prompt = "You are a YouTube chapter generator. Create 5-8 chapters from this transcript.\n\n"
    . "RULES:\n"
    . "- First chapter MUST be [00:00]\n"
    . "- Use [MM:SS] format\n"
    . "- Return ONLY the chapter list\n\n"
    . "TRANSCRIPT:\n" . $promptBody;

list($aiCode, $aiResp) = httpPost(
    'https://api.openai.com/v1/chat/completions',
    array('Content-Type: application/json', 'Authorization: Bearer ' . $openaiKey),
    json_encode(array(
        'model'       => 'gpt-3.5-turbo',
        'temperature' => 0.3,
        'max_tokens'  => 600,
        'messages'    => array(
            array('role' => 'system', 'content' => 'You are a YouTube chapter generator. Return only the formatted chapter list.'),
            array('role' => 'user',   'content' => $prompt),
        ),
    )),
    60
);

if ($aiCode === 401) sendJson(array('error' => 'Invalid OpenAI API key.'), 500);
if ($aiCode === 429) sendJson(array('error' => 'OpenAI quota exceeded. Check billing at platform.openai.com.'), 429);
if ($aiCode !== 200) sendJson(array('error' => 'OpenAI error HTTP ' . $aiCode), 500);

$aiData   = json_decode($aiResp, true);
$chapters = trim(isset($aiData['choices'][0]['message']['content']) ? $aiData['choices'][0]['message']['content'] : '');

if (empty($chapters)) sendJson(array('error' => 'OpenAI returned empty chapters. Please try again.'), 500);

sendJson(array(
    'success'   => true,
    'videoId'   => $videoId,
    'chapters'  => $chapters,
    'wordCount' => str_word_count($transcript),
    'method'    => $method,
));
