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

	var setup = function () {
		observer = new MutationObserver(onMutations);
		observer.observe(document.body, {
			childList: true,
			subtree: true,
		});
		trigger();
	};

	if (document.body) {
		setup();
	} else {
		document.addEventListener('DOMContentLoaded', setup);
	}
})();
