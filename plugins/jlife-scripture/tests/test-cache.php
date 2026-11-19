<?php
/**
 * The shared passage cache with a fake network source: one fetch per
 * passage, the version's max age and verse limit honored, a lock against
 * concurrent first fetches, and failures falling back to the link.
 *
 * @package jlife-scripture
 */

/**
 * Cache tests.
 */
class Test_Cache extends Jlife_Scripture_TestCase {

	/**
	 * Cache rows.
	 *
	 * @return array
	 */
	private function rows() {
		global $wpdb;
		$table = jlife_scripture_table( 'cache' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test query on the plugin's table.
		return (array) $wpdb->get_results( "SELECT * FROM {$table}", ARRAY_A );
	}

	/**
	 * NIrV's fetch for a reference.
	 *
	 * @param string $ref Reference.
	 * @return array|WP_Error
	 */
	private function nirv( $ref ) {
		return jlife_scripture_get_passage( jlife_scripture_get_version( 'nirv' ), jlife_scripture_parse_reference( $ref ) );
	}

	/**
	 * Many readers of the same day: one fetch per passage, one shared row each.
	 */
	public function test_one_fetch_per_passage() {
		$fake = $this->use_fake_nirv();
		$day  = $this->day( 'John 1:1-18; John 1:19-28; Luke 3:23a' );
		foreach ( range( 1, 5 ) as $i ) {
			$html = jlife_scripture_render_day( $day, $this->participant( 'en_US' ) );
			$this->assertStringContainsString( 'jls-text', $html, "reader {$i} sees text" );
		}
		$this->assertSame( 3, $fake->calls );
		$rows = $this->rows();
		$this->assertCount( 3, $rows );
		$this->assertSame( array( 'youversion' ), array_values( array_unique( array_column( $rows, 'source' ) ) ) );
		$this->assertSame( array( 'nirv' ), array_values( array_unique( array_column( $rows, 'version_key' ) ) ) );
		$this->assertContains( 'LUK.3.23-LUK.3.23', array_column( $rows, 'passage' ) );
	}

	/**
	 * A cached passage is served until max_age_seconds, then fetched again.
	 */
	public function test_max_age_is_respected() {
		$fake      = $this->use_fake_nirv( 3600 );
		$this->now = 1798000000;
		$this->assertIsArray( $this->nirv( 'John 1:1-5' ) );
		$this->now += 3599;
		$this->assertIsArray( $this->nirv( 'John 1:1-5' ) );
		$this->assertSame( 1, $fake->calls );
		$this->now += 1;
		$this->assertIsArray( $this->nirv( 'John 1:1-5' ) );
		$this->assertSame( 2, $fake->calls, 'expired at max age' );
	}

	/**
	 * A version whose license allows no storage (max age 0) stores nothing.
	 */
	public function test_zero_max_age_stores_nothing() {
		$fake = $this->use_fake_nirv( 0 );
		$this->nirv( 'John 1:1-5' );
		$this->nirv( 'John 1:1-5' );
		$this->assertSame( 2, $fake->calls );
		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * A failed fetch falls back to the Bible Gateway link; nothing is stored,
	 * and the next view tries again.
	 */
	public function test_failure_falls_back_to_the_link() {
		$fake       = $this->use_fake_nirv();
		$fake->mode = 'error';
		$html       = jlife_scripture_render_day( $this->day( 'John 1:1-18' ), $this->participant( 'en_US' ) );
		$this->assertStringNotContainsString( 'jls-text', $html );
		$this->assertStringContainsString( 'https://www.biblegateway.com/passage/?search=John+1:1-18&#038;version=ERV', $html );
		$this->assertStringContainsString( 'Open in Bible Gateway (ERV)', $html );
		$this->assertStringContainsString( 'The Bible text could not be loaded.', $html );
		$this->assertNull( $this->rows()[0]['body'] );
		$this->assertNull( $this->rows()[0]['lock_until'], 'the lock is released' );

		$fake->mode = 'ok';
		$this->assertIsArray( $this->nirv( 'John 1:1-18' ) );
		$this->assertSame( 2, $fake->calls );
	}

	/**
	 * An exception or a malformed answer is a failure like any other.
	 */
	public function test_exceptions_and_malformed_results_fall_back() {
		$fake = $this->use_fake_nirv();
		foreach ( array( 'throw', 'malformed' ) as $mode ) {
			$fake->mode = $mode;
			$r          = $this->nirv( 'John 2:1-12' );
			$this->assertWPError( $r, $mode );
			$this->assertSame( 'jlife_scripture_unavailable', $r->get_error_code() );
			$html = jlife_scripture_render_day( $this->day( 'John 2:1-12' ), $this->participant( 'en_US' ) );
			$this->assertStringContainsString( 'Open in Bible Gateway (ERV)', $html, $mode );
		}
	}

	/**
	 * While another view holds the lock, a view does not fetch; it shows the
	 * link. Once the lock expires, a view fetches.
	 */
	public function test_lock_prevents_concurrent_first_fetches() {
		$fake      = $this->use_fake_nirv();
		$this->now = 1798000000;
		$version   = jlife_scripture_get_version( 'nirv' );
		$passage   = jlife_scripture_passage_key( jlife_scripture_parse_reference( 'John 3:1-21' ) );
		$key       = hash( 'sha256', 'youversion|nirv|110|' . $passage );
		$this->assertTrue( jlife_scripture_cache_lock( $key, $version, $passage ), 'first view takes the lock' );
		$this->assertFalse( jlife_scripture_cache_lock( $key, $version, $passage ), 'a second view cannot' );

		$r = $this->nirv( 'John 3:1-21' );
		$this->assertWPError( $r );
		$this->assertSame( 'jlife_scripture_busy', $r->get_error_code() );
		$this->assertSame( 0, $fake->calls );

		$this->now += 31;
		$this->assertIsArray( $this->nirv( 'John 3:1-21' ) );
		$this->assertSame( 1, $fake->calls, 'a stale lock is taken over' );
	}

	/**
	 * A fresh row is served even while another view holds the lock.
	 */
	public function test_fresh_row_is_served_while_locked() {
		$fake = $this->use_fake_nirv();
		$this->assertIsArray( $this->nirv( 'John 4:4-26' ) );
		global $wpdb;
		$table = jlife_scripture_table( 'cache' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- test: another view holds the lock on a fresh row.
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET lock_until = %s", gmdate( 'Y-m-d H:i:s', time() + 30 ) ) );
		$this->assertIsArray( $this->nirv( 'John 4:4-26' ) );
		$this->assertSame( 1, $fake->calls );
	}

	/**
	 * Text past its max age is never served, even when the refresh fails;
	 * the expired text is deleted.
	 */
	public function test_expired_text_is_not_served_on_failure() {
		$fake      = $this->use_fake_nirv( 600 );
		$this->now = 1798000000;
		$this->assertIsArray( $this->nirv( 'Mark 5:21-43' ) );
		$this->now += 601;
		$fake->mode = 'error';
		$this->assertWPError( $this->nirv( 'Mark 5:21-43' ) );
		$this->assertNull( $this->rows()[0]['body'], 'expired text removed' );
	}

	/**
	 * A passage longer than the license's consecutive-verse limit is not
	 * fetched (length known from the reference) or not kept (length learned
	 * from the answer), and shows the link.
	 */
	public function test_verse_limit() {
		$fake = $this->use_fake_nirv( 3600, 10 );
		$r    = $this->nirv( 'John 1:1-18' );
		$this->assertSame( 'jlife_scripture_too_long', $r->get_error_code() );
		$this->assertSame( 0, $fake->calls, 'never asked for more than allowed' );
		$this->assertIsArray( $this->nirv( 'John 1:1-10' ) );

		$fake = $this->use_fake_nirv( 3600, 2 );
		$r    = $this->nirv( '1 Corinthians 13' );
		$this->assertSame( 'jlife_scripture_too_long', $r->get_error_code() );
		$this->assertSame( 1, $fake->calls );
		foreach ( $this->rows() as $row ) {
			$this->assertStringNotContainsString( 'COR', (string) $row['body'] );
		}
		$html = jlife_scripture_render_day( $this->day( 'John 1:1-18' ), $this->participant( 'en_US' ) );
		$this->assertStringContainsString( 'Open in Bible Gateway (ERV)', $html );
	}

	/**
	 * The purge deletes text past its version's max age, and everything for a
	 * version whose rule no longer allows storage.
	 */
	public function test_purge() {
		$this->use_fake_nirv( 600 );
		$this->now = 1798000000;
		$this->nirv( 'John 1:1-5' );
		$this->now += 300;
		$this->nirv( 'John 1:6-8' );
		$this->now += 400;
		$this->assertSame( 1, jlife_scripture_cache_purge() );
		$this->assertSame( array( 'JHN.1.6-JHN.1.8' ), array_column( $this->rows(), 'passage' ) );

		$this->patch_version(
			'nirv',
			array(
				'cache' => array(
					'max_age_seconds'        => 0,
					'max_consecutive_verses' => null,
				),
			)
		);
		$this->assertSame( 1, jlife_scripture_cache_purge() );
		$this->assertSame( array(), $this->rows() );
	}

	/**
	 * A source's meta (e.g. API.Bible FUMS ids) is kept with the cached
	 * passage, and every display is tracked, cache hits included.
	 */
	public function test_meta_is_cached_and_every_display_is_tracked() {
		$fake       = $this->use_fake_nirv();
		$fake->meta = array( 'fums' => 'fums-token-1' );
		$seen       = array();
		add_action(
			'jlife_scripture_passage_shown',
			static function ( $version, $passage, $meta ) use ( &$seen ) {
				$seen[] = array( $version['key'], $passage, $meta['fums'] ?? null );
			},
			10,
			3
		);
		$this->assertIsArray( $this->nirv( 'John 1:1-5' ) );
		$this->assertIsArray( $this->nirv( 'John 1:1-5' ) );
		$this->assertSame( 1, $fake->calls, 'second display is a cache hit' );
		$this->assertCount( 2, $fake->displays );
		$this->assertSame( array( 'JHN.1.1-JHN.1.5', array( 'fums' => 'fums-token-1' ) ), $fake->displays[1] );
		$this->assertSame( array( 'nirv', 'JHN.1.1-JHN.1.5', 'fums-token-1' ), $seen[1] );
	}

	/**
	 * A per-response rule can only shorten storage: cacheable false stores
	 * nothing, max_age expires sooner than the version's rule.
	 */
	public function test_per_response_cache_rule_only_shortens() {
		$fake       = $this->use_fake_nirv( 3600 );
		$fake->meta = array( 'cacheable' => false );
		$this->nirv( 'John 1:1-5' );
		$this->nirv( 'John 1:1-5' );
		$this->assertSame( 2, $fake->calls );
		$this->assertNull( $this->rows()[0]['body'] );

		$fake->meta = array( 'max_age' => 60 );
		$this->now  = 1798000000;
		$this->nirv( 'John 2:1-5' );
		$this->now += 59;
		$this->nirv( 'John 2:1-5' );
		$this->assertSame( 3, $fake->calls );
		$this->now += 1;
		$this->nirv( 'John 2:1-5' );
		$this->assertSame( 4, $fake->calls, 'expired at the response max_age' );

		$fake->meta = array( 'max_age' => 999999 );
		$this->nirv( 'John 3:1-5' );
		$this->now += 3600;
		$this->nirv( 'John 3:1-5' );
		$this->assertSame( 6, $fake->calls, 'never longer than the version allows' );
	}
}
