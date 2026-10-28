<?php

namespace SmashBalloon\TikTokFeeds\Common\Abilities\Services;

use SmashBalloon\TikTokFeeds\Common\Database\FeedsTable;
use SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_Edition;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Reads/writes Feed Builder feeds for the Abilities API.
 *
 * Wraps FeedsTable and REDACTS everything an agent must not see: the feed's
 * connected sources are reduced to open_id + display_name (never
 * access_token, refresh_token or the raw `info` blob — see
 * SBTT_Source_Service).
 *
 * CACHE NOTE (verified against AjaxHandlerService::builder_update(), the
 * plugin's own settings-save AJAX handler): a normal settings save — feed
 * settings OR a change of sources — never calls FeedCache::clear() anywhere
 * in that handler. Posts are re-fetched purely on TTL expiry
 * (FeedCache::retrieve_and_set_feed_cache()), not on a settings write. Hard
 * rule #11 in the port brief is "replicate the plugin's own clear-caches path
 * faithfully… no invented cache logic" — so this service intentionally does
 * NOT call FeedCache::clear() after update_feed()/set_feed_sources() either.
 * This is a real, if pre-existing, staleness window (a source swap keeps
 * showing the old account's cached videos until the TTL lapses); it is not
 * something this port introduces, so it isn't papered over with new
 * cache-clearing logic the native save path doesn't have.
 *
 * OBJECT-CACHE NOTE (Rex round 1 — data-loss hazard, distinct from the
 * FeedCache note above; widened at Alex's PR #120 review — see below):
 * FeedsTable::get_feed() (FeedsTable.php:187-205) memoizes the row it reads
 * into the `tiktok-feeds` wp_cache GROUP for 24h, and NEITHER
 * FeedsTable::update() NOR FeedsTable::delete_feeds() invalidates that entry.
 * Under a persistent object cache (Redis/Memcached) this is a real data-loss
 * hazard specific to how this service works: update_feed() and
 * set_feed_sources() both do a PARTIAL merge starting from
 * FeedsTable::get_feed()'s result, so a stale cached row would silently
 * revert whatever key an agent just wrote on the very next call, get_feed()
 * would keep reporting the pre-write value for up to 24h, and a deleted feed
 * could keep resolving (letting request-feed-deletion mint a link for a
 * feed that no longer exists). Unlike the FeedCache note above, this is NOT
 * "the native path doesn't do this either" — the native path never does a
 * PARTIAL merge from a cached read, so it never depended on that cache being
 * fresh the way these abilities do.
 *
 * PRE-READ, not just post-write (Alex's finding on PR #120): invalidating
 * only AFTER a successful write closes ability-write → ability-write, but
 * AjaxHandlerService::builder_update() → FeedsTable::update_or_insert() never
 * calls invalidate_cached_feed() — it's native code, out of this port's
 * scope — so a native Feed Builder save always leaves (or primes) a stale
 * cached row. The very next ability call that reads get_feed() as a merge
 * base would build on that stale row and silently revert the admin's save.
 * So every read that becomes a merge base, or feeds an agent's view, calls
 * invalidate_cached_feed() IMMEDIATELY BEFORE FeedsTable::get_feed() — in
 * get_feed() above, update_feed(), and set_feed_sources() below, plus
 * SBTT_Request_Feed_Deletion::execute() and
 * SBTT_Delete_Confirmation::maybe_render(). The post-write call after every
 * successful write (including from SBTT_Delete_Confirmation::handle_delete())
 * stays too — it's what keeps a read made entirely outside this class (e.g.
 * directly via `new FeedsTable())->get_feed()`) fresh immediately after an
 * ability write, without waiting for that other caller to also invalidate
 * before its own read. Builder key construction stays the one place
 * (invalidate_cached_feed() below); FeedsTable itself is left untouched —
 * its own staleness bug is tracked separately.
 *
 * @since SBR-501
 */
class SBTT_Feed_Settings_Service
{
	/** Highest per_page value list-feeds will honour. */
	public const MAX_PER_PAGE = 50;

	/**
	 * Pro-only keys the free edition drops from its schema but still applies
	 * at render time when a value was saved while the site ran Pro
	 * (Common\Feed::filter_posts() / sort_posts(), and the stored layout).
	 */
	private const FREE_RENDERED_PRO_KEYS = array('layout', 'includeWords', 'excludeWords', 'sortRandomEnabled');

	/**
	 * Invalidate FeedsTable::get_feed()'s per-id object-cache entry.
	 *
	 * The key construction here MUST exactly match FeedsTable::get_feed()
	 * (FeedsTable.php:187: `'get_feed_by_id' . $feed_id` in the `tiktok-feeds`
	 * group) — kept in this ONE place, rather than duplicated at every call
	 * site, so the two can't silently drift apart. Public (not private)
	 * because SBTT_Delete_Confirmation::handle_delete() — a different class —
	 * must call this same invalidation after FeedsTable::delete_feeds().
	 *
	 * @param int $feed_id Feed id.
	 * @return void
	 */
	public static function invalidate_cached_feed($feed_id)
	{
		// absint(), not (int): FeedsTable::get_feed() (FeedsTable.php:186) builds
		// its cache key with absint($feed_id) too, so the key construction here
		// must literally match, not merely produce the same value for every
		// input the docblock above promises.
		wp_cache_delete('get_feed_by_id' . absint($feed_id), 'tiktok-feeds');
	}

	/**
	 * List feeds, paginated.
	 *
	 * @param int $page     1-based.
	 * @param int $per_page Results per page.
	 * @return array{feeds: array, total: int, page: int, per_page: int, total_pages: int}
	 */
	public function list_feeds($page = 1, $per_page = 20)
	{
		global $wpdb;

		$page     = max(1, (int) $page);
		$per_page = min(self::MAX_PER_PAGE, max(1, (int) $per_page));
		$offset   = ($page - 1) * $per_page;
		$table    = $wpdb->prefix . SBTT_FEEDS_TABLE;

		$sql = $wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + constant.
			"SELECT id, feed_name, feed_title, settings, status, last_modified FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d",
			$per_page,
			$offset
		);
		$rows = $wpdb->get_results($sql, ARRAY_A); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql is built via $wpdb->prepare() above.

		$feeds = array();
		foreach ((array) $rows as $row) {
			$settings = json_decode($row['settings'], true);
			$settings = is_array($settings) ? $settings : array();
			$feeds[]  = array(
				'id'            => (int) $row['id'],
				'feed_name'     => (string) $row['feed_name'],
				'feed_title'    => (string) $row['feed_title'],
				'layout'        => isset($settings['layout']) ? (string) $settings['layout'] : 'grid',
				'status'        => (string) $row['status'],
				'last_modified' => (string) $row['last_modified'],
				'shortcode'     => SBTT_Edition::shortcode($row['id']),
			);
		}

		$total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + constant, no user input.

		return array(
			'feeds'       => $feeds,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total > 0 ? (int) ceil($total / $per_page) : 0,
		);
	}

	/**
	 * Read one feed's redacted, schema-cast settings.
	 *
	 * @param int $feed_id Feed id.
	 * @return array|null Null when the feed does not exist.
	 */
	public function get_feed($feed_id)
	{
		$feed_id = (int) $feed_id;
		if ($feed_id <= 0) {
			return null;
		}

		// See the OBJECT-CACHE NOTE on this class: invalidate BEFORE the read, not
		// just after a write, so a read that follows a native Feed Builder save
		// (which never calls invalidate_cached_feed() itself) can't return the
		// stale pre-save row for up to 24h.
		self::invalidate_cached_feed($feed_id);
		$row = (new FeedsTable())->get_feed($feed_id);
		if (!$row) {
			return null;
		}

		$stored = isset($row['settings']) ? json_decode($row['settings'], true) : array();
		$stored = is_array($stored) ? $stored : array();
		$defaults = sbtt_feed_settings_defaults();

		// contentLength — the front-end template (Caption.js:
		// `feedSettings?.contentLength || 100`) treats ANY falsy stored value
		// (0, '', null, or simply absent) as "use the plugin default", so a
		// stored `0` is, at render time, indistinguishable from an untouched
		// key — the same legal-in-range-masks-a-default conflation as the
		// storiestime pattern. Resolve it to the real default here, at the
		// SERVICE layer, BEFORE wp_parse_args()/cast_out() see it, so a read
		// never reports a truncation length the feed does not actually apply
		// (AgDR-9004 requirement 1).
		if (empty($stored['contentLength'])) {
			unset($stored['contentLength']);
		}

		// Hydrated with the plugin's own defaults, READ-side only — never persisted.
		$hydrated = wp_parse_args($stored, $defaults);

		// Read schema: on free a kept key's enum stays full so a value stored
		// while the site was Pro reads back as stored (AgDR-9005).
		$schema   = SBTT_Settings_Schema::for_edition(SBTT_Settings_Schema::feed_settings(), true);
		$out      = array();
		$warnings = array();
		foreach ($schema as $key => $def) {
			if ($key === 'feed_name') {
				continue; // Top-level FeedsTable column, added below.
			}
			$out[$key] = SBTT_Settings_Schema::cast_out(
				$def,
				isset($hydrated[$key]) ? $hydrated[$key] : null,
				isset($defaults[$key]) ? $defaults[$key] : null
			);
		}
		$out['feed_name'] = isset($row['feed_name']) ? (string) $row['feed_name'] : '';

		if (!SBTT_Edition::is_pro()) {
			$warnings = self::stored_pro_value_warnings(
				SBTT_Settings_Schema::feed_settings(),
				SBTT_Settings_Schema::for_edition(SBTT_Settings_Schema::feed_settings()),
				$out
			);
			$warnings = array_merge($warnings, self::stored_pro_setting_warnings($hydrated, $defaults));
		}

		$result = array(
			'id'            => $feed_id,
			'feed_title'    => isset($row['feed_title']) ? (string) $row['feed_title'] : '',
			'status'        => isset($row['status']) ? (string) $row['status'] : '',
			'last_modified' => isset($row['last_modified']) ? (string) $row['last_modified'] : '',
			'shortcode'     => SBTT_Edition::shortcode($feed_id),
			'sources'       => $this->redact_sources(isset($hydrated['sources']) ? $hydrated['sources'] : array()),
			'settings'      => $out,
		);
		// Only present when there is something to say, so the Pro payload is
		// unchanged.
		if (!empty($warnings)) {
			$result['warnings'] = $warnings;
		}

		return $result;
	}

	/**
	 * Read-side downgrade warnings (AgDR-9005): a kept key whose STORED value
	 * is one the free edition cannot write (e.g. `sortFeedsBy: likes` saved
	 * while the site was Pro). The value is returned as stored and still
	 * applies at render time; the note says free cannot set it.
	 *
	 * @param array $full_schema  Unfiltered feed_settings() table (carries tier_values).
	 * @param array $write_schema for_edition() write table.
	 * @param array $out          Settings as read (cast) for the response.
	 * @return array<int, array{key: string, reason: string, message: string}>
	 */
	private static function stored_pro_value_warnings(array $full_schema, array $write_schema, array $out)
	{
		$warnings = array();
		foreach ($write_schema as $key => $write_def) {
			if (!isset($full_schema[$key]['tier_values']) || !isset($out[$key])) {
				continue;
			}
			if (isset($write_def['enum']) && in_array($out[$key], $write_def['enum'], true)) {
				continue;
			}
			$warnings[] = array(
				'key'     => $key,
				'reason'  => 'stored_pro_value',
				'message' => sprintf(
					/* translators: 1: setting key, 2: stored value */
					__(
						'%1$s is stored as "%2$s", a Pro setting that this edition cannot set. It is reported as stored and is still applied to the feed; only the free values can be written.',
						'feeds-for-tiktok'
					),
					$key,
					is_scalar($out[$key]) ? (string) $out[$key] : ''
				),
			);
		}
		return $warnings;
	}

	/**
	 * Read-side downgrade warnings for Pro-only KEYS (AgDR-9005): for_edition()
	 * drops these from the free schema, but the free renderer still applies
	 * their stored value (Common\Feed::filter_posts() / sort_posts(), and the
	 * stored layout). Without a note, a feed saved while the site was Pro reads
	 * back as an unfiltered, newest-first grid while it renders differently.
	 *
	 * @param array $hydrated Stored settings hydrated with the defaults.
	 * @param array $defaults sbtt_feed_settings_defaults().
	 * @return array<int, array{key: string, reason: string, message: string}>
	 */
	private static function stored_pro_setting_warnings(array $hydrated, array $defaults)
	{
		$warnings = array();
		foreach (self::FREE_RENDERED_PRO_KEYS as $key) {
			$value   = isset($hydrated[$key]) ? $hydrated[$key] : null;
			$default = isset($defaults[$key]) ? $defaults[$key] : null;
			if ($value === $default || $value === null || $value === '' || $value === false) {
				continue;
			}
			$warnings[] = array(
				'key'     => $key,
				'reason'  => 'stored_pro_setting',
				'message' => sprintf(
					/* translators: 1: setting key, 2: stored value */
					__(
						'%1$s is stored as "%2$s", a Pro setting saved while this site ran TikTok Feeds Pro. '
							. 'It still applies to the feed, but this edition cannot read it through the settings '
							. 'or change it; only TikTok Feeds Pro can.',
						'feeds-for-tiktok'
					),
					$key,
					is_bool($value) ? ($value ? 'true' : 'false') : (is_scalar($value) ? (string) $value : '')
				),
			);
		}
		return $warnings;
	}

	/**
	 * Write-side twin of stored_pro_setting_warnings() for `layout`: on free the
	 * grid keys are writable, but they have no visible effect on a feed whose
	 * stored layout is a Pro one (list, carousel, ...), which free still renders.
	 *
	 * @param string[] $written Keys actually written this call.
	 * @param array    $final   Final, merged settings (post-write).
	 * @return array<int, array{key: string, reason: string, message: string}>
	 */
	private static function stored_pro_layout_warnings(array $written, array $final)
	{
		$layout = isset($final['layout']) ? (string) $final['layout'] : 'grid';
		if (SBTT_Edition::is_pro() || $layout === 'grid') {
			return array();
		}
		$warnings = array();
		foreach ($written as $key) {
			if (strpos($key, 'grid') !== 0) {
				continue;
			}
			$warnings[] = array(
				'key'     => $key,
				'reason'  => 'accepted_but_inert',
				'message' => sprintf(
					/* translators: 1: setting key, 2: stored layout */
					__(
						'%1$s was saved but has no visible effect: this feed is stored with the Pro layout "%2$s", '
							. 'which it still uses. Only TikTok Feeds Pro can change the layout.',
						'feeds-for-tiktok'
					),
					$key,
					$layout
				),
			);
		}
		return $warnings;
	}

	/**
	 * Keep only what identifies the account. Never tokens or the `info` blob.
	 *
	 * @param array $open_ids Raw stored open_ids for one feed.
	 * @return array<int, array{open_id: string, display_name: string}>
	 */
	private function redact_sources($open_ids)
	{
		$open_ids = array_values(array_filter((array) $open_ids, 'strlen'));
		if (empty($open_ids)) {
			return array();
		}
		$resolved = (new SBTT_Source_Service())->resolve_sources(array_map('strval', $open_ids));
		return $resolved['details'];
	}

	/**
	 * Apply a partial update to one feed's settings.
	 *
	 * FeedsTable::update() JSON-encodes and REPLACES the whole `settings`
	 * column with whatever array is passed in, so this merges into the RAW
	 * stored row (json_decode of the DB column) rather than the
	 * defaults-hydrated view get_feed() returns — persisting hydrated defaults
	 * back to the row would silently pin every never-touched key to today's
	 * plugin defaults forever.
	 *
	 * @param int      $feed_id        Feed id.
	 * @param array    $partial        key => value, already schema-validated by core.
	 * @param callable $tier_available fn(string $feature): bool.
	 * @return array{feed_id: int, updated: string[], ignored: object}|\WP_Error|null
	 *         Null when the feed does not exist.
	 */
	public function update_feed($feed_id, array $partial, $tier_available)
	{
		$feed_id     = (int) $feed_id;
		$feeds_table = new FeedsTable();
		// See the OBJECT-CACHE NOTE on this class: this read is the merge base for
		// the partial update below, so it must be invalidated BEFORE the read, not
		// only after the write — otherwise a stale row from a native Feed Builder
		// save (which never invalidates) would get merged into and silently
		// revert what this call writes.
		self::invalidate_cached_feed($feed_id);
		$row         = $feeds_table->get_feed($feed_id);
		if ($feed_id <= 0 || !$row) {
			return null;
		}
		$stored = isset($row['settings']) ? json_decode($row['settings'], true) : array();
		$stored = is_array($stored) ? $stored : array();

		$schema   = SBTT_Settings_Schema::for_edition(SBTT_Settings_Schema::feed_settings());
		$writable = SBTT_Settings_Schema::writable($schema);
		$updated  = array();
		$ignored  = array();
		$new_name = null;

		foreach ($partial as $key => $value) {
			if (!isset($schema[$key])) {
				$ignored[$key] = 'unknown_setting';
				continue;
			}
			if (!isset($writable[$key])) {
				$ignored[$key] = 'read_only';
				continue;
			}
			$v = SBTT_Settings_Schema::validate_in($schema[$key], $value, $key);
			if (is_wp_error($v)) {
				return $v;
			}
			$tier = SBTT_Settings_Schema::required_tier($schema[$key], $v);
			if ($tier !== null && !call_user_func($tier_available, $tier)) {
				$ignored[$key] = 'feature_unavailable';
				continue;
			}
			if ($key === 'feed_name') {
				$new_name  = $v;
				$updated[] = $key;
				continue;
			}
			$stored[$key] = $v;
			$updated[]    = $key;
		}

		if (empty($updated)) {
			return array(
				'feed_id'  => $feed_id,
				'updated'  => array(),
				'ignored'  => (object) $ignored,
				'warnings' => array(),
			);
		}

		$data = array(
			'id'            => $feed_id,
			'feed_name'     => $new_name !== null ? $new_name : (isset($row['feed_name']) ? $row['feed_name'] : ''),
			'feed_title'    => isset($row['feed_title']) ? $row['feed_title'] : '',
			'settings'      => $stored,
			'feed_style'    => isset($row['feed_style']) ? $row['feed_style'] : '',
			'last_modified' => gmdate('Y-m-d H:i:s'),
		);
		if ($feeds_table->update($data, array('id' => $feed_id)) === false) {
			return new \WP_Error(
				'sbtt_feed_save_failed',
				__('The feed could not be saved.', 'feeds-for-tiktok'),
				array('status' => 500)
			);
		}
		// See the OBJECT-CACHE NOTE on this class: without this, a stale cached
		// row from FeedsTable::get_feed() would silently revert this write on
		// the next partial-merge update.
		self::invalidate_cached_feed($feed_id);

		/**
		 * Fires after an AI agent updated a feed through the Abilities API.
		 *
		 * @param int      $feed_id
		 * @param string[] $updated Keys that were written.
		 */
		do_action('sbtt_abilities_feed_settings_updated', $feed_id, $updated);

		// AgDR-9004 requirement 3: flag any just-written key whose schema
		// `condition` (layout/postStyle) is not satisfied by the FINAL merged
		// settings — the value is stored (it will apply once the condition is
		// met), so this is a warning, not a rejection.
		$warnings = SBTT_Settings_Schema::condition_warnings($schema, $updated, $stored);
		$warnings = array_merge($warnings, self::content_length_fallback_warning($updated, $stored));
		// Colour (and non-grid spacing) keys style the live page through the
		// pre-rendered feed_style CSS, which only the Feed Builder regenerates.
		$warnings = array_merge($warnings, SBTT_Settings_Schema::feed_style_warnings($updated, $stored, $feed_id));
		$warnings = array_merge($warnings, self::stored_pro_layout_warnings($updated, $stored));

		return array(
			'feed_id'  => $feed_id,
			'updated'  => $updated,
			'ignored'  => (object) $ignored,
			'warnings' => $warnings,
		);
	}

	/**
	 * Flag a just-written `contentLength: 0` (or `''`): the front-end template
	 * (Caption.js: `feedSettings?.contentLength || 100`) treats it as "use the
	 * plugin default", not as a 0-character truncation — the write succeeds
	 * and IS stored as 0, but the rendered feed applies the plugin default
	 * instead (AgDR-9004 requirement 3: signal a value that fell to a
	 * default, the write-side twin of the read-side fix in get_feed()).
	 *
	 * @param string[] $written Keys actually written this call.
	 * @param array    $final   Final, merged settings (post-write).
	 * @return array<int, array{key: string, reason: string, message: string}>
	 */
	private static function content_length_fallback_warning(array $written, array $final)
	{
		if (!in_array('contentLength', $written, true) || !empty($final['contentLength'])) {
			return array();
		}
		$default = sbtt_feed_settings_defaults();
		$default = isset($default['contentLength']) ? $default['contentLength'] : 100;
		return array(
			array(
				'key'     => 'contentLength',
				'reason'  => 'fell_to_default',
				'message' => sprintf(
					/* translators: %d: the plugin's real default caption length */
					__(
						'contentLength was saved as 0, but the feed template treats 0 as "use the plugin default" '
							. 'rather than a 0-character truncation; the applied caption length is %d.',
						'feeds-for-tiktok'
					),
					(int) $default
				),
			),
		);
	}

	/**
	 * Tier-gate a requested feed template, expand it, and drop back to the
	 * plugin's own default for anything the expansion changed that the
	 * caller's tier can't reach.
	 *
	 * TEMPLATE GATE: expansion writes `layout` (and other settings keys)
	 * directly via Utils::get_feed_settings_by_feed_templates() — that
	 * bypassed `layout`'s own tier_values gate entirely (Rex PR #120
	 * round-1 finding #3), e.g. a basic-tier `feedTemplate: carousel`
	 * silently set `layout: carousel` with no check. feedTemplate's own
	 * `tier_values` mirrors the plugin's basic_templates/plus_templates
	 * feature gates and is checked BEFORE expansion.
	 *
	 * DROP-AND-WARN, NOT REFUSE (round-3 finding, the operation/optional-input
	 * split): a create-feed call whose only unavailable ingredient is the
	 * OPTIONAL `feedTemplate` input is not a gated OPERATION — the operation
	 * is "create a feed", and that succeeds at every tier. `feedTemplate`
	 * has a valid, always-available fallback ('default'), so this drops to
	 * it and reports the substitution via `ignored.feedTemplate` +
	 * `warnings`, the same optional-input posture the plain `settings` loop
	 * in create_feed() already uses for any other gated key. This mirrors
	 * the family rule other SB feed plugins' abilities modules converged on
	 * in this same review round: 403 `*_tier_locked` stays reserved for a
	 * gated OPERATION (multi-source create without `combine_feed_sources`,
	 * below, keeps its 403 — there is no single-source fallback for
	 * "combine two feeds into one").
	 *
	 * POST-EXPANSION RE-CHECK, SCOPED (round-2 finding #1): re-checks ONLY
	 * the keys the expansion actually CHANGED relative to the
	 * pre-expansion defaults, and only keys whose gate is VALUE-dependent
	 * (`tier_values` — layout/postStyle/videoPlayer/sortFeedsBy). The prior
	 * version walked the WHOLE post-expansion blob, which already carries
	 * every sbtt_feed_settings_defaults() default — including plus-tier
	 * defaults like masonryDesktopColumns, carousel-prefixed and
	 * gallery-prefixed columns. Those carry a
	 * whole-key `tier` that fails regardless of value, so on a basic
	 * licence it fired on EVERY create-feed call (even `feedTemplate:
	 * default`) and silently downgraded valid basic templates (cards/list/
	 * latest_video) to `default`. Restricting to CHANGED, tier_VALUES-gated
	 * keys means this only fires if a template ever sets a tier-gated value
	 * inconsistent with its own tier_values gate on feedTemplate above —
	 * belt-and-braces, not expected to trigger for any shipped template. On
	 * the (unexpected) case it does trigger, the same drop-and-warn posture
	 * applies: the individual expanded key falls back to its
	 * pre-expansion default rather than refusing the whole create.
	 *
	 * @param array<string, array<string, mixed>> $schema        feed_settings() schema table.
	 * @param string                              $feed_template  Requested (or default) template slug.
	 * @param callable                            $tier_available fn(string $feature): bool.
	 * @return array{settings_data: array, ignored: array<string, string>, warnings: array} Expanded
	 *         settings data, plus anything downgraded to the default along the way.
	 */
	private static function resolve_template_settings(array $schema, $feed_template, $tier_available)
	{
		$ignored  = array();
		$warnings = array();

		if (isset($schema['feedTemplate'])) {
			$template_tier = SBTT_Settings_Schema::required_tier($schema['feedTemplate'], $feed_template);
			if ($template_tier !== null && !call_user_func($tier_available, $template_tier)) {
				$ignored['feedTemplate'] = 'feature_unavailable';
				$warnings[]              = array(
					'key'     => 'feedTemplate',
					'reason'  => 'feature_unavailable',
					'message' => sprintf(
						/* translators: 1: requested template slug, 2: the tier feature it needs */
						__(
							'The "%1$s" feed template needs a higher TikTok Feeds Pro license tier (%2$s); '
								. 'the feed was created with the default template instead.',
							'feeds-for-tiktok'
						),
						$feed_template,
						$template_tier
					),
				);
				$feed_template = 'default';
			}
		}

		$pre_expansion = array(
			'feedType'     => 'own_timeline',
			'sources'      => array(),
			'feedTemplate' => $feed_template,
		);
		$pre_expansion = wp_parse_args($pre_expansion, sbtt_feed_settings_defaults());
		// Same template expansion the builder applies on new_insert.
		$settings_data = \SmashBalloon\TikTokFeeds\Common\Utils::get_feed_settings_by_feed_templates($pre_expansion);

		foreach ($settings_data as $expanded_key => $expanded_value) {
			if (!isset($schema[$expanded_key]) || !isset($schema[$expanded_key]['tier_values'])) {
				continue;
			}
			if (array_key_exists($expanded_key, $pre_expansion) && $pre_expansion[$expanded_key] === $expanded_value) {
				continue; // Expansion didn't change this key — nothing new to check.
			}
			$expanded_tier = SBTT_Settings_Schema::required_tier($schema[$expanded_key], $expanded_value);
			if ($expanded_tier !== null && !call_user_func($tier_available, $expanded_tier)) {
				$ignored[$expanded_key] = 'feature_unavailable';
				$warnings[]             = array(
					'key'     => $expanded_key,
					'reason'  => 'feature_unavailable',
					'message' => sprintf(
						/* translators: 1: setting key, 2: the tier feature it needs */
						__(
							'%1$s needs a higher TikTok Feeds Pro license tier (%2$s); '
								. 'the plugin default was used instead.',
							'feeds-for-tiktok'
						),
						$expanded_key,
						$expanded_tier
					),
				);
				$settings_data[$expanded_key] = $pre_expansion[$expanded_key];
			}
		}

		return array(
			'settings_data' => $settings_data,
			'ignored'       => $ignored,
			'warnings'      => $warnings,
		);
	}

	/**
	 * Create a feed the way the builder's "new insert" path does: template
	 * defaults + validated quick settings + resolved, already-connected sources.
	 *
	 * @param array    $input          {feed_name?, feedTemplate?, sources?, settings?}.
	 * @param callable $tier_available fn(string $feature): bool.
	 * @return array|\WP_Error
	 */
	public function create_feed(array $input, $tier_available)
	{
		$schema        = SBTT_Settings_Schema::for_edition(SBTT_Settings_Schema::feed_settings());
		$ignored       = array();
		// The free edition has no feedTemplate key (only `default` is unlocked),
		// so it can never be picked there even if a caller passes one.
		$feed_template = (isset($schema['feedTemplate']) && !empty($input['feedTemplate']))
			? (string) $input['feedTemplate']
			: 'default';

		// Tier-gate the template, expand it, and drop back to the plugin's
		// own default for anything the expansion changed that this tier
		// can't reach — see resolve_template_settings()'s own docblock for
		// the full reasoning (round-1 finding #3, round-2 findings #1 and
		// #2, round-3 drop-and-warn). Never refuses outright: feedTemplate
		// is an OPTIONAL input with a valid fallback, not a gated operation
		// (multi-source below IS a gated operation and keeps its 403).
		// Extracted out of this method to keep its own cyclomatic
		// complexity in bounds.
		$template_result = self::resolve_template_settings($schema, $feed_template, $tier_available);
		$settings_data   = $template_result['settings_data'];
		$ignored         = array_merge($ignored, $template_result['ignored']);
		$template_warnings = $template_result['warnings'];

		$updated = array();
		if (!empty($input['settings']) && is_array($input['settings'])) {
			$writable = SBTT_Settings_Schema::writable($schema);
			foreach ($input['settings'] as $key => $value) {
				if ($key === 'feed_name' || !isset($writable[$key])) {
					$ignored[$key] = isset($schema[$key]) ? 'read_only' : 'unknown_setting';
					continue;
				}
				$v = SBTT_Settings_Schema::validate_in($schema[$key], $value, $key);
				if (is_wp_error($v)) {
					return $v;
				}
				$tier = SBTT_Settings_Schema::required_tier($schema[$key], $v);
				if ($tier !== null && !call_user_func($tier_available, $tier)) {
					$ignored[$key] = 'feature_unavailable';
					continue;
				}
				$settings_data[$key] = $v;
				$updated[]           = $key;
			}
		}

		$source_names = array();
		if (!empty($input['sources']) && is_array($input['sources'])) {
			// Combining more than one source into a feed is a pro-tier feature
			// (Utils::get_features_list()['pro']['combine_feed_sources']) — the
			// input schema's `maxItems: 10` alone let any tier attach up to 10
			// sources with no license check at all (Rex PR #120 finding #3,
			// cross-cutting fix 1).
			if (count($input['sources']) > 1 && !call_user_func($tier_available, 'combine_feed_sources')) {
				return \SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_Ability_Response::tier_locked(
					'combine_feed_sources'
				);
			}
			$resolved = (new SBTT_Source_Service())->resolve_sources(array_map('strval', $input['sources']));
			if (!empty($resolved['missing'])) {
				return \SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_Ability_Response::source_not_found(
					$resolved['missing']
				);
			}
			$settings_data['sources'] = $resolved['ids'];
			foreach ($resolved['details'] as $d) {
				$source_names[] = $d['display_name'];
			}
		}

		$feed_name = !empty($input['feed_name'])
			? sanitize_text_field((string) $input['feed_name'])
			: (!empty($source_names) ? $source_names[0] : __('TikTok Feed', 'feeds-for-tiktok'));

		$feeds_table = new FeedsTable();
		$feed_id     = $feeds_table->insert(
			array(
				'feed_name'     => $feed_name,
				'feed_title'    => '',
				'settings'      => $settings_data,
				'feed_style'    => '',
				'status'        => 'publish',
				'last_modified' => gmdate('Y-m-d H:i:s'),
			)
		);
		if (!$feed_id) {
			return new \WP_Error(
				'sbtt_feed_save_failed',
				__('The feed could not be created.', 'feeds-for-tiktok'),
				array('status' => 500)
			);
		}
		// No invalidate_cached_feed() call needed here: $feed_id is a fresh
		// AUTO_INCREMENT id from FeedsTable::insert(), so no prior
		// FeedsTable::get_feed($feed_id) call could have populated the
		// `get_feed_by_id{$feed_id}` cache entry before this row existed —
		// checked, not assumed (Rex round 1 asked for this explicitly).

		/** This action is documented in SBTT_Feed_Settings_Service::update_feed(). */
		do_action('sbtt_abilities_feed_settings_updated', $feed_id, $updated);

		// AgDR-9004 requirement 3 — see the identical calls in update_feed().
		// $template_warnings comes first: it reports the feedTemplate/
		// expansion drop-and-warn from resolve_template_settings() above.
		$warnings = array_merge(
			$template_warnings,
			SBTT_Settings_Schema::condition_warnings($schema, $updated, $settings_data),
			self::content_length_fallback_warning($updated, $settings_data),
			SBTT_Settings_Schema::feed_style_warnings($updated, $settings_data, $feed_id)
		);

		return array(
			'feed_id'   => (int) $feed_id,
			'feed_name' => $feed_name,
			'shortcode' => SBTT_Edition::shortcode($feed_id),
			'updated'   => $updated,
			'ignored'   => (object) $ignored,
			'warnings'  => $warnings,
		);
	}

	/**
	 * Attach already-connected accounts to a feed (replaces the source list).
	 *
	 * @param int      $feed_id Feed id.
	 * @param string[] $handles open_ids or display names.
	 * @return array|\WP_Error|null Null when the feed does not exist.
	 */
	public function set_feed_sources($feed_id, array $handles)
	{
		$feed_id     = (int) $feed_id;
		$feeds_table = new FeedsTable();
		// See the OBJECT-CACHE NOTE on this class: this read is the merge base for
		// the source-list replace below, so invalidate BEFORE the read, not only
		// after the write — same stale-merge-base hazard as update_feed().
		self::invalidate_cached_feed($feed_id);
		$row         = $feeds_table->get_feed($feed_id);
		if ($feed_id <= 0 || !$row) {
			return null;
		}
		$resolved = (new SBTT_Source_Service())->resolve_sources($handles);
		if (!empty($resolved['missing'])) {
			return \SmashBalloon\TikTokFeeds\Common\Abilities\SBTT_Ability_Response::source_not_found(
				$resolved['missing']
			);
		}
		if (empty($resolved['ids'])) {
			return new \WP_Error(
				'sbtt_invalid_value',
				__('At least one connected account is required.', 'feeds-for-tiktok'),
				array('status' => 400)
			);
		}

		$stored = isset($row['settings']) ? json_decode($row['settings'], true) : array();
		$stored = is_array($stored) ? $stored : array();
		$stored['sources'] = $resolved['ids'];

		$data = array(
			'id'            => $feed_id,
			'feed_name'     => isset($row['feed_name']) ? $row['feed_name'] : '',
			'feed_title'    => isset($row['feed_title']) ? $row['feed_title'] : '',
			'settings'      => $stored,
			'feed_style'    => isset($row['feed_style']) ? $row['feed_style'] : '',
			'last_modified' => gmdate('Y-m-d H:i:s'),
		);
		if ($feeds_table->update($data, array('id' => $feed_id)) === false) {
			return new \WP_Error(
				'sbtt_feed_save_failed',
				__('The feed could not be saved.', 'feeds-for-tiktok'),
				array('status' => 500)
			);
		}
		// See the OBJECT-CACHE NOTE on this class.
		self::invalidate_cached_feed($feed_id);

		/** This action is documented in SBTT_Feed_Settings_Service::update_feed(). */
		do_action('sbtt_abilities_feed_settings_updated', $feed_id, array('sources'));

		return array(
			'feed_id' => $feed_id,
			'sources' => array_map(
				function ($d) {
					return $d['display_name'];
				},
				$resolved['details']
			),
		);
	}
}
