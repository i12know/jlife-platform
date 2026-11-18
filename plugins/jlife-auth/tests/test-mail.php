<?php
/**
 * Brevo transport isolation and failure handling. Mock mailers never use SMTP.
 *
 * @package jlife-auth
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- colocated fixture used only by this test class.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- assertions against PHPMailer's upstream API.

/**
 * Provider rejection/timeout simulator; no network or real credentials.
 */
class Jlife_Test_Mailer extends MockPHPMailer {
	/**
	 * Failure text to throw instead of accepting the message.
	 *
	 * @var string
	 */
	public static $failure = '';

	/**
	 * Simulate provider acceptance or rejection without sending.
	 *
	 * @return bool
	 * @throws \PHPMailer\PHPMailer\Exception Simulated provider error.
	 */
	public function postSend() {
		if ( '' !== self::$failure ) {
			throw new \PHPMailer\PHPMailer\Exception( self::$failure );
		}
		return parent::postSend();
	}
}

/**
 * Constants are private runtime config: isolate each test in its own process.
 *
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class Test_Mail extends WP_UnitTestCase {
	/**
	 * Install fake runtime settings for only the current site.
	 *
	 * @param array $overrides Values to replace; null omits a constant.
	 * @return void
	 */
	private function configure( $overrides = array() ) {
		$settings = array_merge(
			array(
				'JLIFE_MAIL_ENABLED'      => true,
				'JLIFE_MAIL_SITE_ID'      => get_current_blog_id(),
				'JLIFE_MAIL_NETWORK_ID'   => get_current_network_id(),
				'JLIFE_MAIL_SITE_URL'     => get_option( 'home' ),
				'JLIFE_BREVO_SMTP_LOGIN'  => 'fake-login@example.test',
				'JLIFE_BREVO_SMTP_KEY'    => 'fake-key-never-send',
				'JLIFE_MAIL_FROM_EMAIL'   => 'latoc@example.test',
				'JLIFE_MAIL_FROM_NAME'    => 'LATOC Test',
				'JLIFE_MAIL_REPLY_TO'     => 'support@example.test',
				'JLIFE_MAIL_PRODUCT_NAME' => 'LATOC',
			),
			$overrides
		);
		foreach ( $settings as $key => $value ) {
			if ( null !== $value ) {
				define( $key, $value );
			}
		}
		$GLOBALS['phpmailer'] = new Jlife_Test_Mailer( true );
	}

	/**
	 * Capture the isolated instance after its configuration and fake send.
	 *
	 * @return object
	 */
	private function send_and_capture() {
		$captured = null;
		$capture  = static function () use ( &$captured ) {
			$captured = $GLOBALS['phpmailer'];
		};
		add_action( 'wp_mail_succeeded', $capture );
		try {
			$this->assertTrue( wp_mail( 'recipient@example.test', 'Test', 'Synthetic body' ) );
		} finally {
			remove_action( 'wp_mail_succeeded', $capture );
		}
		$this->assertNotNull( $captured );
		return $captured;
	}

	/**
	 * Correct sender, encryption, credentials, and cleanup with a fake provider.
	 */
	public function test_configured_transport_is_isolated_and_uses_tls() {
		$this->configure();
		$original = $GLOBALS['phpmailer'];
		$mailer   = $this->send_and_capture();
		$this->assertSame( 'smtp', $mailer->Mailer );
		$this->assertSame( 'smtp-relay.brevo.com', $mailer->Host );
		$this->assertSame( 465, $mailer->Port );
		$this->assertSame( 'ssl', $mailer->SMTPSecure );
		$this->assertTrue( $mailer->SMTPAuth );
		$this->assertSame( JLIFE_BREVO_SMTP_LOGIN, $mailer->Username );
		$this->assertSame( JLIFE_BREVO_SMTP_KEY, $mailer->Password );
		$this->assertSame( 0, $mailer->SMTPDebug );
		$this->assertTrue( $mailer->SMTPOptions['ssl']['verify_peer'] );
		$this->assertTrue( $mailer->SMTPOptions['ssl']['verify_peer_name'] );
		$this->assertFalse( $mailer->SMTPOptions['ssl']['allow_self_signed'] );
		$this->assertSame( JLIFE_MAIL_FROM_EMAIL, $mailer->From );
		$this->assertSame( JLIFE_MAIL_FROM_NAME, $mailer->FromName );
		$this->assertArrayHasKey( JLIFE_MAIL_REPLY_TO, $mailer->getReplyToAddresses() );
		$this->assertSame( $original, $GLOBALS['phpmailer'] );
		$this->assertSame( '', $original->Password );
		$this->assertSame( 'accepted', get_option( 'jlife_mail_health' )['result'] );
		$this->assertSame( 'LATOC', apply_filters( 'jlife_auth_email_site_name', 'Old site title' ) );
	}

	/**
	 * Selected Brevo with no key cannot quietly send through host mail.
	 */
	public function test_missing_key_blocks_without_host_fallback() {
		$this->configure( array( 'JLIFE_BREVO_SMTP_KEY' => null ) );
		$original = $GLOBALS['phpmailer'];
		$this->assertFalse( wp_mail( 'recipient@example.test', 'Test', 'Synthetic body' ) );
		$this->assertEmpty( $original->mock_sent );
		$this->assertSame( 'configuration_missing', get_option( 'jlife_mail_health' )['result'] );
	}

	/**
	 * A template value is not a usable credential.
	 */
	public function test_placeholder_key_is_rejected() {
		$this->configure( array( 'JLIFE_BREVO_SMTP_KEY' => 'REPLACE_WITH_SMTP_KEY' ) );
		$this->assertFalse( wp_mail( 'recipient@example.test', 'Test', 'Synthetic body' ) );
		$this->assertSame( 'configuration_missing', get_option( 'jlife_mail_health' )['result'] );
	}

	/**
	 * Reject both bad credentials and timeouts; keep raw diagnostics out of state.
	 *
	 * @dataProvider failure_cases
	 * @param string $reason Fake provider failure category.
	 */
	public function test_provider_failure_is_redacted_and_cleans_up( $reason ) {
		$this->configure();
		$original                   = $GLOBALS['phpmailer'];
		Jlife_Test_Mailer::$failure = $reason . ' fake-key-never-send Synthetic body recipient@example.test';
		$this->assertFalse( wp_mail( 'recipient@example.test', 'Test', 'Synthetic body' ) );
		$this->assertSame( $original, $GLOBALS['phpmailer'] );
		$this->assertEmpty( $original->mock_sent );
		$health = get_option( 'jlife_mail_health' );
		$this->assertSame( 'send_failed', $health['result'] );
		$this->assertSame( array( 'result', 'at' ), array_keys( $health ) );
		$this->assertStringNotContainsString( 'fake-key', wp_json_encode( $health ) );
		$this->assertStringNotContainsString( 'Synthetic body', wp_json_encode( $health ) );
		Jlife_Test_Mailer::$failure = '';
		$this->send_and_capture(); // Hooks restored after failure; next request works.
	}

	/**
	 * Simulated provider failures, never live sends.
	 *
	 * @return array
	 */
	public function failure_cases() {
		return array(
			'rejection' => array( 'authentication rejected' ),
			'timeout'   => array( 'connection timeout' ),
		);
	}

	/**
	 * A config for a different site ID does not intercept this site's email.
	 */
	public function test_other_site_is_untouched() {
		$this->configure( array( 'JLIFE_MAIL_SITE_ID' => get_current_blog_id() + 1 ) );
		$this->assertNull( jlife_mail_send( null, array() ) );
		$this->assertSame( 'Site', apply_filters( 'jlife_auth_email_site_name', 'Site' ) );
		$this->assertFalse( get_option( 'jlife_mail_health' ) );
	}

	/**
	 * A different network cannot borrow the sending credentials.
	 */
	public function test_other_network_is_untouched() {
		$this->configure( array( 'JLIFE_MAIL_NETWORK_ID' => get_current_network_id() + 1 ) );
		$this->assertNull( jlife_mail_send( null, array() ) );
	}

	/**
	 * A copied configuration fails closed on a different home URL.
	 */
	public function test_wrong_installation_url_is_rejected() {
		$this->configure( array( 'JLIFE_MAIL_SITE_URL' => 'https://other.example.test' ) );
		$this->assertFalse( wp_mail( 'recipient@example.test', 'Test', 'Synthetic body' ) );
	}

	/**
	 * Header injection in the configured display name fails before sending.
	 */
	public function test_sender_header_injection_is_rejected() {
		$this->configure( array( 'JLIFE_MAIL_FROM_NAME' => "LATOC\r\nBcc: other@example.test" ) );
		$this->assertFalse( wp_mail( 'recipient@example.test', 'Test', 'Synthetic body' ) );
	}

	/**
	 * No enablement means the existing WordPress behavior remains available.
	 */
	public function test_disabled_transport_does_not_intercept() {
		$this->assertNull( jlife_mail_send( null, array() ) );
		$this->assertSame( 'Site', apply_filters( 'jlife_auth_email_site_name', 'Site' ) );
	}
}
