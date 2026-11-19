<?php
/**
 * Copy to ~/.latoc/bible.php on the HOST, outside its document root, mode 0600
 * (#141). Put the API.Bible app key in privately; never commit or upload the
 * real key to GitHub, issues, chat or screenshots. The deploy includes this file
 * from wp-config.php for web PHP and WP-CLI.
 */

define( 'JLIFE_APIBIBLE_KEY', 'REPLACE_WITH_API_BIBLE_KEY' );
