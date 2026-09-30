/**
 * File: pdfservice/shims/electron-log.js
 * Description: Stands in for electron-log inside the vendored app renderer.
 *              Writes to stdout/stderr (docker logs strabo-node) and collects
 *              warnings and errors for the job in progress, so a render
 *              reports them (for example a micrograph image missing on disk).
 */

let jobMessages = null;

function format(args) {
  return args
    .map((a) => (a instanceof Error ? a.message : typeof a === 'string' ? a : JSON.stringify(a)))
    .join(' ');
}

function collect(level, args) {
  if (jobMessages) jobMessages.push(`${level}: ${format(args)}`);
}

module.exports = {
  info: (...args) => console.log(...args),
  debug: () => {},
  warn: (...args) => {
    console.warn(...args);
    collect('warn', args);
  },
  error: (...args) => {
    console.error(...args);
    collect('error', args);
  },

  /** Start collecting warnings and errors for one render. */
  beginJob() {
    jobMessages = [];
  },

  /** Stop collecting and return what the render reported. */
  endJob() {
    const messages = jobMessages || [];
    jobMessages = null;
    return messages;
  },
};
