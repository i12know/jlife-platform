<?php
/**
 * Site-scoped Brevo transport (#63). Credentials live outside the web root.
 *
 * @package jlife-auth
 */

defined( 'ABSPATH' ) || exit;

/**
 * Whether this site's mail is explicitly assigned to Brevo.
 *
 * Configuration belongs to this WordPress installation, not the hosting account.
 * Check both multisite IDs so a later switch_to_blog cannot reuse the credentials.
 *
 * @return bool
 */
function jlife_mail_is_enabled() {
	return defined( 'JLIFE_MAIL_ENABLED' ) && true === JLIFE_MAIL_ENABLED
		&& get_current_blog_id() === ( defined( 'JLIFE_MAIL_SITE_ID' ) ? (int) JLIFE_MAIL_SITE_ID : 1 )
		&& get_current_network_id() === ( defined( 'JLIFE_MAIL_NETWORK_ID' ) ? (int) JLIFE_MAIL_NETWORK_ID : 1 );
}

/**
 * Validate runtime configuration without returning or displaying credentials.
 *
 * @return bool
 */
function jlife_mail_is_configured() {
	foreach ( array( 'JLIFE_MAIL_SITE_URL', 'JLIFE_BREVO_SMTP_LOGIN', 'JLIFE_BREVO_SMTP_KEY', 'JLIFE_MAIL_FROM_EMAIL', 'JLIFE_MAIL_FROM_NAME', 'JLIFE_MAIL_REPLY_TO' ) as $name ) {
		if ( ! defined( $name ) || ! is_string( constant( $name ) ) || '' === trim( constant( $name ) ) || preg_match( '/[\r\n]/', constant( $name ) ) ) {
			return false;
		}
	}
	return untrailingslashit( JLIFE_MAIL_SITE_URL ) === untrailingslashit( (string) get_option( 'home' ) )
		&& false !== is_email( JLIFE_MAIL_FROM_EMAIL )
		&& false !== is_email( JLIFE_MAIL_REPLY_TO )
		&& 'REPLACE_WITH_SMTP_KEY' !== JLIFE_BREVO_SMTP_KEY;
}

/**
 * Store only an allowlisted operational result; never a recipient, body or error.
 *
 * "accepted" means wp_mail succeeded, not that an inbox received the message.
 *
 * @param string $result accepted|configuration_missing|send_failed.
 * @return void
 */
function jlife_mail_record_result( $result ) {
	if ( ! in_array( $result, array( 'accepted', 'configuration_missing', 'send_failed' ), true ) ) {
		return;
	}
	update_option(
		'jlife_mail_health',
		array(
			'result' => $result,
			'at'     => gmdate( 'c' ),
		),
		false
	);
	if ( 'accepted' !== $result ) {
		error_log( 'jlife-mail: ' . $result ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- allowlisted operational status only.
	}
}

/**
 * Route this site's wp_mail calls through an isolated PHPMailer instance.
 *
 * WordPress reuses its global mailer across calls. Save it, use a fresh instance
 * (preserving its class for the WordPress test mailer), and restore it in finally. Hooks
 * are temporary so sibling-site and later unrelated messages retain their mailer.
 *
 * @param null|bool $pre  Earlier short-circuit result.
 * @param array     $atts WordPress mail arguments; never persisted here.
 * @return null|bool
 */
function jlife_mail_send( $pre, $atts ) {
	if ( ! jlife_mail_is_enabled() || null !== $pre ) {
		return $pre;
	}
	if ( ! jlife_mail_is_configured() ) {
		jlife_mail_record_result( 'configuration_missing' );
		return false;
	}

	global $phpmailer;
	$previous  = $phpmailer;
	$configure = static function ( $mailer ) {
		// PHPMailer uses these public property names in its upstream API.
		// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
		$mailer->isSMTP();
		$mailer->Host          = 'smtp-relay.brevo.com';
		$mailer->Port          = 465;
		$mailer->SMTPSecure    = 'ssl';
		$mailer->SMTPAutoTLS   = false; // Implicit TLS; never downgrade to plaintext.
		$mailer->SMTPAuth      = true;
		$mailer->Username      = constant( 'JLIFE_BREVO_SMTP_LOGIN' );
		$mailer->Password      = constant( 'JLIFE_BREVO_SMTP_KEY' );
		$mailer->SMTPDebug     = 0;
		$mailer->Timeout       = 10;
		$mailer->Timelimit     = 10;
		$mailer->SMTPKeepAlive = false;
		$mailer->SMTPOptions   = array(
			'ssl' => array(
				'verify_peer'       => true,
				'verify_peer_name'  => true,
				'allow_self_signed' => false,
			),
		);
		$mailer->setFrom( constant( 'JLIFE_MAIL_FROM_EMAIL' ), constant( 'JLIFE_MAIL_FROM_NAME' ), false );
		$mailer->Sender = constant( 'JLIFE_MAIL_FROM_EMAIL' );
		$mailer->clearReplyTos();
		$mailer->addReplyTo( constant( 'JLIFE_MAIL_REPLY_TO' ) );
		// phpcs:enable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
	};
	remove_filter( 'pre_wp_mail', 'jlife_mail_send', 10 );
	add_action( 'phpmailer_init', $configure, PHP_INT_MAX );
	$sent = false;
	try {
		// A shallow clone would share the previous mailer's SMTP connection.
		$class     = is_object( $previous ) ? get_class( $previous ) : null;
		$phpmailer = $class ? new $class( true ) : null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restored in finally.
		$sent      = wp_mail( $atts['to'], $atts['subject'], $atts['message'], $atts['headers'], $atts['attachments'], $atts['embeds'] ?? array() );
	} catch ( Throwable $error ) {
		// Do not log the exception: SMTP diagnostics can contain credentials/body.
		$sent = false;
	} finally {
		if ( is_object( $phpmailer ) && $phpmailer !== $previous ) {
			$phpmailer->smtpClose();
		}
		$phpmailer = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore the caller's mailer.
		remove_action( 'phpmailer_init', $configure, PHP_INT_MAX );
		add_filter( 'pre_wp_mail', 'jlife_mail_send', 10, 2 );
	}
	jlife_mail_record_result( $sent ? 'accepted' : 'send_failed' );
	return $sent;
}
add_filter( 'pre_wp_mail', 'jlife_mail_send', 10, 2 );

/**
 * Use the configured product name in authentication email subjects.
 *
 * @param string $name Site title fallback.
 * @return string
 */
function jlife_mail_subject_name( $name ) {
	if ( jlife_mail_is_enabled() && defined( 'JLIFE_MAIL_PRODUCT_NAME' ) && is_string( JLIFE_MAIL_PRODUCT_NAME ) && '' !== trim( JLIFE_MAIL_PRODUCT_NAME ) && ! preg_match( '/[\r\n]/', JLIFE_MAIL_PRODUCT_NAME ) ) {
		return JLIFE_MAIL_PRODUCT_NAME;
	}
	return $name;
}
add_filter( 'jlife_auth_email_site_name', 'jlife_mail_subject_name' );

/**
 * Staff-only delivery health, without any message or credential data.
 *
 * @return void
 */
function jlife_mail_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) || ! jlife_mail_is_enabled() ) {
		return;
	}
	$health = get_option( 'jlife_mail_health', array() );
	if ( ! jlife_mail_is_configured() ) {
		$message = __( 'J-Life email: Brevo is enabled but its private configuration is incomplete. Email delivery is blocked.', 'jlife-auth' );
	} elseif ( in_array( $health['result'] ?? '', array( 'configuration_missing', 'send_failed' ), true ) ) {
		$message = __( 'J-Life email: the last sending attempt failed. Check Brevo delivery events and the private runtime configuration.', 'jlife-auth' );
	} else {
		return;
	}
	echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
}
add_action( 'admin_notices', 'jlife_mail_admin_notice' );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/class-jlife-mail-command.php';
	WP_CLI::add_command( 'jlife mail', 'Jlife_Mail_Command' );
}
