<?php
/**
 * Safe updates watcher. Run by cron every 5 minutes on a server other than the sites it watches.
 *
 * Checks each site's /wp-json/egowebdesign/v1/status, emails when a site goes down or comes back
 * (and every 6 h while it stays down), and writes a JSON summary (states, 30-day uptime, incidents)
 * for the monitoring dashboard. Settings in config.json next to this file (see config.example.json).
 *
 * php watch.php           normal run (cron)
 * php watch.php --test    sends a test email, changes nothing
 */

const REMIND_EVERY = 6 * 3600;  // Repeat the alert this often while a site stays down.
const RETRY_AFTER  = 20;        // Seconds before re-checking a site that failed, to rule out a blip.
const KEEP_DAYS    = 90;        // Incidents older than this are dropped.
const UPTIME_DAYS  = 30;

$dir  = __DIR__;
$conf = json_decode( (string) @file_get_contents( "$dir/config.json" ), true );
if ( ! $conf || empty( $conf['sites'] ) || empty( $conf['email'] ) ) {
	fwrite( STDERR, "config.json missing or incomplete\n" );
	exit( 1 );
}

if ( in_array( '--test', $argv, true ) ) {
	$ok = send( $conf, 'Watcher test', "This is a test from the Safe updates watcher.\n\nIt checks: " . implode( ', ', array_column( $conf['sites'], 'name' ) ) . '.' );
	echo $ok ? "test email handed to the mail server\n" : "mail() failed\n";
	exit( $ok ? 0 : 1 );
}

// One run at a time: a slow run must not overlap the next one.
$lock = fopen( "$dir/.lock", 'c' );
if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
	exit( 0 );
}

$state     = json_decode( (string) @file_get_contents( "$dir/state.json" ), true ) ?: [ 'sites' => [], 'incidents' => [], 'started' => time() ];
$now       = time();
$incidents = &$state['incidents'];

foreach ( $conf['sites'] as $site ) {
	$id   = $site['url'];
	$prev = $state['sites'][ $id ] ?? null;
	$res  = check( $site['url'] );
	if ( 'ok' !== $res['state'] ) {
		sleep( RETRY_AFTER );
		$res = check( $site['url'] );
	}
	$res['checked'] = $now;
	$res['since']   = $prev && $prev['state'] === $res['state'] ? $prev['since'] : $now;
	$res['alerted'] = $prev['alerted'] ?? 0;

	$changed = ! $prev || $prev['state'] !== $res['state'];
	if ( $changed && $prev && 'ok' !== $prev['state'] ) {
		close_incident( $incidents, $id, $now );
	}
	if ( $changed && 'ok' !== $res['state'] ) {
		$incidents[] = [ 'site' => $site['name'], 'url' => $id, 'state' => $res['state'], 'detail' => $res['detail'], 'start' => $now, 'end' => null ];
	}

	if ( $changed && ( $prev || 'ok' !== $res['state'] ) ) {
		if ( 'ok' === $res['state'] ) {
			send( $conf, "{$site['name']} is back", "{$site['name']} answers normally again after " . duration( $now - $prev['since'] ) . ".\n\n{$site['url']}" );
		} else {
			send( $conf, ( 'down' === $res['state'] ? 'DOWN: ' : 'Problem: ' ) . $site['name'], message( $site, $res ) );
		}
		$res['alerted'] = $now;
	} elseif ( 'down' === $res['state'] && $now - $res['alerted'] >= REMIND_EVERY ) {
		send( $conf, "Still down: {$site['name']}", 'Down for ' . duration( $now - $res['since'] ) . ".\n\n" . message( $site, $res ) );
		$res['alerted'] = $now;
	}
	$state['sites'][ $id ] = $res;
}

// Sites removed from config.json: forget them and close what was open.
foreach ( array_diff( array_keys( $state['sites'] ), array_column( $conf['sites'], 'url' ) ) as $gone ) {
	close_incident( $incidents, $gone, $now );
	unset( $state['sites'][ $gone ] );
}
$incidents = array_values( array_filter( $incidents, function ( $i ) use ( $now ) { return null === $i['end'] || $i['end'] > $now - KEEP_DAYS * 86400; } ) );

$state['last_run'] = $now;
save( "$dir/state.json", $state );
if ( ! empty( $conf['public_file'] ) ) {
	save( $conf['public_file'], summary( $conf, $state, $now ) );
}

/* ------------------------------------------------------------------ */

/** [state ok|problem|down, detail, http, ms]. "problem" = the site answers but its own page checks fail or stopped. */
function check( $url ) {
	$ch = curl_init( rtrim( $url, '/' ) . '/wp-json/egowebdesign/v1/status' );
	curl_setopt_array( $ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_MAXREDIRS      => 3,
		CURLOPT_TIMEOUT        => 30,
		CURLOPT_CONNECTTIMEOUT => 15,
		CURLOPT_USERAGENT      => 'EgoWebDesign Safe Updates watcher',
		CURLOPT_HTTPHEADER     => [ 'Cache-Control: no-cache' ],
	] );
	$body = curl_exec( $ch );
	$http = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	$ms   = (int) round( curl_getinfo( $ch, CURLINFO_TOTAL_TIME ) * 1000 );
	$err  = curl_error( $ch );
	$json = is_string( $body ) ? json_decode( $body, true ) : null;
	$out  = [ 'http' => $http, 'ms' => $ms ];

	if ( false === $body || 0 === $http ) {
		return $out + [ 'state' => 'down', 'detail' => 'no answer (' . ( $err ?: 'connection failed' ) . ')' ];
	}
	if ( 200 === $http && 'ok' === ( $json['status'] ?? '' ) ) {
		return $out + [ 'state' => 'ok', 'detail' => '' ];
	}
	if ( 503 === $http && in_array( $json['status'] ?? '', [ 'problem', 'checks-not-running' ], true ) ) {
		$detail = 'problem' === $json['status'] ? 'page checks fail: ' . implode( ', ', (array) ( $json['failing'] ?? [] ) ) : 'page checks stopped running';
		return $out + [ 'state' => 'problem', 'detail' => $detail ];
	}
	return $out + [ 'state' => 'down', 'detail' => 404 === $http ? 'status link not found (Safe updates off?)' : "HTTP $http" ];
}

function message( $site, $res ) {
	$what = 'down' === $res['state'] ? "{$site['name']} does not answer properly: {$res['detail']}." : "{$site['name']} is online, but {$res['detail']}.";
	return "$what\n\n{$site['url']}\nChecked " . gmdate( 'Y-m-d H:i', $res['checked'] ) . " UTC, and again " . RETRY_AFTER . " s later.";
}

function close_incident( &$incidents, $url, $now ) {
	foreach ( $incidents as &$i ) {
		if ( $i['url'] === $url && null === $i['end'] ) {
			$i['end'] = $now;
		}
	}
}

/** What the dashboard reads. */
function summary( $conf, $state, $now ) {
	$fmt   = function ( $t ) { return $t ? gmdate( 'c', $t ) : null; };
	$from  = max( $now - UPTIME_DAYS * 86400, (int) $state['started'] );
	$sites = [];
	foreach ( $conf['sites'] as $site ) {
		$s    = $state['sites'][ $site['url'] ] ?? [];
		$down = 0;
		foreach ( $state['incidents'] as $i ) {
			if ( $i['url'] === $site['url'] && 'down' === $i['state'] ) {
				$down += max( 0, min( $i['end'] ?? $now, $now ) - max( $i['start'], $from ) );
			}
		}
		$sites[] = [
			'name'        => $site['name'],
			'url'         => $site['url'],
			'state'       => $s['state'] ?? null,
			'detail'      => $s['detail'] ?? '',
			'since'       => $fmt( $s['since'] ?? 0 ),
			'checked'     => $fmt( $s['checked'] ?? 0 ),
			'response_ms' => $s['ms'] ?? null,
			'uptime_pct'  => $now > $from ? round( 100 * ( 1 - $down / ( $now - $from ) ), 2 ) : null,
		];
	}
	$incidents = array_map( function ( $i ) use ( $fmt, $now ) {
		return [ 'site' => $i['site'], 'state' => $i['state'], 'detail' => $i['detail'], 'start' => $fmt( $i['start'] ), 'end' => $fmt( $i['end'] ), 'minutes' => (int) round( ( ( $i['end'] ?? $now ) - $i['start'] ) / 60 ) ];
	}, array_reverse( $state['incidents'] ) );

	return [
		'generated'      => $fmt( $now ),
		'every_minutes'  => 5,
		'uptime_since'   => $fmt( $from ),
		'sites'          => $sites,
		'incidents'      => $incidents,
	];
}

function send( $conf, $subject, $body ) {
	$from = $conf['from'] ?? 'watcher@' . gethostname();
	$body .= "\n\n— Safe updates watcher (" . gethostname() . ')';
	return mail( $conf['email'], '[Safe updates] ' . $subject, $body, "From: Safe updates watcher <$from>\r\nContent-Type: text/plain; charset=UTF-8", "-f$from" );
}

function duration( $s ) {
	return $s < 3600 ? max( 1, round( $s / 60 ) ) . ' min' : ( $s < 172800 ? round( $s / 3600, 1 ) . ' h' : round( $s / 86400, 1 ) . ' days' );
}

/** Write to a temp file then rename, so readers never see half a file. */
function save( $path, $data ) {
	file_put_contents( "$path.tmp", json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	rename( "$path.tmp", $path );
}
