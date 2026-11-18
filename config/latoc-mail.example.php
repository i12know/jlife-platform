<?php
/**
 * Copy to ~/.latoc/mail.php on the HOST, outside its document root, mode 0600.
 * Replace the example values privately; never commit or upload the real key
 * to GitHub. The deploy includes this file from wp-config.php for web/CLI.
 */

define( 'JLIFE_MAIL_ENABLED', true );
define( 'JLIFE_MAIL_NETWORK_ID', 1 );
define( 'JLIFE_MAIL_SITE_ID', 1 );
define( 'JLIFE_MAIL_SITE_URL', 'https://latoc.example.test' );
define( 'JLIFE_MAIL_PRODUCT_NAME', 'LATOC' );
define( 'JLIFE_BREVO_SMTP_LOGIN', 'smtp-login@example.test' );
define( 'JLIFE_BREVO_SMTP_KEY', 'REPLACE_WITH_SMTP_KEY' );
define( 'JLIFE_MAIL_FROM_EMAIL', 'info@example.test' );
define( 'JLIFE_MAIL_FROM_NAME', 'LATOC' );
define( 'JLIFE_MAIL_REPLY_TO', 'info@example.test' );
