// ── Helpers ──────────────────────────────────────────────────────────────────

function extractVideoId(url) {
    const patterns = [
        /[?&]v=([\w-]{11})/,
        /youtu\.be\/([\w-]{11})/,
        /embed\/([\w-]{11})/,
        /shorts\/([\w-]{11})/,
    ];
    for (const p of patterns) {
        const m = url.match(p);
        if (m) return m[1];
    }
    return null;
}

function parseXml(xml) {
    const segments = [], parts = [];
    const re = /<text\s+start="([\d.]+)"[^>]*>([\s\S]*?)<\/text>/gi;
    let m;
    while ((m = re.exec(xml)) !== null) {
        const text = m[2]
            .replace(/<[^>]+>/g, '')
            .replace(/&amp;/g,  '&')
            .replace(/&lt;/g,   '<')
            .replace(/&gt;/g,   '>')
            .replace(/&quot;/g, '"')
            .replace(/&#39;/g,  "'")
            .replace(/\s+/g,    ' ')
            .trim();
        if (text) {
            segments.push({ start: parseFloat(m[1]), text });
            parts.push(text);
        }
    }
    return parts.length ? { text: parts.join(' '), segments } : null;
}

function secsToTimestamp(secs) {
    const h  = Math.floor(secs / 3600);
    const mn = Math.floor((secs % 3600) / 60);
    const s  = secs % 60;
    return h > 0
        ? `${h}:${String(mn).padStart(2, '0')}:${String(s).padStart(2, '0')}`
        : `${String(mn).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
}

// ── Transcript fetching ───────────────────────────────────────────────────────

async function fetchTranscript(videoId) {
    // Method 1: Tactiq free API
    try {
        const r = await fetch('https://tactiq-apps-prod.tactiq.io/transcript', {
            method:  'POST',
            headers: {
                'Content-Type': 'application/json',
                'Origin':       'https://tactiq.io',
                'Referer':      'https://tactiq.io/',
            },
            body: JSON.stringify({
                videoUrl: `https://www.youtube.com/watch?v=${videoId}`,
                langCode: 'en',
            }),
        });
        if (r.ok) {
            const d = await r.json();
            if (d.captions?.length) {
                const segments = d.captions
                    .map(c => ({ start: (c.offset || 0) / 1000, text: (c.text || '').trim() }))
                    .filter(s => s.text);
                if (segments.length) {
                    return { segments, text: segments.map(s => s.text).join(' ') };
                }
            }
        }
    } catch (_) {}

    // Method 2: video.google.com timedtext
    for (const lang of ['en', 'a.en', 'en-US']) {
        try {
            const r = await fetch(
                `https://video.google.com/timedtext?lang=${lang}&v=${videoId}`
            );
            if (!r.ok) continue;
            const xml = await r.text();
            if (xml.length < 100) continue;
            const parsed = parseXml(xml);
            if (parsed && parsed.text.length > 50) return parsed;
        } catch (_) {}
    }

    return null;
}

// ── Build prompt ──────────────────────────────────────────────────────────────

function buildPrompt(transcriptData) {
    let body = '';
    if (transcriptData.segments?.length) {
        for (const seg of transcriptData.segments.slice(0, 220)) {
            const secs = Math.floor(seg.start);
            body += `[${secsToTimestamp(secs)}] ${seg.text}\n`;
        }
    } else {
        body = transcriptData.text.slice(0, 7000);
    }

    return (
        'You are a YouTube chapter generator. Analyze this timestamped transcript and create 5–8 well-named chapters.\n\n' +
        'FORMAT RULES:\n' +
        '- Use [MM:SS] for videos under 1 hour, [H:MM:SS] for longer\n' +
        '- First chapter MUST be [00:00]\n' +
        '- Titles must be descriptive and under 60 characters\n' +
        '- Return ONLY the chapter list — no intro, no explanation, no markdown\n\n' +
        'TRANSCRIPT:\n' + body
    );
}

// ── UI helpers ────────────────────────────────────────────────────────────────

function showError(msg) {
    document.getElementById('result').innerHTML =
        `<div class="error-message">❌ ${msg}</div>`;
}

function showChapters(chapters, wordCount) {
    window._chapters = chapters;

    const formatted = chapters
        .split('\n')
        .filter(line => line.trim())
        .map(line => {
            const highlighted = line.replace(
                /\[(\d+:\d{2}(?::\d{2})?)\]/g,
                '<span class="timestamp">[$1]</span>'
            );
            return `<span class="chapter-line">${highlighted}</span>`;
        })
        .join('');

    document.getElementById('result').innerHTML = `
        <div class="chapters-output">${formatted}</div>
        <div style="margin-top:16px; display:flex; gap:10px;">
            <button onclick="copyToClipboard()" class="copy-btn">📋 Copy</button>
            <button onclick="downloadChapters()" class="download-btn">📥 Download</button>
        </div>
        <p style="font-size:12px; opacity:0.5; margin-top:12px;">
            Transcript: ~${(wordCount || 0).toLocaleString()} words
        </p>`;
}

// ── Main action ───────────────────────────────────────────────────────────────

async function generateChapters() {
    const urlInput    = document.getElementById('videoUrl');
    const resultDiv   = document.getElementById('result');
    const loadingDiv  = document.getElementById('loading');
    const generateBtn = document.getElementById('generateBtn');
    const videoUrl    = urlInput.value.trim();

    // Basic validation
    if (!videoUrl) { showError('Please enter a YouTube URL'); return; }

    const patterns = [
        /youtube\.com\/watch\?v=/,
        /youtu\.be\//,
        /youtube\.com\/embed\//,
        /youtube\.com\/shorts\//,
    ];
    if (!patterns.some(p => p.test(videoUrl))) {
        showError('Please enter a valid YouTube URL');
        return;
    }

    const videoId = extractVideoId(videoUrl);
    if (!videoId) { showError('Could not extract video ID from URL'); return; }

    // Show loading state
    loadingDiv.style.display = 'block';
    resultDiv.innerHTML      = '';
    generateBtn.disabled     = true;
    generateBtn.textContent  = '⏳ Processing…';

    try {
        // Step 1: fetch transcript
        const transcriptData = await fetchTranscript(videoId);
        if (!transcriptData || transcriptData.text.length < 50) {
            showError(
                'No transcript found for this video. ' +
                'The video must have subtitles or auto-generated captions enabled on YouTube.'
            );
            return;
        }

        const wordCount = transcriptData.text.trim().split(/\s+/).length;

        // Step 2: call Anthropic API for chapters
        const response = await fetch('https://api.anthropic.com/v1/messages', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                model:      'claude-sonnet-4-6',
                max_tokens: 600,
                messages:   [{ role: 'user', content: buildPrompt(transcriptData) }],
            }),
        });

        const data = await response.json();

        if (!response.ok) {
            showError(`API error: ${data.error?.message || 'Unknown error. Please try again.'}`);
            return;
        }

        const chapters = data.content?.[0]?.text?.trim();
        if (!chapters) {
            showError('No chapters were generated. Please try another video.');
            return;
        }

        showChapters(chapters, wordCount);

    } catch (err) {
        showError('Network error: ' + err.message);
    } finally {
        loadingDiv.style.display = 'none';
        generateBtn.disabled     = false;
        generateBtn.textContent  = '⚡ Generate Chapters';
    }
}

// ── Copy / Download ───────────────────────────────────────────────────────────

function copyToClipboard() {
    const text = window._chapters || '';
    if (!text) { alert('Generate chapters first!'); return; }
    navigator.clipboard.writeText(text)
        .then(() => alert('✅ Copied to clipboard!'))
        .catch(() => {
            const ta = document.createElement('textarea');
            ta.value = text;
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            alert('✅ Copied!');
        });
}

function downloadChapters() {
    const text = window._chapters || '';
    if (!text) { alert('Generate chapters first!'); return; }
    const blob = new Blob([text], { type: 'text/plain' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = `chapters-${new Date().toISOString().slice(0, 10)}.txt`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

// ── Init ──────────────────────────────────────────────────────────────────────

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('videoUrl').addEventListener('keydown', function (e) {
        if (e.key === 'Enter') generateChapters();
    });
    document.getElementById('videoUrl').focus();
});
