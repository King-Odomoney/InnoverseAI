<?php
/**
 * InnoverseAI - Transcript Proxy
 * Fetches YouTube transcript server-side (avoids CORS).
 * Returns: { success, segments: [{start, text}], wordCount } or { error }
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit(); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit(); }

// ── Read input ────────────────────────────────────────────────────────────────
$raw     = file_get_contents('php://input');
$body    = json_decode($raw, true);
$videoId = trim($body['videoId'] ?? '');

if (!$videoId || !preg_match('/^[\w-]{11}$/', $videoId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid or missing videoId']);
    exit();
}

// ── cURL helper ───────────────────────────────────────────────────────────────
function curlFetch(string $url, array $headers = [], string $postJson = ''): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($postJson !== '') {
        curl_setopt($ch, CURLOPT_POST,       true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postJson);
    }
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$resp];
}

// ── XML parser (for timedtext / captionTracks XML) ────────────────────────────
function parseTimedText(string $xml): ?array {
    $segments = [];
    $parts    = [];
    if (!preg_match_all('/<text\s+start="([\d.]+)"[^>]*>(.*?)<\/text>/si', $xml, $m)) {
        return null;
    }
    foreach ($m[1] as $i => $start) {
        $text = html_entity_decode(strip_tags($m[2][$i]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/', ' ', $text));
        if ($text) {
            $segments[] = ['start' => (float)$start, 'text' => $text];
            $parts[]    = $text;
        }
    }
    return $parts ? ['segments' => $segments, 'text' => implode(' ', $parts)] : null;
}

$result = null;

// ── Method 1: Tactiq free API ─────────────────────────────────────────────────
$tactiq = curlFetch(
    'https://tactiq-apps-prod.tactiq.io/transcript',
    ['Content-Type: application/json', 'Origin: https://tactiq.io', 'Referer: https://tactiq.io/'],
    json_encode(['videoUrl' => "https://www.youtube.com/watch?v={$videoId}", 'langCode' => 'en'])
);
if ($tactiq['code'] === 200) {
    $td = json_decode($tactiq['body'], true);
    if (!empty($td['captions'])) {
        $segments = [];
        $parts    = [];
        foreach ($td['captions'] as $cap) {
            $text = trim($cap['text'] ?? '');
            if ($text) {
                $segments[] = ['start' => round(($cap['offset'] ?? 0) / 1000, 2), 'text' => $text];
                $parts[]    = $text;
            }
        }
        if ($segments) $result = ['segments' => $segments, 'text' => implode(' ', $parts)];
    }
}

// ── Method 2: YouTube watch page → captionTracks JSON ────────────────────────
if (!$result) {
    $page = curlFetch(
        "https://www.youtube.com/watch?v={$videoId}",
        ['Accept-Language: en-US,en;q=0.9']
    );
    if ($page['code'] === 200 && preg_match('/"captionTracks":(\[.*?\])/s', $page['body'], $cm)) {
        $tracks = json_decode($cm[1], true) ?? [];
        $track  = null;
        foreach ($tracks as $t) {
            if (strpos($t['languageCode'] ?? '', 'en') === 0) { $track = $t; break; }
        }
        if (!$track) $track = $tracks[0] ?? null;
        if (!empty($track['baseUrl'])) {
            $xml = curlFetch($track['baseUrl']);
            if ($xml['code'] === 200) {
                $parsed = parseTimedText($xml['body']);
                if ($parsed && strlen($parsed['text']) > 50) $result = $parsed;
            }
        }
    }
}

// ── Method 3: video.google.com timedtext ─────────────────────────────────────
if (!$result) {
    foreach (['en', 'a.en', 'en-US'] as $lang) {
        $r = curlFetch("https://video.google.com/timedtext?lang={$lang}&v={$videoId}");
        if ($r['code'] === 200 && strlen($r['body']) > 100) {
            $parsed = parseTimedText($r['body']);
            if ($parsed && strlen($parsed['text']) > 50) { $result = $parsed; break; }
        }
    }
}

// ── Method 4: YouTube InnerTube transcript API ────────────────────────────────
if (!$result) {
    $params = base64_encode("\x0a\x0b" . $videoId);
    $it = curlFetch(
        'https://www.youtube.com/youtubei/v1/get_transcript?key=AIzaSyAO_FJ2SlqU8Q4STEHLGCilw_Y9_11qcW8',
        ['Content-Type: application/json'],
        json_encode([
            'context' => ['client' => ['clientName' => 'WEB', 'clientVersion' => '2.20240101']],
            'params'  => $params,
        ])
    );
    if ($it['code'] === 200) {
        $itd  = json_decode($it['body'], true);
        $cues = $itd['actions'][0]['updateEngagementPanelAction']['content']
                     ['transcriptRenderer']['body']['transcriptBodyRenderer']['cueGroups'] ?? [];
        $segments = [];
        $parts    = [];
        foreach ($cues as $group) {
            foreach ($group['transcriptCueGroupRenderer']['cues'] ?? [] as $cue) {
                $cr   = $cue['transcriptCueRenderer'] ?? [];
                $ms   = (int)($cr['startOffsetMs'] ?? 0);
                $text = trim($cr['cue']['simpleText'] ?? '');
                if ($text) {
                    $segments[] = ['start' => round($ms / 1000, 2), 'text' => $text];
                    $parts[]    = $text;
                }
            }
        }
        if ($segments) $result = ['segments' => $segments, 'text' => implode(' ', $parts)];
    }
}

// ── Respond ───────────────────────────────────────────────────────────────────
if (!$result || strlen($result['text']) < 50) {
    http_response_code(422);
    echo json_encode([
        'error' => 'No transcript found. Make sure the video has subtitles or auto-captions enabled on YouTube.',
    ]);
    exit();
}

echo json_encode([
    'success'   => true,
    'segments'  => $result['segments'],
    'wordCount' => str_word_count($result['text']),
]);
