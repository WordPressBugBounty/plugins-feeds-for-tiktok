/**
 * Inserts the TikTok Feed block for the feed chosen in the Embed modal's
 * "Add and Go to Page", then drops sbtt_wizard so a reload does not insert again.
 * Skips the insert when that feed's block is already on the page.
 */
(function (wp, config) {
	// wp_localize_script turns 0 into "0", so parse it.
	var feedId = String(parseInt(config && config.feedId, 10) || '');
	var name = 'smashballoon/tiktok-feed';
	if (!feedId || !wp || !wp.data || !wp.domReady) {
		return;
	}
	// Set when the server could not register the block (WordPress before 5.8), so an
	// inserted block would render nothing: give the shortcode instead.
	if (config.notice) {
		wp.domReady(function () {
			wp.data.dispatch('core/notices').createWarningNotice(config.notice, {id: 'sbtt-add-to-page'});
		});
		return;
	}
	if (!wp.blocks || !wp.blocks.getBlockType(name)) {
		return;
	}
	var done = false;
	var unsubscribe;
	var blocksWaitOver = false;
	var waitTimer;
	var hasFeedBlock = function (blocks) {
		return blocks.some(function (b) {
			return (b.name === name && String(b.attributes.feedId) === feedId)
				|| hasFeedBlock(b.innerBlocks || []);
		});
	};
	var tryInsert = function () {
		var editor = wp.data.select('core/editor');
		var store = wp.data.select('core/block-editor') ? 'core/block-editor' : 'core/editor';
		// __unstableIsEditorReady turns true before core loads the saved blocks (seen on WP 6.1),
		// and that load overwrites anything inserted earlier. So also wait for the post to load
		// (content set) and the block list to hold the saved content; a post with none has nothing
		// to wait for. The wait lasts up to 5s after the editor is ready, so content that parses to no blocks cannot hold it forever.
		var post = editor && editor.getCurrentPostId() && editor.getCurrentPost();
		var saved = post ? post.content : '';
		saved = String((saved && saved.raw !== undefined ? saved.raw : saved) || '').trim();
		var blocks = post ? wp.data.select(store).getBlocks() : [];
		if (done || !post || (editor.__unstableIsEditorReady && !editor.__unstableIsEditorReady())) {
			return;
		}
		// Start the 5s cap when the editor first reports ready, so a slow editor still gets the wait.
		if (!waitTimer) {
			waitTimer = setTimeout(function () {
				blocksWaitOver = true;
				tryInsert();
			}, 5000);
		}
		if (!blocksWaitOver && (post.content === undefined || (saved !== '' && !blocks.length))) {
			return;
		}
		done = true;
		if (unsubscribe) {
			unsubscribe();
		}
		if (!hasFeedBlock(blocks)) {
			// Write to the post itself: an insert into core/block-editor shows but is not saved.
			// resetEditorBlocks exists since WP 5.3, and the block only registers on WP 5.8+.
			var block = wp.blocks.createBlock(name, {feedId: feedId});
			wp.data.dispatch('core/editor').resetEditorBlocks(editor.getEditorBlocks().concat(block));
		}
		try {
			var url = new URL(window.location.href);
			url.searchParams.delete('sbtt_wizard');
			window.history.replaceState(window.history.state, '', url.toString());
		} catch (e) {}
	};
	// Core sets up the editor in a domReady callback, after footer scripts run.
	wp.domReady(function () {
		tryInsert();
		if (!done) {
			unsubscribe = wp.data.subscribe(tryInsert);
			// Stop listening if the editor never reports ready.
			setTimeout(function () {
				if (!done) {
					done = true;
					unsubscribe();
				}
			}, 30000);
		}
	});
})(window.wp, window.sbttAddToPage);
