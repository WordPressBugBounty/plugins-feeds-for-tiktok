/**
 * Hydrates feed divs injected into the Gutenberg block editor by ServerSideRender.
 *
 * The framework block JS schedules window.sbttInitializeFeed() at 500/1500/3000ms
 * after mount, but in the iframed editor the async script load and SSR REST
 * round-trip can both miss those windows on a fresh page load. A MutationObserver
 * covers all timing scenarios. The wrap around sbttInitializeFeed makes repeated
 * calls idempotent so React's createRoot isn't invoked twice on the same node.
 */
(function () {
	if (typeof window.sbttInitializeFeed !== 'function') {
		return;
	}

	var original = window.sbttInitializeFeed;
	var mounted = new WeakSet();

	window.sbttInitializeFeed = function () {
		var divs = document.querySelectorAll('.sbtt-tiktok-feed');
		if (!divs.length) {
			return;
		}

		var hidden = [];
		divs.forEach(function (el) {
			if (mounted.has(el)) {
				el.classList.remove('sbtt-tiktok-feed');
				hidden.push(el);
			}
		});

		try {
			original();
		} catch (err) {
			// Surface init errors directly instead of letting the MutationObserver
			// frame swallow the stack trace.
			console.error('sbttInitializeFeed threw:', err);
		} finally {
			hidden.forEach(function (el) {
				el.classList.add('sbtt-tiktok-feed');
			});
			document.querySelectorAll('.sbtt-tiktok-feed').forEach(function (el) {
				mounted.add(el);
			});
		}
	};

	var SELECTOR = '.sbtt-tiktok-feed';
	var observer = null;

	var allMounted = function () {
		var divs = document.querySelectorAll(SELECTOR);
		if (!divs.length) {
			return false;
		}
		for (var i = 0; i < divs.length; i++) {
			if (!mounted.has(divs[i])) {
				return false;
			}
		}
		return true;
	};

	var trigger = function () {
		if (document.querySelector(SELECTOR)) {
			window.sbttInitializeFeed();
		}
		// Once every feed div is hydrated, stop listening — Gutenberg fires
		// mutations on every keystroke and a long-lived subtree observer is a
		// classic source of editor lag.
		if (observer && allMounted()) {
			observer.disconnect();
			observer = null;
		}
	};

	var hasFeedDiv = function (node) {
		if (!(node instanceof Element)) {
			return false;
		}
		return (node.matches && node.matches(SELECTOR)) || node.querySelector(SELECTOR);
	};

	var onMutations = function (mutations) {
		for (var i = 0; i < mutations.length; i++) {
			var added = mutations[i].addedNodes;
			for (var j = 0; j < added.length; j++) {
				if (hasFeedDiv(added[j])) {
					trigger();
					return;
				}
			}
		}
	};

	// MutationObserver.observe() throws a TypeError when handed anything that
	// isn't a node, so the target has to be checked at the call site and not only
	// before scheduling. This script is enqueued through enqueue_block_assets, so
	// WP 7.x runs it inside the editor canvas's blob: iframe — and in that iframe
	// DOMContentLoaded fires while document.body is still null (readyState is
	// already 'interactive' with no body), which is exactly what threw. Observing
	// documentElement instead covers body's own insertion plus everything under
	// it, so the observer attaches at the first opportunity rather than at a
	// DOM-ready event that has already passed the moment it needed.
	var OBSERVER_RETRY_INTERVAL_MS = 50;
	var OBSERVER_RETRY_LIMIT = 40; // ~2s, then give up rather than poll forever.
	var observerRetries = 0;
	var scheduleSetup;

	var setup = function () {
		// One observer, ever. trigger() nulls this out when it disconnects, so a
		// later re-attach is still possible; what this prevents is reassigning
		// over a live observer and orphaning it beyond trigger()'s reach.
		if (observer) {
			return;
		}

		// documentElement is present even before body is parsed. body is preferred
		// when it already exists — the non-iframed admin footer case, where this is
		// byte-for-byte the old behaviour. In the editor iframe body does not exist
		// yet, so documentElement is the root for the observer's whole lifetime;
		// that is a strict superset of body's subtree, not a different target.
		var root = document.body || document.documentElement;

		if (!root) {
			// Neither node exists yet — nothing valid to hand observe(), so retry
			// instead of throwing. This is reachable: probing the editor iframe on
			// WP 7.1-RC3 hit it once in six loads, where the blob: document had
			// momentarily no documentElement either.
			scheduleSetup();
			return;
		}

		observer = new MutationObserver(onMutations);
		observer.observe(root, {
			childList: true,
			subtree: true,
		});
		trigger();
	};

	scheduleSetup = function () {
		if (document.body || document.documentElement) {
			setup();
			return;
		}

		if (observerRetries < OBSERVER_RETRY_LIMIT) {
			observerRetries++;
			window.setTimeout(setup, OBSERVER_RETRY_INTERVAL_MS);
		}
	};

	scheduleSetup();
})();
