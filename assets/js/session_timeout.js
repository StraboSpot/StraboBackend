/**
 * File: session_timeout.js
 * Description: Auto-logout warning system. Loaded by includes/mheader.php on
 *              every page when the visitor is logged in, configured via
 *              window.straboSessionConfig (values come from
 *              includes/session_config.php so client and server agree).
 *
 *              Behavior:
 *              - Real user activity (mouse/keyboard/scroll/touch) quietly
 *                extends the session at most once per ACTIVITY_PING_MS, so
 *                someone working in a long form is never interrupted.
 *              - Shortly before the idle timeout, the server is polled
 *                (activity in another tab counts, so poll first). If the
 *                session really is about to expire, a modal with a live
 *                countdown offers "Stay Logged In".
 *              - No confirmation in time means a redirect to /loggedout.php,
 *                which explains what happened and offers a login link back
 *                to this page.
 *
 *              The status poll (session_time_left.php) never counts as
 *              activity; only session_extend.php resets the server clock.
 */

(function () {
	"use strict";

	var cfg = window.straboSessionConfig;
	if (!cfg || !cfg.timeoutSeconds) return;

	var TIMEOUT_MS = cfg.timeoutSeconds * 1000;
	var WARN_MS = cfg.warningSeconds * 1000;
	var ACTIVITY_PING_MS = 15 * 60 * 1000; // quiet auto-extend at most this often
	var RETRY_MS = 30 * 1000;              // status poll retry after network error

	// This page render just reset the server-side clock (every page that loads
	// mheader.php also runs sessioncheck.php, which stamps LAST_ACTIVITY).
	var expiresAt = Date.now() + TIMEOUT_MS;
	var lastPing = Date.now();
	var checkTimer = null;
	var countdownTimer = null;
	var warningShown = false;
	var redirecting = false;

	function fetchJson(url) {
		return fetch(url, { credentials: "same-origin", cache: "no-store" })
			.then(function (r) { return r.json(); });
	}

	function goToLoggedOutPage() {
		if (redirecting) return;
		redirecting = true;
		var here = window.location.pathname + window.location.search;
		window.location.href = "/loggedout.php?uri=" + encodeURIComponent(here);
	}

	// ---- warning modal ------------------------------------------------------

	function buildModal() {
		var overlay = document.createElement("div");
		overlay.id = "straboSessionOverlay";
		overlay.style.cssText = "display:none; position:fixed; top:0; left:0; right:0; bottom:0;" +
			"background:rgba(0,0,0,0.65); z-index:99999;";

		var box = document.createElement("div");
		box.style.cssText = "max-width:420px; margin:15vh auto 0 auto; background:#ffffff; color:#333333;" +
			"border-radius:6px; padding:2em; text-align:center; font-size:1em;" +
			"box-shadow:0 8px 30px rgba(0,0,0,0.4);";

		box.innerHTML =
			'<h3 style="margin:0 0 0.5em 0; color:#333333;">Are you still there?</h3>' +
			'<p style="margin:0 0 1em 0;">For your security, you will be automatically logged out in</p>' +
			'<p id="straboSessionCountdown" style="font-size:2em; margin:0 0 1em 0; font-weight:bold;"></p>' +
			'<p style="margin:0;">' +
			'<button type="button" id="straboSessionStay" style="cursor:pointer; padding:0.6em 1.4em;' +
			' font-size:1em; border:0; border-radius:4px; background:#e44c65; color:#ffffff;">Stay Logged In</button>' +
			'</p>' +
			'<p style="margin:1em 0 0 0; font-size:0.85em;"><a href="/logout" style="color:#e44c65;">Log out now</a></p>';

		overlay.appendChild(box);
		document.body.appendChild(overlay);

		document.getElementById("straboSessionStay").addEventListener("click", extendSession);
		return overlay;
	}

	function showWarning(remainingSeconds) {
		var overlay = document.getElementById("straboSessionOverlay") || buildModal();
		warningShown = true;
		overlay.style.display = "block";

		// Count down against a wall-clock deadline so throttled background-tab
		// timers stay accurate; they just tick less often.
		var deadline = Date.now() + remainingSeconds * 1000;
		var label = document.getElementById("straboSessionCountdown");

		function tick() {
			var left = Math.max(0, Math.round((deadline - Date.now()) / 1000));
			var m = Math.floor(left / 60);
			var s = left % 60;
			label.textContent = m + ":" + (s < 10 ? "0" : "") + s;
			if (left <= 0) {
				clearInterval(countdownTimer);
				countdownTimer = null;
				// Last chance: the session may have been extended from another
				// tab. Only a server-confirmed live session cancels the logout.
				fetchJson("/session_time_left.php").then(function (d) {
					if (d.loggedin && d.remaining * 1000 > WARN_MS) {
						hideWarning(d.remaining);
					} else {
						goToLoggedOutPage();
					}
				}).catch(goToLoggedOutPage);
			}
		}

		if (countdownTimer) clearInterval(countdownTimer);
		countdownTimer = setInterval(tick, 1000);
		tick();
	}

	function hideWarning(remainingSeconds) {
		warningShown = false;
		if (countdownTimer) { clearInterval(countdownTimer); countdownTimer = null; }
		var overlay = document.getElementById("straboSessionOverlay");
		if (overlay) overlay.style.display = "none";
		expiresAt = Date.now() + remainingSeconds * 1000;
		scheduleCheck();
	}

	// ---- server round trips -------------------------------------------------

	function extendSession() {
		fetchJson("/session_extend.php").then(function (d) {
			if (d.ok) {
				lastPing = Date.now();
				hideWarning(d.remaining);
			} else {
				goToLoggedOutPage();
			}
		}).catch(function () {
			// Transient failure: keep the modal up; the countdown's final
			// status check still decides the outcome.
		});
	}

	function checkStatus() {
		fetchJson("/session_time_left.php").then(function (d) {
			if (!d.loggedin || d.remaining <= 0) {
				goToLoggedOutPage();
			} else if (d.remaining * 1000 > WARN_MS) {
				// Activity elsewhere (another tab, an AJAX call) pushed the
				// expiry out. Re-sync and go back to sleep.
				expiresAt = Date.now() + d.remaining * 1000;
				scheduleCheck();
			} else {
				showWarning(d.remaining);
			}
		}).catch(function () {
			if (!warningShown && !redirecting) {
				checkTimer = setTimeout(checkStatus, RETRY_MS);
			}
		});
	}

	function scheduleCheck() {
		if (checkTimer) clearTimeout(checkTimer);
		var delay = Math.max(0, expiresAt - Date.now() - WARN_MS);
		checkTimer = setTimeout(checkStatus, delay);
	}

	// ---- quiet activity-based extension --------------------------------------

	function onActivity() {
		if (warningShown || redirecting) return;
		if (Date.now() - lastPing < ACTIVITY_PING_MS) return;
		lastPing = Date.now();
		fetchJson("/session_extend.php").then(function (d) {
			if (d.ok) {
				expiresAt = Date.now() + d.remaining * 1000;
				scheduleCheck();
			}
			// ok=false means already expired; leave it to the scheduled
			// check, which confirms and redirects.
		}).catch(function () { /* best effort */ });
	}

	["mousemove", "mousedown", "keydown", "scroll", "touchstart"].forEach(function (evt) {
		document.addEventListener(evt, onActivity, { passive: true });
	});

	// Laptop wake / tab re-focus: timers may not have fired while suspended,
	// so re-check immediately if we are near (or past) the warning window.
	document.addEventListener("visibilitychange", function () {
		if (document.visibilityState !== "visible") return;
		if (warningShown || redirecting) return;
		if (Date.now() >= expiresAt - WARN_MS) {
			if (checkTimer) clearTimeout(checkTimer);
			checkStatus();
		}
	});

	scheduleCheck();
})();
