<?php
/**
 * InnoverseAI - YouTube Chapter Generator
 * Generate timestamped chapters from any YouTube video
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

function getTranscript($videoId) {
    $url = "https://www.youtube.com/watch?v=" . $videoId;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
    $html = curl_exec($ch);
    curl_close($ch);
    
    if (preg_match('/"captionTracks":\s*(\[.*?\])/', $html, $matches)) {
        $captionData = json_decode($matches[1], true);
        if (!empty($captionData)) {
            $baseUrl = $captionData[0]['baseUrl'] ?? '';
            if ($baseUrl) {
                $transcriptXml = file_get_contents($baseUrl);
                if ($transcriptXml) {
                    $xml = simplexml_load_string($transcriptXml);
                    $text = '';
                    foreach ($xml->text as $item) {
                        $text .= (string)$item . ' ';
                    }
                    return trim($text);
                }
            }
        }
    }
    return null;
}

$transcript = getTranscript($videoId);

if (!$transcript || strlen($transcript) < 20) {
    http_response_code(400);
    echo json_encode(['error' => 'No transcript available for this video. Try another video.']);
    exit();
}

$openaiApiKey = getenv('OPENAI_API_KEY');

if (!$openaiApiKey) {
    http_response_code(500);
    echo json_encode(['error' => 'OpenAI API key not configured']);
    exit();
}

$prompt = "You are a YouTube chapter generator. Create timestamped chapters from this transcript. 
Format EXACTLY like this:
[00:00] Introduction
[02:15] Main Topic 1
[05:30] Main Topic 2
[10:45] Conclusion

Rules:
- Create 5-8 chapters
- Make titles descriptive and clickable
- Each chapter should be 1-3 words
- Return ONLY the chapters, no extra text

Transcript: " . substr($transcript, 0, 6000);

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

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200) {
    http_response_code($httpCode);
    echo json_encode(['error' => 'OpenAI API error: ' . $response]);
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