/**
 * File: pdfservice/renderer.js
 * Description: Renders a StraboMicro project PDF with the desktop app's own
 *              code, copied unchanged into vendor/ by sync-renderer.sh:
 *              projectSerializer.loadProjectJson (the app's project load,
 *              including the runtime imagePath), pdfReactExport (the PDF) and
 *              imageExport (micrograph images with overlays and spots, via
 *              renderPdfImage, the same call the app's PDF export makes).
 *
 *              The vendored files require 'electron-log', './tileCache' and
 *              './projectFolders', which only exist inside the Electron app.
 *              A module resolution hook points those requests at shims/
 *              instead, so the app files never need editing for the server.
 *
 *              Input is the project folder the server already keeps:
 *              straboMicroFiles/<id>/project.json, images/<imagePath>,
 *              tilesAffine/<micrographId>/medium.jpg. The same layout as the
 *              app's own project folder, for legacy and synced projects alike.
 */

const Module = require('module');
const fs = require('fs');
const path = require('path');

const SHIMS = {
  'electron-log': path.join(__dirname, 'shims', 'electron-log.js'),
  './tileCache': path.join(__dirname, 'shims', 'tileCache.js'),
  './projectFolders': path.join(__dirname, 'shims', 'projectFolders.js'),
};
const VENDOR_DIR = path.join(__dirname, 'vendor') + path.sep;

const originalResolve = Module._resolveFilename;
Module._resolveFilename = function (request, parent, ...rest) {
  if (SHIMS[request] && parent?.filename?.startsWith(VENDOR_DIR)) {
    return SHIMS[request];
  }
  return originalResolve.call(this, request, parent, ...rest);
};

const sharp = require('sharp');
const log = require('./shims/electron-log');
const tileCache = require('./shims/tileCache');
const projectFolders = require('./shims/projectFolders');
const projectSerializer = require('./vendor/projectSerializer');
const pdfReactExport = require('./vendor/pdfReactExport');
const imageExport = require('./vendor/imageExport');

// A long-running process: do not keep decoded images in sharp's cache
// between renders.
sharp.cache(false);

function badRequest(message) {
  return Object.assign(new Error(message), { status: 400 });
}

/**
 * Render straboMicroFiles/<projectId>/project.json to a PDF in the same
 * folder. Written to a temporary name first and renamed into place, so a
 * reader never sees a half-written file.
 *
 * @param {string} projectId  micro_projectmetadata.id (digits only)
 * @param {string} outName    File name inside the project folder
 * @returns {Promise<{file: string, bytes: number, ms: number, micrographs: number, messages: string[]}>}
 */
async function renderProjectPdf(projectId, outName) {
  if (!/^\d+$/.test(String(projectId))) throw badRequest('project must be a numeric id');
  if (typeof outName !== 'string' || !/^[A-Za-z0-9._-]+\.pdf$/.test(outName) || outName.startsWith('.')) {
    throw badRequest('out must be a plain .pdf file name');
  }

  const folderPaths = projectFolders.getProjectFolderPaths(projectId);
  const projectPath = folderPaths.projectPath;
  const outPath = path.join(projectPath, outName);
  const tmpPath = path.join(projectPath, `.${outName}.${process.pid}.tmp`);
  const started = Date.now();

  log.beginJob();
  let projectData;
  try {
    projectData = await projectSerializer.loadProjectJson(projectId);
    tileCache.setProject(projectPath, projectData);
    await pdfReactExport.generateProjectPDF(
      tmpPath,
      projectData,
      projectData.id,
      folderPaths,
      imageExport.renderPdfImage,
      null
    );
    await fs.promises.rename(tmpPath, outPath);
  } catch (err) {
    await fs.promises.rm(tmpPath, { force: true });
    err.messages = log.endJob();
    throw err;
  } finally {
    tileCache.clearProject();
  }
  const messages = log.endJob();

  return {
    file: outPath,
    bytes: (await fs.promises.stat(outPath)).size,
    ms: Date.now() - started,
    micrographs: imageExport.collectMicrographs(projectData).length,
    messages,
  };
}

module.exports = { renderProjectPdf };
