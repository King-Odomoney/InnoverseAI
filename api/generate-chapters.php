<?php
/**
 * InnoverseAI - YouTube Chapter Generator
 * Uses YouTube's timedtext API with proper headers + OpenAI for chapters
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed. Use POST.']);
    exit();
}

$input    = json_decode(file_get_contents('php://input'), true);
$videoUrl = trim($input['videoUrl'] ?? '');

if (empty($videoUrl)) {
    http_response_code(400);
    echo json_encode(['error' => 'YouTube URL is required']);
    exit();
}

// ── 1. Extract video ID ───────────────────────────────────────────────────────
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
    echo json_encode(['error' => 'Invalid YouTube URL. Could not extract video ID.']);
    exit();
}

// ── 2. Fetch transcript ───────────────────────────────────────────────────────
function curlGet(string $url, array $headers = []): ?string {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER     => array_merge([
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
            'Accept-Language: en-US,en;q=0.9',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        ], $headers),
    ]);
    $result = curl_exec($ch);
    curl_close($ch);
    return $result ?: null;
}

function getTranscriptAndSegments(string $videoId): array {
    // ── Method A: Parse watch page for captionTracks ──────────────────────────
    $html = curlGet("https://www.youtube.com/watch?v={$videoId}");

    if ($html) {
        // Extract the full captions JSON blob
        if (preg_match('/"captions":\s*\{"playerCaptionsTracklistRenderer":\s*(\{.*?"captionTracks":\s*\[.*?\].*?\})\s*,\s*"audio/', $html, $m1)
            || preg_match('/"captionTracks":\s*(\[.*?\])\s*,\s*"audioTracks"/', $html, $m2)
        ) {
            $raw   = isset($m1[1]) ? $m1[1] : null;
            $tracks = null;

            if ($raw) {
                // Pull captionTracks array from the outer object
                if (preg_match('/"captionTracks":\s*(\[.*?\])\s*,/', $raw, $mx)) {
                    $tracks = json_decode($mx[1], true);
                }
            } elseif (isset($m2[1])) {
                $tracks = json_decode($m2[1], true);
            }

            if (!empty($tracks) && is_array($tracks)) {
                // Priority: English manual → English auto → anything
                $selected = null;
                foreach ($tracks as $t) {
                    if (($t['languageCode'] ?? '') === 'en' && empty($t['kind'])) {
                        $selected = $t; break;
                    }
                }
                if (!$selected) foreach ($tracks as $t) {
                    if (str_starts_with($t['languageCode'] ?? '', 'en')) {
                        $selected = $t; break;
                    }
                }
                if (!$selected) $selected = $tracks[0];

                if (!empty($selected['baseUrl'])) {
                    $xml = curlGet($selected['baseUrl']);
                    if ($xml) {
                        return parseTranscriptXml($xml);
                    }
                }
            }
        }
    }

    // ── Method B: Direct timedtext endpoint (older videos) ───────────────────
    foreach (['en', 'a.en', 'en-US'] as $lang) {
        $url = "https://video.google.com/timedtext?lang={$lang}&v={$videoId}&fmt=srv3";
        $xml = curlGet($url);
        if ($xml && strlen($xml) > 100) {
            $result = parseTranscriptXml($xml);
            if (!empty($result['text'])) return $result;
        }
    }

    return ['text' => '', 'segments' => []];
}

function parseTranscriptXml(string $xml): array {
    // Strip BOM and whitespace
    $xml = preg_replace('/^\xEF\xBB\xBF/', '', trim($xml));

    $segments = [];
    $parts    = [];

    // Handle both <text start="..."> and <p t="..."> formats
    if (preg_match_all('/<text\s+start="([\d.]+)"[^>]*>(.*?)<\/text>/si', $xml, $m)) {
        foreach ($m[1] as $i => $start) {
            $text = html_entity_decode(strip_tags($m[2][$i]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace('/\s+/', ' ', trim($text));
            if ($text) {
                $segments[] = ['start' => round((float)$start, 2), 'text' => $text];
                $parts[]    = $text;
            }
        }
    } elseif (preg_match_all('/<p\s+t="(\d+)"[^>]*>(.*?)<\/p>/si', $xml, $m)) {
        foreach ($m[1] as $i => $ms) {
            $text = html_entity_decode(strip_tags($m[2][$i]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace('/\s+/', ' ', trim($text));
            if ($text) {
                $segments[] = ['start' => round((int)$ms / 1000, 2), 'text' => $text];
                $parts[]    = $text;
            }
        }
    }

    return [
        'text'     => implode(' ', $parts),
        'segments' => $segments,
    ];
}

$transcriptData = getTranscriptAndSegments($videoId);
$transcript     = $transcriptData['text'];
$segments       = $transcriptData['segments'];

if (strlen($transcript) < 50) {
    http_response_code(400);
    echo json_encode([
        'error' => 'No transcript available for this video. This tool works with videos that have subtitles or auto-generated captions enabled.'
    ]);
    exit();
}

// ── 3. Build timestamped prompt body ─────────────────────────────────────────
$promptBody = '';
if (!empty($segments)) {
    $sample = array_slice($segments, 0, 220);
    foreach ($sample as $seg) {
        $secs = (int)$seg['start'];
        $h    = intdiv($secs, 3600);
        $m    = intdiv($secs % 3600, 60);
        $s    = $secs % 60;
        $ts   = $h > 0
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
    echo json_encode(['error' => 'OPENAI_API_KEY is not set in Vercel environment variables.']);
    exit();
}

$prompt = <<<PROMPT
You are a YouTube chapter generator. Analyze the transcript and create 5–8 well-named chapters.

FORMAT RULES (strictly follow):
- Use [MM:SS] for videos under 1 hour, [H:MM:SS] for longer ones
- First chapter MUST be [00:00]
- Chapter titles must be descriptive and under 60 characters
- Return ONLY the formatted chapter list — no intro, no explanation

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
    CURLOPT_POSTFIELDS     => json_encode([
        'model'       => 'gpt-3.5-turbo',
        'temperature' => 0.3,
        'max_tokens'  => 600,
        'messages'    => [
            [
                'role'    => 'system',
                'content' => 'You are a YouTube chapter generator. Return only the formatted chapter list, nothing else.',
            ],
            ['role' => 'user', 'content' => $prompt],
        ],
    ]),
    CURLOPT_TIMEOUT        => 60,
    CURLOPT_SSL_VERIFYPEER => false,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 401) {
    http_response_code(500);
    echo json_encode(['error' => 'Invalid OpenAI API key. Check Vercel environment variables.']);
    exit();
}
if ($httpCode === 429) {
    http_response_code(429);
    echo json_encode(['error' => 'OpenAI quota exceeded. Check your billing at platform.openai.com.']);
    exit();
}
if ($httpCode !== 200) {
    http_response_code(500);
    echo json_encode(['error' => "OpenAI returned HTTP {$httpCode}. Check your API key."]);
    exit();
}

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
