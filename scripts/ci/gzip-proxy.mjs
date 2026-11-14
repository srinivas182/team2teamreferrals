// CI only: puts gzip in front of `php artisan serve`, as the production server and CDN do, so Lighthouse measures
// what phones actually download. Usage: node scripts/ci/gzip-proxy.mjs 8001 8000
import http from 'node:http';
import zlib from 'node:zlib';

const [listen = '8001', target = '8000'] = process.argv.slice(2);
http.createServer((req, res) => {
    const up = http.request(
        { host: '127.0.0.1', port: target, path: req.url, method: req.method, headers: { ...req.headers, 'accept-encoding': 'identity' } },
        (r) => {
            const type = String(r.headers['content-type'] ?? '');
            const gzip = /gzip/.test(String(req.headers['accept-encoding'] ?? '')) && /text|javascript|json|xml|svg|css/.test(type);
            const headers = { ...r.headers };
            if (gzip) {
                delete headers['content-length'];
                headers['content-encoding'] = 'gzip';
                headers['vary'] = 'Accept-Encoding';
            }
            if (/\/build\/assets\//.test(req.url ?? '')) headers['cache-control'] = 'public, max-age=31536000, immutable';
            res.writeHead(r.statusCode ?? 502, headers);
            (gzip ? r.pipe(zlib.createGzip({ level: 6 })) : r).pipe(res);
        },
    );
    up.on('error', () => res.writeHead(502).end());
    req.pipe(up);
}).listen(Number(listen), '127.0.0.1');
