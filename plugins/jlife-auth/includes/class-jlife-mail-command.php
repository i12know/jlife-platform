<?php
/**
 * Operational mail checks that never display credentials or send a message.
 *
 * @package jlife-auth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Inspect mail configuration and encrypted SMTP reachability.
 */
class Jlife_Mail_Command {
	/**
	 * Show configuration and the last operational outcome, without secrets.
	 *
	 * @return void
	 */
	public function status() {
		WP_CLI::line(
			wp_json_encode(
				array(
					'provider'       => 'brevo',
					'enabled_here'   => jlife_mail_is_enabled(),
					'configured'     => jlife_mail_is_configured(),
					'private_config' => defined( 'JLIFE_MAIL_CONFIG_LOADED' ) && JLIFE_MAIL_CONFIG_LOADED,
					'last_attempt'   => get_option( 'jlife_mail_health', array() ),
				)
			)
		);
	}

	/**
	 * Test verified TLS to Brevo port 465. Does not authenticate or send email.
	 *
	 * @return void
	 */
	public function probe() {
		$context = stream_context_create(
			array(
				'ssl' => array(
					'verify_peer'       => true,
					'verify_peer_name'  => true,
					'allow_self_signed' => false,
					'peer_name'         => 'smtp-relay.brevo.com',
				),
			)
		);
		// CLI-only reachability check. Error text is intentionally never displayed.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_socket_client, WordPress.PHP.NoSilencedErrors.Discouraged
		$socket = @stream_socket_client( 'ssl://smtp-relay.brevo.com:465', $errno, $error, 10, STREAM_CLIENT_CONNECT, $context );
		if ( false === $socket ) {
			WP_CLI::error( 'Brevo TLS connection failed. Check outbound port 465 and the host CA trust store. No message was sent.' );
		}
		stream_set_timeout( $socket, 10 );
		$banner = fgets( $socket, 512 );
		fwrite( $socket, "QUIT\r\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- SMTP socket, not a file.
		fclose( $socket ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- SMTP socket.
		if ( ! is_string( $banner ) || 0 !== strpos( $banner, '220' ) ) {
			WP_CLI::error( 'TLS connected but the SMTP greeting was not ready. No message was sent.' );
		}
		WP_CLI::success( 'Brevo port 465 and certificate verification passed. Authentication and inbox delivery still need a real test.' );
	}
}
