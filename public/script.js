async function generateChapters() {
    const urlInput = document.getElementById('videoUrl');
    const resultDiv = document.getElementById('result');
    const loadingDiv = document.getElementById('loading');
    const generateBtn = document.getElementById('generateBtn');
    const videoUrl = urlInput.value.trim();

    if (!videoUrl) {
        resultDiv.innerHTML = `<div class="error-message">⚠️ Please enter a YouTube URL</div>`;
        return;
    }

    const patterns = [/youtube\.com\/watch\?v=/, /youtu\.be\//, /youtube\.com\/embed\//];
    if (!patterns.some(p => p.test(videoUrl))) {
        resultDiv.innerHTML = `<div class="error-message">⚠️ Please enter a valid YouTube URL</div>`;
        return;
    }

    loadingDiv.style.display = 'block';
    resultDiv.innerHTML = '';
    generateBtn.disabled = true;
    generateBtn.textContent = '⏳ Processing...';

    try {
        const response = await fetch('/api/generate-chapters.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ videoUrl })
        });

        const data = await response.json();
        loadingDiv.style.display = 'none';

        if (data.error) {
            resultDiv.innerHTML = `<div class="error-message">❌ ${data.error}</div>`;
            return;
        }

        if (data.success && data.chapters) {
            const formatted = data.chapters
                .split('\n')
                .filter(line => line.trim())
                .map(line => {
                    const formatted = line.replace(/\[(\d{2}:\d{2})\]/g, '<span class="timestamp">[$1]</span>');
                    return `<span class="chapter-line">${formatted}</span>`;
                })
                .join('');

            resultDiv.innerHTML = `
                <div class="chapters-output">${formatted}</div>
                <div style="margin-top:16px;">
                    <button onclick="copyToClipboard()" class="copy-btn">📋 Copy</button>
                    <button onclick="downloadChapters()" class="download-btn">📥 Download</button>
                </div>
            `;
            window._chapters = data.chapters;
        }
    } catch (error) {
        loadingDiv.style.display = 'none';
        resultDiv.innerHTML = `<div class="error-message">❌ Network error: ${error.message}</div>`;
    } finally {
        generateBtn.disabled = false;
        generateBtn.textContent = '⚡ Generate Chapters';
    }
}

function copyToClipboard() {
    const text = window._chapters || '';
    if (!text) { alert('Generate chapters first!'); return; }
    navigator.clipboard.writeText(text).then(() => alert('✅ Copied!')).catch(() => alert('Failed to copy'));
}

function downloadChapters() {
    const text = window._chapters || '';
    if (!text) { alert('Generate chapters first!'); return; }
    const blob = new Blob([text], { type: 'text/plain' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `chapters-${new Date().toISOString().slice(0,10)}.txt`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(url);
}

document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('videoUrl').addEventListener('keydown', function(e) {
        if (e.key === 'Enter') generateChapters();
    });
    document.getElementById('videoUrl').focus();
});