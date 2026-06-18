// api/get-transcript.js
// Vercel serverless function — fetches YouTube transcript server-side (no CORS issues)

export default async function handler(req, res) {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type');

    if (req.method === 'OPTIONS') return res.status(200).end();
    if (req.method !== 'POST')   return res.status(405).json({ error: 'Method not allowed' });

    const { videoId } = req.body || {};
    if (!videoId || !/^[\w-]{11}$/.test(videoId)) {
        return res.status(400).json({ error: 'Invalid or missing videoId' });
    }

    // ── Helper: fetch with timeout + UA ──────────────────────────────────────
    async function get(url, options = {}) {
        const controller = new AbortController();
        const timeout    = setTimeout(() => controller.abort(), 15000);
        try {
            const r = await fetch(url, {
                ...options,
                signal: controller.signal,
                headers: {
                    'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/124.0 Safari/537.36',
                    'Accept-Language': 'en-US,en;q=0.9',
                    ...(options.headers || {}),
                },
            });
            return { ok: r.ok, status: r.status, text: await r.text() };
        } catch (e) {
            return { ok: false, status: 0, text: '' };
        } finally {
            clearTimeout(timeout);
        }
    }

    // ── Helper: parse YouTube timed-text XML ──────────────────────────────────
    function parseXml(xml) {
        const segments = [], parts = [];
        const re = /<text\s+start="([\d.]+)"[^>]*>([\s\S]*?)<\/text>/gi;
        let m;
        while ((m = re.exec(xml)) !== null) {
            const text = m[2]
                .replace(/<[^>]+>/g, '')
                .replace(/&amp;/g,  '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>')
                .replace(/&quot;/g, '"').replace(/&#39;/g, "'")
                .replace(/\s+/g, ' ').trim();
            if (text) { segments.push({ start: parseFloat(m[1]), text }); parts.push(text); }
        }
        return parts.length ? { segments, text: parts.join(' ') } : null;
    }

    let result = null;

    // ── Method 1: Tactiq free API ─────────────────────────────────────────────
    try {
        const r = await get('https://tactiq-apps-prod.tactiq.io/transcript', {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'Origin': 'https://tactiq.io', 'Referer': 'https://tactiq.io/' },
            body:    JSON.stringify({ videoUrl: `https://www.youtube.com/watch?v=${videoId}`, langCode: 'en' }),
        });
        if (r.ok) {
            const d = JSON.parse(r.text);
            if (d.captions?.length) {
                const segments = d.captions
                    .map(c => ({ start: (c.offset || 0) / 1000, text: (c.text || '').trim() }))
                    .filter(s => s.text);
                if (segments.length) {
                    result = { segments, text: segments.map(s => s.text).join(' ') };
                }
            }
        }
    } catch (_) {}

    // ── Method 2: YouTube watch page → captionTracks ──────────────────────────
    if (!result) {
        try {
            const page = await get(`https://www.youtube.com/watch?v=${videoId}`);
            if (page.ok) {
                const cm = page.text.match(/"captionTracks":(\[.*?\])/s);
                if (cm) {
                    const tracks = JSON.parse(cm[1]);
                    let track = tracks.find(t => (t.languageCode || '').startsWith('en')) || tracks[0];
                    if (track?.baseUrl) {
                        const xml = await get(track.baseUrl);
                        if (xml.ok) {
                            const parsed = parseXml(xml.text);
                            if (parsed?.text?.length > 50) result = parsed;
                        }
                    }
                }
            }
        } catch (_) {}
    }

    // ── Method 3: video.google.com timedtext ──────────────────────────────────
    if (!result) {
        for (const lang of ['en', 'a.en', 'en-US']) {
            try {
                const r = await get(`https://video.google.com/timedtext?lang=${lang}&v=${videoId}`);
                if (r.ok && r.text.length > 100) {
                    const parsed = parseXml(r.text);
                    if (parsed?.text?.length > 50) { result = parsed; break; }
                }
            } catch (_) {}
        }
    }

    // ── Method 4: YouTube InnerTube API ───────────────────────────────────────
    if (!result) {
        try {
            const params = Buffer.from('\x0a\x0b' + videoId).toString('base64');
            const r = await get(
                'https://www.youtube.com/youtubei/v1/get_transcript?key=AIzaSyAO_FJ2SlqU8Q4STEHLGCilw_Y9_11qcW8',
                {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({
                        context: { client: { clientName: 'WEB', clientVersion: '2.20240101' } },
                        params,
                    }),
                }
            );
            if (r.ok) {
                const itd  = JSON.parse(r.text);
                const cues = itd?.actions?.[0]?.updateEngagementPanelAction?.content
                              ?.transcriptRenderer?.body?.transcriptBodyRenderer?.cueGroups || [];
                const segments = [], parts = [];
                for (const group of cues) {
                    for (const cue of group?.transcriptCueGroupRenderer?.cues || []) {
                        const cr   = cue?.transcriptCueRenderer || {};
                        const ms   = parseInt(cr.startOffsetMs || 0);
                        const text = (cr.cue?.simpleText || '').trim();
                        if (text) { segments.push({ start: ms / 1000, text }); parts.push(text); }
                    }
                }
                if (segments.length) result = { segments, text: parts.join(' ') };
            }
        } catch (_) {}
    }

    // ── Respond ───────────────────────────────────────────────────────────────
    if (!result || result.text.length < 50) {
        return res.status(422).json({
            error: 'No transcript found. Make sure the video has subtitles or auto-generated captions enabled on YouTube.',
        });
    }

    return res.status(200).json({
        success:   true,
        segments:  result.segments,
        wordCount: result.text.trim().split(/\s+/).length,
    });
}