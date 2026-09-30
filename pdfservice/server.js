/**
 * File: pdfservice/server.js
 * Description: Internal HTTP service for the strabo-node container. Reachable
 *              only on the Docker network (no published port), as
 *              http://strabo-node:3000 from strabo-php.
 *
 *              GET  /health  Node, sharp and @react-pdf/renderer versions;
 *                            proves the native and ESM dependencies load.
 *                            "pdf": true once this build can render PDFs.
 *              POST /pdf     JSON {"project": <micro_projectmetadata.id>,
 *                            "out": "<file name>.pdf"}. Renders
 *                            straboMicroFiles/<id>/project.json with the
 *                            app's renderer into that folder and answers
 *                            {ok, file, bytes, ms, micrographs, messages}.
 *                            Renders run one at a time (full-resolution
 *                            composites use a lot of memory); later requests
 *                            wait their turn.
 *
 *              Check from the PHP container:
 *                docker exec strabo-php php -r 'echo file_get_contents("http://strabo-node:3000/health");'
 */

const http = require('http');
const { renderProjectPdf } = require('./renderer');

const PORT = 3000;
const MAX_BODY_BYTES = 10 * 1024;

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
    reactPdf: typeof reactPdf.renderToFile === 'function',
    // Callers check this before asking for renders (older builds lack it)
    pdf: true,
  };
}

function readJsonBody(req) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];
    req.on('data', (chunk) => {
      size += chunk.length;
      if (size > MAX_BODY_BYTES) {
        reject(new Error('request body too large'));
        req.destroy();
        return;
      }
      chunks.push(chunk);
    });
    req.on('end', () => {
      try {
        resolve(JSON.parse(Buffer.concat(chunks).toString('utf8') || '{}'));
      } catch {
        reject(new Error('request body is not JSON'));
      }
    });
    req.on('error', reject);
  });
}

// One render at a time: each job waits for the one before it.
let queue = Promise.resolve();
function enqueue(job) {
  const run = queue.then(job, job);
  queue = run.catch(() => {});
  return run;
}

async function handlePdf(req, res) {
  let body;
  try {
    body = await readJsonBody(req);
  } catch (err) {
    sendJson(res, 400, { ok: false, error: err.message });
    return;
  }
  const project = String(body.project ?? '');
  const out = body.out ?? 'project.pdf';
  console.log(`[pdfservice] render requested: project ${project} -> ${out}`);
  try {
    const result = await enqueue(() => renderProjectPdf(project, out));
    console.log(`[pdfservice] rendered project ${project}: ${result.bytes} bytes in ${result.ms} ms`);
    sendJson(res, 200, { ok: true, ...result });
  } catch (err) {
    console.error(`[pdfservice] render of project ${project} failed:`, err);
    const status = err.status || (err.code === 'ENOENT' ? 404 : 500);
    sendJson(res, status, { ok: false, error: err.message, messages: err.messages || [] });
  }
}

const server = http.createServer(async (req, res) => {
  try {
    if (req.method === 'GET' && req.url === '/health') {
      sendJson(res, 200, await health());
      return;
    }
    if (req.method === 'POST' && req.url === '/pdf') {
      await handlePdf(req, res);
      return;
    }
    sendJson(res, 404, { ok: false, error: 'not found' });
  } catch (err) {
    console.error(`[pdfservice] ${req.method} ${req.url} failed:`, err);
    sendJson(res, 500, { ok: false, error: err.message });
  }
});

// Renders of large projects can take minutes; do not cut them off.
server.requestTimeout = 0;

server.listen(PORT, () => {
  console.log(`[pdfservice] listening on ${PORT} (node ${process.version})`);
});

// docker stop sends SIGTERM; finish open requests, then exit
process.on('SIGTERM', () => {
  server.close(() => process.exit(0));
});
