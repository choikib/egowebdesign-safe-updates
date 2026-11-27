<?php
/**
 * Safe updates watcher. Run by cron every 5 minutes on a server other than the sites it watches.
 *
 * Checks each site's /wp-json/egowebdesign/v1/status, emails when a site goes down or comes back
 * (and every 6 h while it stays down), and writes a JSON summary (states, 30-day uptime, incidents)
 * for the monitoring dashboard. Settings in config.json next to this file (see config.example.json).
 *
 * Sites to watch: the ones in config.json, plus those that signed up (public/register.php) and were
 * approved (public/approve.php). The approved list, with each site's report link, is also written for
 * the dashboard's daily routine (config.json "sites_file").
 *
 * php watch.php           normal run (cron)
 * php watch.php --test    sends a test email, changes nothing
 */

require __DIR__ . '/lib.php';

const REMIND_EVERY = 6 * 3600;  // Repeat the alert this often while a site stays down.
const RETRY_AFTER  = 20;        // Seconds before re-checking a site that failed, to rule out a blip.
const KEEP_DAYS    = 90;        // Incidents older than this are dropped.
const UPTIME_DAYS  = 30;

$dir  = __DIR__;
$conf = load_conf();
if ( ! $conf || ! $conf['sites'] ) {
	fwrite( STDERR, "config.json missing or incomplete\n" );
	exit( 1 );
}

if ( in_array( '--test', $argv, true ) ) {
	$state = json_decode( (string) @file_get_contents( "$dir/state.json" ), true ) ?: [ 'sites' => [] ];
	$ok    = alert( $conf, 'test', null, [], $state['sites'] );
	echo $ok ? "test email handed to the mail server\n" : "mail() failed\n";
	exit( $ok ? 0 : 1 );
}

// php watch.php --preview DIR: writes one sample of each email as HTML, sends nothing.
if ( in_array( '--preview', $argv, true ) ) {
	$out  = $argv[ array_search( '--preview', $argv, true ) + 1 ] ?? $dir;
	$site = $conf['sites'][0];
	$all  = [];
	foreach ( $conf['sites'] as $i => $s ) {
		$all[ $s['url'] ] = [ 'state' => 0 === $i ? 'down' : ( 1 === $i ? 'problem' : 'ok' ), 'detail' => '', 'since' => time() - 3600, 'checked' => time(), 'ms' => 420 ];
	}
	$samples = [
		'down'    => [ 'state' => 'down', 'detail' => 'no answer within 30 seconds', 'since' => time(), 'checked' => time(), 'http' => 0, 'ms' => 30001 ],
		'problem' => [ 'state' => 'problem', 'detail' => 'page checks fail: Accueil, Nous joindre (form)', 'since' => time(), 'checked' => time(), 'http' => 503, 'ms' => 812 ],
		'still'   => [ 'state' => 'down', 'detail' => 'the server answers with an error (HTTP 500)', 'since' => time() - 6 * 3600, 'checked' => time(), 'http' => 500, 'ms' => 1290 ],
		'back'    => [ 'state' => 'ok', 'detail' => '', 'since' => time(), 'checked' => time(), 'http' => 200, 'ms' => 240, 'was_since' => time() - 47 * 60 ],
		'test'    => [],
	];
	foreach ( $samples as $kind => $res ) {
		file_put_contents( "$out/email-$kind.html", alert_parts( $conf, $kind, 'test' === $kind ? null : $site, $res, $all )[2] );
	}
	echo "previews written to $out\n";
	exit( 0 );
}

// One run at a time: a slow run must not overlap the next one.
$lock = fopen( "$dir/.lock", 'c' );
if ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
	exit( 0 );
}

$state     = json_decode( (string) @file_get_contents( "$dir/state.json" ), true ) ?: [ 'sites' => [], 'incidents' => [], 'started' => time() ];
$now       = time();
$incidents = &$state['incidents'];
$alerts    = []; // Sent after every site is checked, so each email can list all of them up to date.

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
		$alerts[]       = [ 'ok' === $res['state'] ? 'back' : $res['state'], $site, $res + [ 'was_since' => $prev['since'] ?? $now ] ];
		$res['alerted'] = $now;
	} elseif ( 'down' === $res['state'] && $now - $res['alerted'] >= REMIND_EVERY ) {
		$alerts[]       = [ 'still', $site, $res ];
		$res['alerted'] = $now;
	}
	$state['sites'][ $id ] = $res;
}
foreach ( $alerts as $a ) {
	alert( $conf, $a[0], $a[1], $a[2], $state['sites'] );
}

// Sites no longer watched: forget them and close what was open.
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
if ( ! empty( $conf['sites_file'] ) ) {
	save( $conf['sites_file'], sites_list( $conf, $now ) );
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
		$why = [
			CURLE_OPERATION_TIMEDOUT => 'no answer within 30 seconds',
			CURLE_COULDNT_RESOLVE_HOST => 'its domain name does not resolve (DNS)',
			CURLE_COULDNT_CONNECT => 'the server refuses the connection',
			CURLE_SSL_CONNECT_ERROR => 'the secure connection (SSL) fails',
			CURLE_PEER_FAILED_VERIFICATION => 'its SSL certificate is invalid or expired',
		][ curl_errno( $ch ) ] ?? 'no answer (' . ( $err ?: 'connection failed' ) . ')';
		return $out + [ 'state' => 'down', 'detail' => $why ];
	}
	if ( 200 === $http && 'ok' === ( $json['status'] ?? '' ) ) {
		return $out + [ 'state' => 'ok', 'detail' => '' ];
	}
	if ( 503 === $http && in_array( $json['status'] ?? '', [ 'problem', 'checks-not-running' ], true ) ) {
		$detail = 'problem' === $json['status'] ? 'page checks fail: ' . implode( ', ', (array) ( $json['failing'] ?? [] ) ) : 'page checks stopped running';
		return $out + [ 'state' => 'problem', 'detail' => $detail ];
	}
	return $out + [ 'state' => 'down', 'detail' => 404 === $http ? 'its status link is gone (Safe updates deactivated?)' : ( $http >= 500 ? "the server answers with an error (HTTP $http)" : "unexpected answer (HTTP $http)" ) ];
}

/* ------------------------------------------------------------------ Emails */

/** Sends one alert. $kind: down | problem | still | back | test. $all: every site's latest state, listed at the bottom. */
function alert( $conf, $kind, $site, $res, $all ) {
	list( $subject, $text, $html ) = alert_parts( $conf, $kind, $site, $res, $all );
	return send( $conf, $subject, $text, $html );
}

/** [subject, plain text, HTML] of an alert. */
function alert_parts( $conf, $kind, $site, $res, $all ) {
	$name  = $site['name'] ?? '';
	$retry = 'and again ' . RETRY_AFTER . ' s later';
	$look  = [
		//         badge         subject prefix   title                         accent     tint
		'down'    => [ 'Down',       'DOWN: ',        "$name is down",              '#C4320A', '#FEF3EE' ],
		'still'   => [ 'Still down', 'Still down: ',  "$name is still down",        '#C4320A', '#FEF3EE' ],
		'problem' => [ 'Problem',    'Problem: ',     "$name has a problem",        '#B54708', '#FFF8EB' ],
		'back'    => [ 'Back up',    '',              "$name is back",              '#067647', '#ECFDF3' ],
		'test'    => [ 'Test',       '',              'Alerts reach you',           '#15151C', '#EEEDE8' ],
	][ $kind ];
	list( $badge, $prefix, $title, $accent, $tint ) = $look;

	$rows = [];
	switch ( $kind ) {
		case 'down':
			$lead   = "The site isn't working: {$res['detail']}.";
			$rows[] = [ 'Checked', when( $res['checked'] ) . ", $retry" ];
			break;
		case 'still':
			$lead   = 'Down for ' . duration( $res['checked'] - $res['since'] ) . ". Last answer: {$res['detail']}.";
			$rows[] = [ 'Down since', when( $res['since'] ) ];
			$rows[] = [ 'Next reminder', 'in ' . duration( REMIND_EVERY ) . ' if it is still down' ];
			break;
		case 'problem':
			$lead   = "The site is online, but {$res['detail']}.";
			$rows[] = [ 'Checked', when( $res['checked'] ) . ", $retry" ];
			break;
		case 'back':
			$lead   = 'It answers normally again after ' . duration( $res['checked'] - $res['was_since'] ) . '.';
			$rows[] = [ 'Interruption', when( $res['was_since'] ) . ' → ' . date( 'H:i', $res['checked'] ) ];
			$rows[] = [ 'Response time', number_format( $res['ms'] ) . ' ms' ];
			break;
		default:
			$n    = count( $conf['sites'] );
			$lead = "This is a test from the Safe updates watcher. It checks $n " . ( 1 === $n ? 'site' : 'sites' ) . ' every 5 minutes and writes to you only when something changes.';
	}
	if ( $site ) {
		array_unshift( $rows, [ 'Site', $site['url'] ] );
	}

	$button = null;
	if ( $site ) {
		$button = 'problem' === $kind
			? [ 'Open Safe updates', rtrim( $site['url'], '/' ) . '/wp-admin/options-general.php?page=msu-settings' ]
			: [ "Open $name", $site['url'] ];
	}

	// Plain text, for mail apps that don't show HTML.
	$text = "$title\n\n$lead\n\n";
	foreach ( $rows as $r ) {
		$text .= "{$r[0]}: {$r[1]}\n";
	}
	$text .= "\nAll sites:\n";
	foreach ( $conf['sites'] as $s ) {
		$text .= '- ' . $s['name'] . ': ' . state_label( $all[ $s['url'] ]['state'] ?? null ) . "\n";
	}
	$text .= "\nChecked every 5 minutes. You hear from the watcher only when something changes.";

	return [ "[Safe updates] $prefix" . ( 'test' === $kind ? 'Watcher test' : ( $prefix ? $name : $title ) ), $text, email_html( $conf, compact( 'badge', 'title', 'lead', 'accent', 'tint', 'rows', 'button' ), $all ) ];
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

/** For the dashboard's daily routine: every watched site, with its report link once it has signed up. */
function sites_list( $conf, $now ) {
	$reg   = registry();
	$sites = [];
	foreach ( $conf['sites'] as $s ) {
		$r       = $reg[ site_key( $s['url'] ) ] ?? [];
		$sites[] = [
			'name'       => $s['name'],
			'url'        => $s['url'],
			'report_url' => $r['report_url'] ?? null,
			'version'    => $r['version'] ?? null,
			'last_seen'  => isset( $r['last_seen'] ) ? gmdate( 'c', $r['last_seen'] ) : null,
		];
	}
	return [ 'generated' => gmdate( 'c', $now ), 'sites' => $sites ];
}
