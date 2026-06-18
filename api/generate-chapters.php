<?php
/**
 * InnoverseAI - YouTube Chapter Generator
 * Uses YouTube Transcript API service
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

$input = json_decode(file_get_contents('php://input'), true);
$videoUrl = $input['videoUrl'] ?? '';

if (empty($videoUrl)) {
    http_response_code(400);
    echo json_encode(['error' => 'YouTube URL is required']);
    exit();
}

function extractVideoId($url) {
    $patterns = [
        '/youtube\.com\/watch\?v=([\w-]+)/',
        '/youtu\.be\/([\w-]+)/',
        '/youtube\.com\/embed\/([\w-]+)/'
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $matches)) {
            return $matches[1];
        }
    }
    return null;
}

$videoId = extractVideoId($videoUrl);

if (!$videoId) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid YouTube URL']);
    exit();
}

// Get transcript using an external API service
function getTranscript($videoId) {
    // Try using the YouTube Transcript API (free, no key needed)
    $apiUrl = "https://www.youtube.com/watch?v=" . $videoId;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $html = curl_exec($ch);
    curl_close($ch);
    
    if (!$html) {
        return null;
    }
    
    // Extract caption tracks
    if (preg_match_all('/"captionTracks":\s*(\[.*?\])/', $html, $matches)) {
        foreach ($matches[1] as $match) {
            $captionData = json_decode($match, true);
            if (!empty($captionData)) {
                foreach ($captionData as $track) {
                    if (isset($track['baseUrl'])) {
                        $transcriptXml = @file_get_contents($track['baseUrl']);
                        if ($transcriptXml) {
                            $xml = @simplexml_load_string($transcriptXml);
                            if ($xml) {
                                $text = '';
                                foreach ($xml->text as $item) {
                                    $text .= (string)$item . ' ';
                                }
                                return trim($text);
                            }
                        }
                    }
                }
            }
        }
    }
    
    return null;
}

$transcript = getTranscript($videoId);

// If no transcript found, try using the API endpoint directly
if (!$transcript || strlen($transcript) < 10) {
    // Try alternative method - get transcript from YouTube's own API
    $apiUrl = "https://www.youtube.com/youtubei/v1/get_transcript";
    $payload = json_encode([
        'context' => [
            'client' => [
                'clientName' => 'WEB',
                'clientVersion' => '2.20231219.04.00'
            ]
        ],
        'videoId' => $videoId
    ]);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    curl_close($ch);
    
    if ($response) {
        $data = json_decode($response, true);
        if (isset($data['actions'][0]['updateEngagementPanelAction']['content']['transcriptRenderer']['body']['transcriptBodyRenderer']['cueGroups'])) {
            $cues = $data['actions'][0]['updateEngagementPanelAction']['content']['transcriptRenderer']['body']['transcriptBodyRenderer']['cueGroups'];
            $text = '';
            foreach ($cues as $cue) {
                if (isset($cue['transcriptCueGroupRenderer']['cues'])) {
                    foreach ($cue['transcriptCueGroupRenderer']['cues'] as $item) {
                        if (isset($item['transcriptCueRenderer']['cue']['simpleText'])) {
                            $text .= $item['transcriptCueRenderer']['cue']['simpleText'] . ' ';
                        }
                    }
                }
            }
            $transcript = trim($text);
        }
    }
}

if (!$transcript || strlen($transcript) < 10) {
    http_response_code(400);
    echo json_encode(['error' => 'No transcript available. Try a video with subtitles or captions.']);
    exit();
}

// Get OpenAI API key
$openaiApiKey = getenv('OPENAI_API_KEY');

if (!$openaiApiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'OpenAI API key not configured']);
    exit();
}

// Generate chapters
$prompt = "You are a YouTube chapter generator. Create timestamped chapters from this transcript.

Format EXACTLY like this example:
[00:00] Introduction
[02:15] Main Topic 1
[05:30] Main Topic 2
[10:45] Conclusion

Rules:
- Create 5-8 chapters
- Make titles descriptive and clickable
- Return ONLY the chapters, no extra text

Transcript: " . substr($transcript, 0, 7000);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://api.openai.com/v1/chat/completions');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $openaiApiKey
]);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
    'model' => 'gpt-3.5-turbo',
    'messages' => [
        [
            'role' => 'system',
            'content' => 'You are a YouTube chapter generator. Create timestamped chapters from transcripts. Always respond with only the formatted chapters.'
        ],
        [
            'role' => 'user',
            'content' => $prompt
        ]
    ],
    'temperature' => 0.3,
    'max_tokens' => 500
]));
curl_setopt($ch, CURLOPT_TIMEOUT, 60);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    http_response_code($httpCode);
    echo json_encode(['error' => 'OpenAI API error. Please check your API key.']);
    exit();
}

$data = json_decode($response, true);
$chapters = $data['choices'][0]['message']['content'] ?? '';

if (empty($chapters)) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to generate chapters']);
    exit();
}

echo json_encode([
    'success' => true,
    'videoId' => $videoId,
    'chapters' => $chapters,
    'wordCount' => str_word_count($transcript)
]);
