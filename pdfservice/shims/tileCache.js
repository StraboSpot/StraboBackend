/**
 * File: pdfservice/shims/tileCache.js
 * Description: Stands in for the app's electron/tileCache.js inside the
 *              vendored renderer. The only call the renderer makes is
 *              loadAffineMedium(affineTileHash). The app keys its cache by
 *              that hash; the server keeps the same image per micrograph at
 *              straboMicroFiles/<id>/tilesAffine/<micrographId>/medium.jpg,
 *              so the render sets up a hash -> file map first.
 */

const fs = require('fs').promises;
const path = require('path');

let mediumByHash = new Map();

module.exports = {
  /**
   * Map every affine micrograph's affineTileHash to its medium.jpg for the
   * project about to render.
   * @param {string} projectPath  straboMicroFiles/<id>
   * @param {Object} projectData  parsed project.json
   */
  setProject(projectPath, projectData) {
    mediumByHash = new Map();
    for (const dataset of projectData?.datasets || []) {
      for (const sample of dataset.samples || []) {
        for (const micrograph of sample.micrographs || []) {
          if (micrograph.affineTileHash) {
            mediumByHash.set(
              micrograph.affineTileHash,
              path.join(projectPath, 'tilesAffine', micrograph.id, 'medium.jpg')
            );
          }
        }
      }
    }
  },

  clearProject() {
    mediumByHash = new Map();
  },

  async loadAffineMedium(imageHash) {
    const file = mediumByHash.get(imageHash);
    if (!file) return null;
    try {
      return await fs.readFile(file);
    } catch {
      return null;
    }
  },
};
