/**
 * File: pdfservice/server.js
 * Description: Internal HTTP service for the strabo-node container. Reachable
 *              only on the Docker network (no published port), as
 *              http://strabo-node:3000 from strabo-php.
 *
 *              GET /health   Node, sharp and @react-pdf/renderer versions;
 *                            proves the native and ESM dependencies load.
 *
 *              Check from the PHP container:
 *                docker exec strabo-php php -r 'echo file_get_contents("http://strabo-node:3000/health");'
 */

const http = require('http');

const PORT = 3000;

function sendJson(res, status, body) {
  const text = JSON.stringify(body);
  res.writeHead(status, { 'Content-Type': 'application/json', 'Content-Length': Buffer.byteLength(text) });
  res.end(text);
}

async function health() {
  const sharp = require('sharp');
  const reactPdf = await import('@react-pdf/renderer');
  const react = await import('react');
  return {
    ok: true,
    node: process.version,
    sharp: sharp.versions.sharp,
    vips: sharp.versions.vips,
    react: react.version,
    reactPdf: typeof reactPdf.renderToBuffer === 'function',
  };
}

const server = http.createServer(async (req, res) => {
  try {
    if (req.method === 'GET' && req.url === '/health') {
      sendJson(res, 200, await health());
      return;
    }
    sendJson(res, 404, { ok: false, error: 'not found' });
  } catch (err) {
    console.error(`[pdfservice] ${req.method} ${req.url} failed:`, err);
    sendJson(res, 500, { ok: false, error: err.message });
  }
});

server.listen(PORT, () => {
  console.log(`[pdfservice] listening on ${PORT} (node ${process.version})`);
});

// docker stop sends SIGTERM; finish open requests, then exit
process.on('SIGTERM', () => {
  server.close(() => process.exit(0));
});
