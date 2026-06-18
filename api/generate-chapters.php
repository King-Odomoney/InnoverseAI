<?php
/**
 * InnoverseAI - YouTube Chapter Generator
 * Transcript: Tactiq free API (no key needed)
 * Chapters: OpenAI GPT-3.5
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

if (empty($videoUrl)) {
    http_response_code(400);
    echo json_encode(['error' => 'YouTube URL is required']);
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

$fullUrl = "https://www.youtube.com/watch?v={$videoId}";

// ── 2. Fetch Transcript via Tactiq (free, no key) ────────────────────────────
function fetchTranscriptTactiq(string $videoUrl): array {
    $payload = json_encode(['videoUrl' => $videoUrl, 'langCode' => 'en']);

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
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) return [];

    $data = json_decode($response, true);

    // Tactiq returns: { "captions": [{ "text": "...", "offset": 1234 }, ...] }
    if (empty($data['captions'])) return [];

    $segments  = [];
    $textParts = [];

    foreach ($data['captions'] as $cap) {
        $text  = trim($cap['text'] ?? '');
        $start = round(($cap['offset'] ?? 0) / 1000, 2); // ms → seconds
        if ($text) {
            $segments[]  = ['start' => $start, 'text' => $text];
            $textParts[] = $text;
        }
    }

    return [
        'text'     => implode(' ', $textParts),
        'segments' => $segments,
    ];
}

// ── Fallback: YouTube timedtext direct endpoint ───────────────────────────────
function fetchTranscriptDirect(string $videoId): array {
    $langs = ['en', 'a.en', 'en-US', 'en-GB'];

    foreach ($langs as $lang) {
        $url = "https://video.google.com/timedtext?lang={$lang}&v={$videoId}";
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        ]);
        $xml  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code === 200 && $xml && strlen($xml) > 100) {
            return parseXml($xml);
        }
    }

    return [];
}

function parseXml(string $xml): array {
    $segments  = [];
    $textParts = [];

    if (preg_match_all('/<text\s+start="([\d.]+)"[^>]*>(.*?)<\/text>/si', $xml, $m)) {
        foreach ($m[1] as $i => $start) {
            $text = html_entity_decode(strip_tags($m[2][$i]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = trim(preg_replace('/\s+/', ' ', $text));
            if ($text) {
                $segments[]  = ['start' => round((float)$start, 2), 'text' => $text];
                $textParts[] = $text;
            }
        }
    }

    return empty($segments) ? [] : [
        'text'     => implode(' ', $textParts),
        'segments' => $segments,
    ];
}

// Try Tactiq first, fall back to direct
$transcriptData = fetchTranscriptTactiq($fullUrl);
if (empty($transcriptData['text'])) {
    $transcriptData = fetchTranscriptDirect($videoId);
}

$transcript = $transcriptData['text']     ?? '';
$segments   = $transcriptData['segments'] ?? [];

if (strlen($transcript) < 50) {
    http_response_code(400);
    echo json_encode([
        'error' => 'No transcript available for this video. It must have subtitles or auto-generated captions enabled on YouTube.'
    ]);
    exit();
}

// ── 3. Build timestamped prompt ───────────────────────────────────────────────
$promptBody = '';
if (!empty($segments)) {
    foreach (array_slice($segments, 0, 220) as $seg) {
        $secs = (int)$seg['start'];
        $h = intdiv($secs, 3600);
        $m = intdiv($secs % 3600, 60);
        $s = $secs % 60;
        $ts = $h > 0
            ? sprintf('%d:%02d:%02d', $h, $m, $s)
            : sprintf('%02d:%02d', $m, $s);
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

$prompt = <<<PROMPT
You are a YouTube chapter generator. Analyze the transcript and create 5–8 well-named chapters.

FORMAT RULES:
- Use [MM:SS] for videos under 1 hour, [H:MM:SS] for longer
- First chapter MUST be [00:00]
- Titles must be descriptive and under 60 characters
- Return ONLY the chapter list — no intro, no explanation

EXAMPLE:
[00:00] Introduction
[02:30] Setting Up the Environment
[08:15] Building the Core Feature
[18:40] Testing and Debugging
[24:00] Conclusion

TRANSCRIPT:
{$promptBody}
PROMPT;

$ch = curl_init('https://api.openai.com/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Authorization: Bearer ' . $openaiApiKey,
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'model'       => 'gpt-3.5-turbo',
        'temperature' => 0.3,
        'max_tokens'  => 600,
        'messages'    => [
            ['role' => 'system', 'content' => 'You are a YouTube chapter generator. Return only the formatted chapter list, nothing else.'],
            ['role' => 'user',   'content' => $prompt],
        ],
    ]),
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 401) { http_response_code(500); echo json_encode(['error' => 'Invalid OpenAI API key.']); exit(); }
if ($httpCode === 429) { http_response_code(429); echo json_encode(['error' => 'OpenAI quota exceeded. Check billing at platform.openai.com.']); exit(); }
if ($httpCode !== 200) { http_response_code(500); echo json_encode(['error' => "OpenAI error HTTP {$httpCode}."]); exit(); }

$data     = json_decode($response, true);
$chapters = trim($data['choices'][0]['message']['content'] ?? '');

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
]);
