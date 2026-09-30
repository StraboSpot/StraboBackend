/**
 * File: pdfservice/shims/projectFolders.js
 * Description: Stands in for the app's electron/projectFolders.js inside the
 *              vendored renderer. The app keeps a project in
 *              ~/Documents/StraboMicro2Data/<uuid>/; the server keeps the
 *              same layout in straboMicroFiles/<id>/, so the "project id"
 *              the vendored code passes around is the server's numeric id.
 */

const path = require('path');

const DATA_ROOT = process.env.MICRO_FILES_ROOT || '/srv/app/www/straboMicroFiles';

function getProjectFolderPath(projectId) {
  if (!/^\d+$/.test(String(projectId))) throw new Error(`not a server project id: ${projectId}`);
  return path.join(DATA_ROOT, String(projectId));
}

/** Same keys as the app's getProjectFolderPaths. */
function getProjectFolderPaths(projectId) {
  const projectPath = getProjectFolderPath(projectId);
  return {
    projectPath,
    associatedFiles: path.join(projectPath, 'associatedFiles'),
    compositeImages: path.join(projectPath, 'compositeImages'),
    compositeThumbnails: path.join(projectPath, 'compositeThumbnails'),
    images: path.join(projectPath, 'images'),
    uiImages: path.join(projectPath, 'uiImages'),
    webImages: path.join(projectPath, 'webImages'),
    webThumbnails: path.join(projectPath, 'webThumbnails'),
    projectJson: path.join(projectPath, 'project.json'),
  };
}

module.exports = { getProjectFolderPath, getProjectFolderPaths };
