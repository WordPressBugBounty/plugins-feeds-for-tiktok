/**
 * Smash Usage Tracking: session duration and record-event from JS.
 *
 * @package SmashBalloon\TikTokFeeds
 */
(function ($) {
	'use strict';

	if (typeof window.sbttSmashUsageSession === 'undefined') {
		return;
	}

	var config = window.sbttSmashUsageSession;
	var sessionStart = Date.now();
	var sessionSent = false;

	function sendSessionDuration() {
		var durationSeconds = Math.round((Date.now() - sessionStart) / 1000);
		// The threshold check must come BEFORE the latch: visibilitychange can
		// fire within the first seconds (tab switch, cmd-tab), and latching
		// there would permanently suppress the real session length recorded
		// on the eventual unload.
		if (sessionSent || durationSeconds < 3) {
			return;
		}
		sessionSent = true;
		if (navigator.sendBeacon) {
			var data = new FormData();
			data.append('action', 'sbtt_smash_usage_record_session');
			data.append('nonce', config.nonce);
			data.append('duration_seconds', durationSeconds);
			navigator.sendBeacon(config.ajax_url, data);
		} else {
			$.post(config.ajax_url, {
				action: 'sbtt_smash_usage_record_session',
				nonce: config.nonce,
				duration_seconds: durationSeconds
			});
		}
	}

	// visibilitychange fires when a tab is backgrounded or the app is
	// switched away from — the most reliable signal on mobile, where
	// beforeunload/pagehide are often not dispatched.
	document.addEventListener('visibilitychange', function () {
		if (document.visibilityState === 'hidden') {
			sendSessionDuration();
		}
	});

	$(window).on('beforeunload pagehide', function () {
		sendSessionDuration();
	});

})(jQuery);
