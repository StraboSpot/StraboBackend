#!/usr/bin/env bash
# =============================================================================
# File: exportjobs/cleanup_data.sh
# Description: Belt-and-braces cleanup of the Export Builder job data folder
#              (docs/ExportBuilder_Design.md §6.6.1). The worker already
#              removes every workspace on success, failure and cancel and
#              expires results after 7 days (retention) or when a user's cap
#              is hit; this script only catches what a crashed worker left
#              behind and keeps the logs bounded. Meant for the nightly
#              host-side cron; safe to run from inside the container too.
#
#   work/<uuid>/                  remove subdirectories older than WORK_DAYS (1)
#   results/<userpkey>/<uuid>.zip remove files older than RESULT_DAYS (8, i.e.
#                                 one day past the 7-day retention; the
#                                 download endpoint treats a missing file as
#                                 expired), then drop empty per-user dirs
#   log/*.log                     rotate to .1 when larger than LOG_MAX_MB (50)
#   .htaccess                     never touched (the code rewrites it anyway)
#
# Usage:
#   exportjobs/cleanup_data.sh [--dry-run] [DATA_DIR]
#
#   DATA_DIR defaults to <this script's dir>/../exportjobs_data (a symlink is
#   followed, so on prod the default resolves through www/exportjobs_data;
#   the real host path works too). Prints one line per action; exits 0 when
#   nothing needed doing, 2 when DATA_DIR does not look like the job data
#   folder (no results/ work/ log/ inside), so a wrong path deletes nothing.
#
#   Prod host crontab (nightly, after the worker's own retention has run):
#   30 4 * * * /home/ubuntu/DC/Strabo/www/exportjobs/cleanup_data.sh /volumes/volume01/StraboData/bigDriveData/exportjobs_data >> /var/log/strabo_exportjobs_cleanup.log 2>&1
#
# @package    StraboSpot Web Site
# @author     Jason Ash <jasonash@ku.edu>
# @copyright  2026 StraboSpot
# @license    https://opensource.org/licenses/MIT MIT License
# @link       https://strabospot.org
# =============================================================================
set -euo pipefail

WORK_DAYS="${WORK_DAYS:-1}"
RESULT_DAYS="${RESULT_DAYS:-8}"
TILE_DAYS="${TILE_DAYS:-90}"
FIELDBOOK_HOURS="${FIELDBOOK_HOURS:-24}"   # interactive Field Book builds (fieldbook/<key>.json + .pdf, docs/Fieldbook_Design.md §14 M6)
LOG_MAX_MB="${LOG_MAX_MB:-50}"

DRY=0
if [ "${1:-}" = "--dry-run" ] || [ "${1:-}" = "-n" ]; then DRY=1; shift; fi

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
DATA_DIR="${1:-$SCRIPT_DIR/../exportjobs_data}"

stamp() { date '+%Y-%m-%d %H:%M:%S'; }
say()   { echo "[$(stamp)] $*"; }
act()   { if [ "$DRY" = 1 ]; then say "DRY-RUN: $*"; else say "$*"; fi; }

if [ ! -d "$DATA_DIR" ]; then
	say "ERROR: $DATA_DIR is not a directory (or the symlink does not resolve here)"
	exit 2
fi
DATA_DIR="$(cd "$DATA_DIR" && pwd -P)"
for sub in results work log; do
	if [ ! -d "$DATA_DIR/$sub" ]; then
		say "ERROR: $DATA_DIR has no $sub/ subdirectory; refusing (wrong path?)"
		exit 2
	fi
done

n_work=0; n_zip=0; n_dirs=0; n_log=0

# 1. Stale workspaces: any work/<uuid> dir older than WORK_DAYS. A live build
#    never runs that long; a crashed run's next attempt starts from a fresh dir.
while IFS= read -r d; do
	[ -n "$d" ] || continue
	act "remove stale workspace $d"
	[ "$DRY" = 1 ] || rm -rf -- "$d"
	n_work=$((n_work + 1))
done < <(find "$DATA_DIR/work" -mindepth 1 -maxdepth 1 -type d -mmin +$((WORK_DAYS * 1440)) 2>/dev/null)

# 2. Results past retention + 1 day (the worker should already have expired
#    them and flipped the row; the download endpoint handles a missing file).
while IFS= read -r f; do
	[ -n "$f" ] || continue
	act "remove expired result $f"
	[ "$DRY" = 1 ] || rm -f -- "$f"
	n_zip=$((n_zip + 1))
done < <(find "$DATA_DIR/results" -mindepth 2 -maxdepth 2 -type f -name '*.zip' -mmin +$((RESULT_DAYS * 1440)) 2>/dev/null)

# 2b. Empty per-user result dirs older than a day (the worker recreates them).
while IFS= read -r d; do
	[ -n "$d" ] || continue
	act "remove empty result dir $d"
	[ "$DRY" = 1 ] || rmdir -- "$d" 2>/dev/null || true
	n_dirs=$((n_dirs + 1))
done < <(find "$DATA_DIR/results" -mindepth 1 -maxdepth 1 -type d -empty -mmin +1440 2>/dev/null)

# 3. Logs: rotate once (keep one previous generation) when above LOG_MAX_MB.
#    The worker and the endpoints reopen the log on every append, so a
#    rename is safe; a kicked worker mid-run keeps writing to the rotated
#    file until it finishes, which is fine.
while IFS= read -r f; do
	[ -n "$f" ] || continue
	act "rotate $f -> $f.1"
	[ "$DRY" = 1 ] || mv -f -- "$f" "$f.1"
	n_log=$((n_log + 1))
done < <(find "$DATA_DIR/log" -mindepth 1 -maxdepth 1 -type f -name '*.log' -size +"${LOG_MAX_MB}M" 2>/dev/null)

# 4. Fieldbook basemap tile cache (tilecache/<set>/z/x/y.png, docs/Fieldbook_Design.md §6) and
#    photo thumbnail cache (thumbcache/<id>_<px>.jpg, §8): files untouched for TILE_DAYS, then
#    the empty directories they leave.
n_tiles=0; n_tdirs=0
if [ -d "$DATA_DIR/thumbcache" ]; then
	while IFS= read -r f; do
		[ -n "$f" ] || continue
		[ "$DRY" = 1 ] && act "remove stale thumbnail $f"
		[ "$DRY" = 1 ] || rm -f -- "$f"
		n_tiles=$((n_tiles + 1))
	done < <(find "$DATA_DIR/thumbcache" -mindepth 1 -maxdepth 1 -type f \( -name '*.jpg' -o -name '*.tmp' \) -mmin +$((TILE_DAYS * 1440)) 2>/dev/null)
fi
if [ -d "$DATA_DIR/tilecache" ]; then
	while IFS= read -r f; do
		[ -n "$f" ] || continue
		[ "$DRY" = 1 ] && act "remove stale tile $f"
		[ "$DRY" = 1 ] || rm -f -- "$f"
		n_tiles=$((n_tiles + 1))
	done < <(find "$DATA_DIR/tilecache" -mindepth 4 -maxdepth 4 -type f \( -name '*.png' -o -name '*.tmp' \) -mmin +$((TILE_DAYS * 1440)) 2>/dev/null)
	while IFS= read -r d; do
		[ -n "$d" ] || continue
		[ "$DRY" = 1 ] || rmdir -- "$d" 2>/dev/null || true
		n_tdirs=$((n_tdirs + 1))
	done < <(find "$DATA_DIR/tilecache" -mindepth 1 -mindepth 1 -type d -empty 2>/dev/null | sort -r)
fi

# 5. Interactive Field Book builds (fieldbook/<key>.json, .pdf, .lock, .tmp/): a finished book is only
#    reused for two minutes and the page rebuilds on demand, so anything older than FIELDBOOK_HOURS goes.
n_fb=0
if [ -d "$DATA_DIR/fieldbook" ]; then
	while IFS= read -r f; do
		[ -n "$f" ] || continue
		[ "$DRY" = 1 ] && act "remove old field book build $f"
		[ "$DRY" = 1 ] || rm -rf -- "$f"
		n_fb=$((n_fb + 1))
	done < <(find "$DATA_DIR/fieldbook" -mindepth 1 -maxdepth 1 -mmin +$((FIELDBOOK_HOURS * 60)) 2>/dev/null)
fi

total=$((n_work + n_zip + n_dirs + n_log + n_tiles + n_fb))
if [ "$total" -gt 0 ] || [ "$DRY" = 1 ]; then
	say "done: $n_work workspaces, $n_zip results, $n_dirs empty dirs, $n_log logs rotated, $n_tiles stale tiles / thumbnails + $n_tdirs empty tile dirs, $n_fb field book builds ($DATA_DIR)"
fi
exit 0
