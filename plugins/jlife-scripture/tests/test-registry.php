<?php
/**
 * The version allowlist: only enabled + native versions with license
 * evidence render text; link-only versions are links; defaults by locale;
 * switching a default is a data change.
 *
 * @package jlife-scripture
 */

/**
 * Registry tests.
 */
class Test_Registry extends Jlife_Scripture_TestCase {

	/**
	 * Every shipped version is valid; Kinh Thánh 1925 and the three API.Bible
	 * versions (NLT, NKJV, CSB: enabled #141) may render text, nothing else.
	 */
	public function test_shipped_registry_renders_only_the_licensed_versions() {
		$versions = jlife_scripture_registry()['versions'];
		$this->assertSame( array( 'kt1925', 'nirv', 'niv', 'kthd', 'nlt', 'nkjv', 'csb', 'erv', 'bpt' ), array_keys( $versions ), 'no shipped entry is dropped as invalid' );
		$renderable = array_keys( array_filter( $versions, 'jlife_scripture_can_render' ) );
		$this->assertSame( array( 'kt1925', 'nlt', 'nkjv', 'csb' ), $renderable );
		foreach ( array( 'erv', 'bpt' ) as $key ) {
			$this->assertSame( 'link', $versions[ $key ]['source'] );
			$this->assertSame( 'link', $versions[ $key ]['display'] );
		}
		foreach ( array(
			'nirv' => '110',
			'niv'  => '111',
			'kthd' => '1638',
		) as $key => $id ) {
			$this->assertSame( 'youversion', $versions[ $key ]['source'] );
			$this->assertSame( $id, $versions[ $key ]['source_id'] );
			$this->assertFalse( jlife_scripture_can_render( $versions[ $key ] ), "{$key} waits for the owner gates" );
		}
	}

	/**
	 * Defaults by the participant's locale: vi -> 1925, en (or none) -> NLT
	 * (API.Bible), with the ERV link as English's fallback.
	 */
	public function test_defaults_by_locale() {
		$vi   = $this->participant( 'vi' );
		$vivn = $this->participant( 'vi_VN' );
		$en   = $this->participant( 'en_US' );
		$none = $this->participant( '' );
		$this->assertSame( 'vi', jlife_scripture_language_for_user( $vi ) );
		$this->assertSame( 'vi', jlife_scripture_language_for_user( $vivn ) );
		$this->assertSame( 'en', jlife_scripture_language_for_user( $en ) );
		$this->assertSame( 'en', jlife_scripture_language_for_user( $none ) );

		$choice = jlife_scripture_reader_choice( 'vi' );
		$this->assertSame( 'kt1925', $choice['version']['key'] );
		$this->assertTrue( $choice['native'] );

		$choice = jlife_scripture_reader_choice( 'en' );
		$this->assertSame( 'nlt', $choice['version']['key'] );
		$this->assertTrue( $choice['native'] );
		$this->assertSame( 'ERV', $choice['fallback']['source_id'] );
		$this->assertSame( 'BPT', jlife_scripture_fallback_version( 'vi' )['source_id'] );
	}

	/**
	 * Each allowlist condition is required.
	 *
	 * @dataProvider not_renderable
	 *
	 * @param array $fields Fields that make Kinh Thánh 1925 unrenderable.
	 */
	public function test_each_allowlist_condition_is_required( $fields ) {
		$this->patch_version( 'kt1925', $fields );
		$v = jlife_scripture_get_version( 'kt1925' );
		$this->assertFalse( null !== $v && jlife_scripture_can_render( $v ) );
		$this->assertFalse( jlife_scripture_reader_choice( 'vi' )['native'] );
	}

	/**
	 * Variants that must not render.
	 *
	 * @return array
	 */
	public function not_renderable() {
		return array(
			'disabled'               => array( array( 'enabled' => false ) ),
			'link display'           => array( array( 'display' => 'link' ) ),
			'no license evidence'    => array( array( 'license_evidence' => null ) ),
			'empty license evidence' => array( array( 'license_evidence' => '  ' ) ),
			'link source, native'    => array( array( 'source' => 'link' ) ),
			'unknown source'         => array( array( 'source' => 'scraper' ) ),
			'no copyright line'      => array( array( 'copyright' => array( 'text' => '' ) ) ),
			'cache rule missing'     => array( array( 'cache' => array() ) ),
			'negative max age'       => array(
				array(
					'cache' => array(
						'max_age_seconds'        => -1,
						'max_consecutive_verses' => null,
					),
				),
			),
		);
	}

	/**
	 * A source with no registered implementation cannot show text: the API.Bible
	 * client is optional (it lives in jlife-challenges), so with it missing an
	 * otherwise enabled, native, licensed NLT is not renderable.
	 */
	public function test_a_source_without_a_registered_client_cannot_render() {
		$this->patch_version(
			'nlt',
			array(
				'enabled'          => true,
				'display'          => 'native',
				'license_evidence' => 'Test only.',
			)
		);
		$this->assertTrue( jlife_scripture_can_render( jlife_scripture_get_version( 'nlt' ) ), 'the apibible source is registered' );
		add_filter(
			'jlife_scripture_sources',
			static function ( $sources ) {
				unset( $sources['apibible'] );
				return $sources;
			}
		);
		$this->assertFalse( jlife_scripture_can_render( jlife_scripture_get_version( 'nlt' ) ), 'no registered source, no text' );
	}

	/**
	 * Moving the English and Vietnamese defaults to ERV/BPT (once Bible League
	 * licenses them) is a registry edit only.
	 */
	public function test_switching_defaults_is_data_only() {
		$this->patch_version( 'nlt', array( 'default_for' => array() ) );
		$this->patch_version( 'kt1925', array( 'default_for' => array() ) );
		$this->patch_version( 'erv', array( 'default_for' => array( 'en' ) ) );
		$this->patch_version( 'bpt', array( 'default_for' => array( 'vi' ) ) );
		$this->assertSame( 'erv', jlife_scripture_default_version( 'en' )['key'] );
		$this->assertSame( 'bpt', jlife_scripture_default_version( 'vi' )['key'] );

		$html = jlife_scripture_render_day( $this->day( 'John 1:1-18' ), $this->participant( 'vi' ) );
		$this->assertStringContainsString( 'version=BPT', $html );
		$this->assertStringNotContainsString( 'jls-text', $html, 'a link version shows no text' );
	}
}
