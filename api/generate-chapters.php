<?php
/**
 * InnoverseAI - YouTube Chapter Generator
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit();
}

// ── Read input — works on both Vercel PHP runtime and standard PHP ────────────
$rawBody = '';
$stream  = fopen('php://input', 'r');
if ($stream) {
    while (!feof($stream)) {
        $rawBody .= fread($stream, 8192);
    }
    fclose($stream);
}

// Fallback: try $_POST if json body is empty
$input = json_decode($rawBody, true);
if (empty($input) && !empty($_POST['videoUrl'])) {
    $input = $_POST;
}
// Last resort: try getallheaders + read again
if (empty($input)) {
    $input = json_decode(file_get_contents('php://input'), true);
}

$videoUrl = trim($input['videoUrl'] ?? '');

if (empty($videoUrl)) {
    http_response_code(400);
    echo json_encode(['error' => 'YouTube URL is required', 'received' => $rawBody]);
    exit();
}

// ── 1. Extract Video ID ───────────────────────────────────────────────────────
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

// ── 2. Fetch Transcript ───────────────────────────────────────────────────────
function curlFetch(string $url, array $opts = []): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, array_merge([
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
    ], $opts));
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => $body ?: '', 'error' => $err];
}

function parseXml(string $xml): array {
    $segments = []; $parts = [];
    if (preg_match_all('/<text\s+start="([\d.]+)"[^>]*>(.*?)<\/text>/si', $xml, $m)) {
        foreach ($m[1] as $i => $start) {
            $text = html_entity_decode(strip_tags($m[2][$i]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim(preg_replace('/\s+/', ' ', $text));
            if ($text) {
                $segments[] = ['start' => round((float)$start, 2), 'text' => $text];
                $parts[]    = $text;
            }
        }
    }
    return empty($parts) ? [] : ['text' => implode(' ', $parts), 'segments' => $segments];
}

$transcript = '';
$segments   = [];
$methodUsed = '';

// Method 1: Tactiq free API
$tactiq = curlFetch('https://tactiq-apps-prod.tactiq.io/transcript', [
    CURLOPT_POST       => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Origin: https://tactiq.io',
        'Referer: https://tactiq.io/',
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'videoUrl' => "https://www.youtube.com/watch?v={$videoId}",
        'langCode'  => 'en'
    ]),
]);
if ($tactiq['code'] === 200) {
    $td = json_decode($tactiq['body'], true);
    if (!empty($td['captions'])) {
        foreach ($td['captions'] as $cap) {
            $text = trim($cap['text'] ?? '');
            if ($text) {
                $segments[] = ['start' => round(($cap['offset'] ?? 0) / 1000, 2), 'text' => $text];
            }
        }
        $transcript = implode(' ', array_column($segments, 'text'));
        $methodUsed = 'tactiq';
    }
}

// Method 2: YouTube watch page captionTracks
if (strlen($transcript) < 50) {
    $page = curlFetch("https://www.youtube.com/watch?v={$videoId}", [
        CURLOPT_HTTPHEADER => [
            'Accept-Language: en-US,en;q=0.9',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ],
    ]);
    if ($page['code'] === 200 && preg_match('/"captionTracks":(\[.*?\])/', $page['body'], $m)) {
        $tracks = json_decode($m[1], true);
        $track  = null;
        foreach ($tracks as $t) { if (str_starts_with($t['languageCode'] ?? '', 'en')) { $track = $t; break; } }
        if (!$track) $track = $tracks[0] ?? null;
        if (!empty($track['baseUrl'])) {
            $xml = curlFetch($track['baseUrl']);
            if ($xml['code'] === 200) {
                $parsed = parseXml($xml['body']);
                if (!empty($parsed['text'])) {
                    $transcript = $parsed['text'];
                    $segments   = $parsed['segments'];
                    $methodUsed = 'youtube_page';
                }
            }
        }
    }
}

// Method 3: video.google.com timedtext
if (strlen($transcript) < 50) {
    foreach (['en', 'a.en', 'en-US'] as $lang) {
        $r = curlFetch("https://video.google.com/timedtext?lang={$lang}&v={$videoId}");
        if ($r['code'] === 200 && strlen($r['body']) > 100) {
            $parsed = parseXml($r['body']);
            if (!empty($parsed['text'])) {
                $transcript = $parsed['text'];
                $segments   = $parsed['segments'];
                $methodUsed = "timedtext_{$lang}";
                break;
            }
        }
    }
}

if (strlen($transcript) < 50) {
    http_response_code(400);
    echo json_encode(['error' => 'No transcript available for this video. It must have subtitles or auto-generated captions enabled on YouTube.']);
    exit();
}

// ── 3. Build timestamped prompt ───────────────────────────────────────────────
$promptBody = '';
if (!empty($segments)) {
    foreach (array_slice($segments, 0, 220) as $seg) {
        $secs = (int)$seg['start'];
        $h = intdiv($secs, 3600); $m = intdiv($secs % 3600, 60); $s = $secs % 60;
        $ts = $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%02d:%02d', $m, $s);
        $promptBody .= "[{$ts}] {$seg['text']}\n";
    }
} else {
    $promptBody = substr($transcript, 0, 7000);
}

// ── 4. Generate chapters via OpenAI ──────────────────────────────────────────
$openaiApiKey = getenv('OPENAI_API_KEY');
if (!$openaiApiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'OPENAI_API_KEY not set in Vercel environment variables.']);
    exit();
}

$prompt = "You are a YouTube chapter generator. Analyze the transcript and create 5-8 well-named chapters.\n\n"
    . "FORMAT RULES:\n"
    . "- Use [MM:SS] for videos under 1 hour, [H:MM:SS] for longer\n"
    . "- First chapter MUST be [00:00]\n"
    . "- Titles must be descriptive and under 60 characters\n"
    . "- Return ONLY the chapter list, no intro, no explanation\n\n"
    . "TRANSCRIPT:\n{$promptBody}";

$ai = curlFetch('https://api.openai.com/v1/chat/completions', [
    CURLOPT_POST       => true,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $openaiApiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'model'       => 'gpt-3.5-turbo',
        'temperature' => 0.3,
        'max_tokens'  => 600,
        'messages'    => [
            ['role' => 'system', 'content' => 'You are a YouTube chapter generator. Return only the formatted chapter list.'],
            ['role' => 'user',   'content' => $prompt],
        ],
    ]),
    CURLOPT_TIMEOUT => 60,
]);

if ($ai['code'] === 401) { http_response_code(500); echo json_encode(['error' => 'Invalid OpenAI API key.']); exit(); }
if ($ai['code'] === 429) { http_response_code(429); echo json_encode(['error' => 'OpenAI quota exceeded.']); exit(); }
if ($ai['code'] !== 200) { http_response_code(500); echo json_encode(['error' => "OpenAI error HTTP {$ai['code']}"]); exit(); }

$aiData   = json_decode($ai['body'], true);
$chapters = trim($aiData['choices'][0]['message']['content'] ?? '');

if (empty($chapters)) {
    http_response_code(500);
    echo json_encode(['error' => 'OpenAI returned empty chapters. Please try again.']);
    exit();
}

echo json_encode([
    'success'   => true,
    'videoId'   => $videoId,
    'chapters'  => $chapters,
    'wordCount' => str_word_count($transcript),
    'method'    => $methodUsed,
]);
