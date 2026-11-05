<?php
/**
 * Router for PHP's built-in web server: subdirectory multisite rewrites.
 *
 * Cloud dev only (bin/cloud-dev.sh). Mirrors config/wp-env/htaccess-multisite:
 *   ^([_0-9a-zA-Z-]+/)?(wp-(content|admin|includes).*)  -> $2
 *   ^([_0-9a-zA-Z-]+/)?(.*\.php)$                       -> $2
 *   everything else                                      -> index.php
 *
 * @package jlife-platform
 */

// phpcs:disable WordPress -- dev-server shim, not WordPress code.

$jlife_root = rtrim( $_SERVER['DOCUMENT_ROOT'], '/' );
$jlife_path = urldecode( (string) parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) );

// Real files (and directories with an index.php) are served as-is.
if ( '/' !== $jlife_path && is_file( $jlife_root . $jlife_path ) && '.php' !== substr( $jlife_path, -4 ) ) {
	return false;
}

$jlife_target = $jlife_path;
if ( ! file_exists( $jlife_root . $jlife_target ) && preg_match( '#^/[_0-9a-zA-Z-]+(/(?:wp-(?:content|admin|includes).*|[^?]*\.php))$#', $jlife_path, $jlife_m ) ) {
	$jlife_target = $jlife_m[1]; // Strip the subsite slug, e.g. /hub/wp-admin/ -> /wp-admin/.
}

$jlife_file = $jlife_root . $jlife_target;
if ( is_dir( $jlife_file ) ) {
	$jlife_target = rtrim( $jlife_target, '/' ) . '/index.php';
	$jlife_file   = $jlife_root . $jlife_target;
}

if ( is_file( $jlife_file ) && '.php' !== substr( $jlife_file, -4 ) ) {
	$jlife_types = array(
		'css'   => 'text/css',
		'js'    => 'application/javascript',
		'json'  => 'application/json',
		'svg'   => 'image/svg+xml',
		'png'   => 'image/png',
		'jpg'   => 'image/jpeg',
		'jpeg'  => 'image/jpeg',
		'gif'   => 'image/gif',
		'webp'  => 'image/webp',
		'ico'   => 'image/x-icon',
		'woff'  => 'font/woff',
		'woff2' => 'font/woff2',
		'ttf'   => 'font/ttf',
		'map'   => 'application/json',
	);
	$jlife_ext   = strtolower( pathinfo( $jlife_file, PATHINFO_EXTENSION ) );
	header( 'Content-Type: ' . ( $jlife_types[ $jlife_ext ] ?? 'application/octet-stream' ) );
	readfile( $jlife_file );
	return true;
}

if ( ! is_file( $jlife_file ) ) {
	$jlife_target = '/index.php';
	$jlife_file   = $jlife_root . '/index.php';
}

$_SERVER['SCRIPT_NAME']     = $jlife_target;
$_SERVER['PHP_SELF']        = $jlife_target;
$_SERVER['SCRIPT_FILENAME'] = $jlife_file;
chdir( dirname( $jlife_file ) );
require $jlife_file;
