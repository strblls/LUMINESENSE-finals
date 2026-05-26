const router = require('express').Router();

// node-fetch v3 exports as ESM; support both require() shapes
let fetch = null;
try {
    const _fetch = require('node-fetch');
    fetch = _fetch && _fetch.default ? _fetch.default : _fetch;
} catch (e) {
    // Fall back to global fetch if available (Node 18+)
    if (typeof globalThis.fetch === 'function') {
        fetch = globalThis.fetch;
    } else {
        // Will cause runtime error when used; we surface a clearer message below
        fetch = null;
        console.error('[lighting route] node-fetch not available and global fetch missing');
    }
}

router.post('/toggle', async (req, res) => {
    const { row, state } = req.body || {};  // { row: "1", state: "on" }

    console.info('[lighting] /toggle called with', { row, state });

    // Defensive runtime check: ensure `fetch` is actually callable
    if (!fetch || typeof fetch !== 'function') {
        const fetchInfo = {
            exists: !!fetch,
            type: typeof fetch,
            keys: fetch && typeof fetch === 'object' ? Object.keys(fetch) : undefined
        };
        console.error('[lighting] fetch is not callable; info=%o', fetchInfo);
        return res.status(500).json({ error: 'Server misconfiguration: fetch not callable', fetch: fetchInfo });
    }

    try {
        // Use Node's built-in http to forward the request to Flask (avoid node-fetch issues)
        const http = require('http');
        const payload = JSON.stringify({ row, state });

        const upstream = await new Promise((resolve, reject) => {
            const options = {
                hostname: '127.0.0.1',
                port: 5001,
                path: '/lighting/toggle',
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Content-Length': Buffer.byteLength(payload)
                },
                timeout: 5000
            };

            const creq = http.request(options, (cres) => {
                let body = '';
                cres.setEncoding('utf8');
                cres.on('data', (chunk) => body += chunk);
                cres.on('end', () => resolve({ status: cres.statusCode, headers: cres.headers, body }));
            });

            creq.on('error', (e) => reject(e));
            creq.on('timeout', () => {
                creq.destroy(new Error('Upstream request timed out'));
            });

            creq.write(payload);
            creq.end();
        });

        let data;
        try { data = JSON.parse(upstream.body); } catch (e) { data = upstream.body; }

        console.info('[lighting] proxied response status=%d body=%o', upstream.status, data);

        if (upstream.status && upstream.status >= 400) {
            return res.status(upstream.status).json({ error: data });
        }

        return res.json(data);
    } catch (err) {
        console.error('[lighting] proxy error:', err && err.stack ? err.stack : err);
        return res.status(500).json({ error: err && err.message ? err.message : String(err) });
    }
});

module.exports = router;