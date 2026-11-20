<?php
/**
 * Shared fixtures: the tables, the Kinh Thánh 1925 dataset imported once,
 * a controllable clock, the fake network source and a registry override.
 *
 * @package jlife-scripture
 */

/**
 * Base test case.
 */
abstract class Jlife_Scripture_TestCase extends WP_UnitTestCase {

	/**
	 * The fixed "now", or null for the real clock.
	 *
	 * @var int|null
	 */
	protected $now = null;

	/**
	 * The fake source, when installed.
	 *
	 * @var Jlife_Scripture_Fake_Source|null
	 */
	protected $fake = null;

	/**
	 * Tables and the 1925 text, once for the whole run (committed, like the
	 * shipped data; it holds no personal data).
	 *
	 * @throws RuntimeException When the shipped dataset does not import.
	 */
	public static function wpSetUpBeforeClass() {
		jlife_scripture_install_tables();
		$r = jlife_scripture_import_dataset( 'vie1934' );
		if ( is_wp_error( $r ) ) {
			throw new RuntimeException( $r->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- test setup.
		}
	}

	/**
	 * Captured PHP error log for this test.
	 *
	 * @var string
	 */
	protected $log = '';

	/**
	 * Previous error_log setting.
	 *
	 * @var string|false
	 */
	private $previous_log = false;

	/**
	 * Clock, no lock waiting, and the PHP error log captured in a file.
	 */
	public function set_up() {
		parent::set_up();
		$this->log          = wp_tempnam( 'jls-log' );
		$this->previous_log = ini_set( 'error_log', $this->log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- test: capture the log.
		add_filter( 'jlife_scripture_now', array( $this, 'clock' ) );
		add_filter(
			'jlife_scripture_lock_wait',
			static fn() => array(
				'attempts' => 0,
				'micros'   => 0,
			)
		);
	}

	/**
	 * Restore the error log.
	 */
	public function tear_down() {
		ini_set( 'error_log', (string) $this->previous_log ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- test: restore the log.
		wp_delete_file( $this->log );
		parent::tear_down();
	}

	/**
	 * What this test wrote to the PHP error log.
	 *
	 * @return string
	 */
	protected function logged() {
		return (string) file_get_contents( $this->log ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- test log.
	}

	/**
	 * Clock filter.
	 *
	 * @param int $ts Real time.
	 * @return int
	 */
	public function clock( $ts ) {
		return null === $this->now ? $ts : $this->now;
	}

	/**
	 * Replace the YouVersion stub with the fake source and make NIrV a
	 * renderable, cached English default (as it will be once the owner gates
	 * clear; today NLT is the shipped English default, so this moves it), with
	 * the given cache rule.
	 *
	 * @param int      $max_age   cache.max_age_seconds.
	 * @param int|null $max_verse cache.max_consecutive_verses.
	 * @return Jlife_Scripture_Fake_Source
	 */
	protected function use_fake_nirv( $max_age = 3600, $max_verse = null ) {
		$this->fake = new Jlife_Scripture_Fake_Source();
		$fake       = $this->fake;
		add_filter(
			'jlife_scripture_sources',
			static function ( $sources ) use ( $fake ) {
				$sources['youversion'] = $fake;
				return $sources;
			}
		);
		$this->patch_version(
			'nirv',
			array(
				'display'          => 'native',
				'enabled'          => true,
				'license_evidence' => 'Test only.',
				'default_for'      => array( 'en' ),
				'cache'            => array(
					'max_age_seconds'        => $max_age,
					'max_consecutive_verses' => $max_verse,
				),
			)
		);
		$this->patch_version( 'nlt', array( 'default_for' => array() ) );
		return $this->fake;
	}

	/**
	 * Change fields of one registry version for this test (data-only, the
	 * way an owner would edit bible-versions.json).
	 *
	 * @param string $key    Version key.
	 * @param array  $fields Fields to set.
	 */
	protected function patch_version( $key, $fields ) {
		add_filter(
			'jlife_scripture_registry',
			static function ( $doc ) use ( $key, $fields ) {
				foreach ( $doc['versions'] as $i => $v ) {
					if ( $v['key'] === $key ) {
						$doc['versions'][ $i ] = array_merge( $v, $fields );
					}
				}
				return $doc;
			}
		);
	}

	/**
	 * A participant with a locale.
	 *
	 * @param string $locale User meta locale ('' = none).
	 * @return int
	 */
	protected function participant( $locale ) {
		$id = self::factory()->user->create( array( 'user_email' => wp_generate_password( 8, false ) . '@example.test' ) );
		if ( '' !== $locale ) {
			update_user_meta( $id, 'locale', $locale );
		}
		return $id;
	}

	/**
	 * A plan day like the reading-plan JSON's, from a reference cell.
	 *
	 * @param string $cell   "John 1:1-18; John 1:19-28".
	 * @param array  $vi     Vietnamese labels.
	 * @return array
	 */
	protected function day( $cell, $vi = array() ) {
		$day = array(
			'day_id'   => 'd001',
			'passages' => array_map(
				static fn( $d ) => array( 'display' => trim( $d ) ),
				explode( ';', $cell )
			),
		);
		if ( $vi ) {
			$day['i18n'] = array( 'vi' => array( 'passages' => $vi ) );
		}
		return $day;
	}
}
