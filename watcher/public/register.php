<?php
/**
 * Where sites running Safe updates sign up for monitoring (POST, JSON: url, name, report_url, version).
 * Lives in the web folder; the code and data stay in ~/msu-watcher.
 */
require dirname( __DIR__, 2 ) . '/msu-watcher/lib.php';

header( 'Content-Type: application/json' );
header( 'Cache-Control: no-store' );
header( 'X-Robots-Tag: noindex' );

if ( 'POST' !== $_SERVER['REQUEST_METHOD'] ) {
	http_response_code( 405 );
	exit( '{"error":"POST only"}' );
}
$conf = load_conf();
$in   = json_decode( (string) file_get_contents( 'php://input', false, null, 0, 8192 ), true );
if ( ! $conf || ! is_array( $in ) ) {
	http_response_code( $conf ? 400 : 503 );
	exit( '{"error":"bad request"}' );
}
list( $code, $answer ) = register_site( $conf, $in );
http_response_code( $code );
echo json_encode( $answer );
