// ── Helpers ───────────────────────────────────────────────────────────────────

function extractVideoId(url) {
    const patterns = [
        /[?&]v=([\w-]{11})/,
        /youtu\.be\/([\w-]{11})/,
        /youtube\.com\/embed\/([\w-]{11})/,
        /youtube\.com\/shorts\/([\w-]{11})/,
    ];
    for (const p of patterns) {
        const m = url.match(p);
        if (m) return m[1];
    }
    return null;
}

function secsToTimestamp(secs) {
    const s  = Math.floor(secs);
    const h  = Math.floor(s / 3600);
    const mn = Math.floor((s % 3600) / 60);
    const sc = s % 60;
    return h > 0
        ? `${h}:${String(mn).padStart(2, '0')}:${String(sc).padStart(2, '0')}`
        : `${String(mn).padStart(2, '0')}:${String(sc).padStart(2, '0')}`;
}

function buildPrompt(segments) {
    let body = '';
    for (const seg of segments.slice(0, 220)) {
        body += `[${secsToTimestamp(seg.start)}] ${seg.text}\n`;
    }
    return (
        'You are a YouTube chapter generator. Analyze this timestamped transcript and create 5-8 well-named chapters.\n\n' +
        'FORMAT RULES:\n' +
        '- Use [MM:SS] for videos under 1 hour, [H:MM:SS] for longer\n' +
        '- First chapter MUST be [00:00]\n' +
        '- Titles must be descriptive and under 60 characters\n' +
        '- Return ONLY the chapter list — no intro, no explanation, no markdown\n\n' +
        'TRANSCRIPT:\n' + body
    );
}

// ── UI Helpers ────────────────────────────────────────────────────────────────

function setLoadingText(msg) {
    const p = document.querySelector('#loading p');
    if (p) p.textContent = msg;
}

function showError(msg) {
    document.getElementById('result').innerHTML =
        `<div class="error-message">❌ ${msg}</div>`;
}

function showChapters(chapters, wordCount) {
    window._chapters = chapters;
    const formatted = chapters
        .split('\n')
        .filter(l => l.trim())
        .map(line => {
            const hl = line.replace(
                /\[(\d+:\d{2}(?::\d{2})?)\]/g,
                '<span class="timestamp">[$1]</span>'
            );
            return `<span class="chapter-line">${hl}</span>`;
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

// ── Main ──────────────────────────────────────────────────────────────────────

async function generateChapters() {
    const urlInput    = document.getElementById('videoUrl');
    const resultDiv   = document.getElementById('result');
    const loadingDiv  = document.getElementById('loading');
    const generateBtn = document.getElementById('generateBtn');
    const videoUrl    = urlInput.value.trim();

    if (!videoUrl) { showError('Please enter a YouTube URL'); return; }

    const patterns = [/youtube\.com\/watch\?v=/, /youtu\.be\//, /youtube\.com\/embed\//, /youtube\.com\/shorts\//];
    if (!patterns.some(p => p.test(videoUrl))) {
        showError('Please enter a valid YouTube URL');
        return;
    }

    const videoId = extractVideoId(videoUrl);
    if (!videoId) { showError('Could not extract video ID from URL'); return; }

    // Reset UI
    resultDiv.innerHTML     = '';
    loadingDiv.style.display = 'block';
    generateBtn.disabled    = true;
    generateBtn.textContent = '⏳ Processing…';

    try {
        // ── Step 1: fetch transcript via serverless function ──────────────────
        setLoadingText('Fetching transcript…');

        const transcriptRes = await fetch('/api/get-transcript', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body:    JSON.stringify({ videoId }),
        });

        const transcriptData = await transcriptRes.json();

        if (!transcriptRes.ok || transcriptData.error) {
            showError(transcriptData.error || 'Failed to fetch transcript. Please try again.');
            return;
        }

        const { segments, wordCount } = transcriptData;

        if (!segments?.length) {
            showError('Transcript returned no usable segments. Try a different video.');
            return;
        }

        // ── Step 2: generate chapters via Claude ──────────────────────────────
        setLoadingText('Generating chapters with AI…');

        const aiRes = await fetch('https://api.anthropic.com/v1/messages', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                model:      'claude-sonnet-4-6',
                max_tokens: 600,
                messages:   [{ role: 'user', content: buildPrompt(segments) }],
            }),
        });

        const aiData = await aiRes.json();

        if (!aiRes.ok) {
            showError(`AI error: ${aiData.error?.message || 'Unknown error. Please try again.'}`);
            return;
        }

        const chapters = aiData.content?.[0]?.text?.trim();
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
            document.body.appendChild(ta); ta.select();
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
    document.body.appendChild(a); a.click();
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
