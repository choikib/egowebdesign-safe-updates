<?php
/**
 * Shared by the watcher (watch.php) and its two web pages (public/register.php, public/approve.php):
 * settings, the registry of sites that signed up, and the email design.
 */

const PENDING_MAX = 20; // Sign-ups waiting for approval beyond this are refused, so nobody can flood the inbox.

/** config.json, with `sites` = every site to watch (the ones listed there + approved sign-ups). */
function load_conf() {
	$conf = json_decode( (string) @file_get_contents( __DIR__ . '/config.json' ), true );
	if ( ! $conf || empty( $conf['email'] ) ) {
		return null;
	}
	date_default_timezone_set( $conf['timezone'] ?? 'America/Toronto' ); // Times in emails.
	$conf['seed']  = $conf['sites'] ?? [];
	$conf['sites'] = watched_sites( $conf );
	return $conf;
}

/** "granddefi.egowebdesign.ca" or "metalus.qc.ca/dev": one site whatever the scheme or trailing slash. */
function site_key( $url ) {
	$p = parse_url( (string) $url );
	return strtolower( $p['host'] ?? '' ) . rtrim( $p['path'] ?? '', '/' );
}

function watched_sites( $conf ) {
	$sites = [];
	foreach ( $conf['seed'] as $s ) {
		$sites[ site_key( $s['url'] ) ] = $s;
	}
	foreach ( registry() as $key => $r ) {
		if ( 'approved' === $r['status'] && ! isset( $sites[ $key ] ) ) {
			$sites[ $key ] = [ 'name' => $r['name'], 'url' => $r['url'] ];
		}
	}
	return array_values( $sites );
}

/* ------------------------------------------------------------------ Registry */

/** Reads the registry, or changes it under a lock when $change (function ( &$registry )) is given; returns what $change returns. */
function registry( $change = null ) {
	$fh = fopen( __DIR__ . '/registry.json', 'c+' );
	if ( ! $fh ) {
		return $change ? null : [];
	}
	flock( $fh, $change ? LOCK_EX : LOCK_SH );
	$data   = json_decode( (string) stream_get_contents( $fh ), true ) ?: [];
	$result = $data;
	if ( $change ) {
		$result = $change( $data );
		ftruncate( $fh, 0 );
		rewind( $fh );
		fwrite( $fh, json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		fflush( $fh );
	}
	flock( $fh, LOCK_UN );
	fclose( $fh );
	return $result;
}

/**
 * A site asks to be monitored. It must be on https, and its report link must answer as Safe updates, for that same site.
 * Known sites are refreshed (name, report link, version). New ones wait for approval by email, unless config.json lists them.
 * Returns [HTTP code, answer].
 */
function register_site( $conf, $in ) {
	$url    = rtrim( (string) ( $in['url'] ?? '' ), '/' );
	$report = (string) ( $in['report_url'] ?? '' );
	$host   = strtolower( (string) parse_url( $url, PHP_URL_HOST ) );
	if ( ! preg_match( '#^https://#i', $url ) || ! $host || ! preg_match( '#^https://#i', $report )
		|| strtolower( (string) parse_url( $report, PHP_URL_HOST ) ) !== $host || false === strpos( $report, 'egowebdesign/v1/report' ) ) {
		return [ 400, [ 'error' => 'A https site address and its Safe updates report link are needed.' ] ];
	}
	if ( ! public_host( $host ) ) {
		return [ 400, [ 'error' => 'This address is not reachable from the internet.' ] ];
	}
	$rep = fetch_json( $report );
	if ( ! $rep || empty( $rep['plugin'] ) || strtolower( (string) ( $rep['site'] ?? '' ) ) !== $host ) {
		return [ 422, [ 'error' => 'The report link did not answer as Safe updates for this site.' ] ];
	}

	$key    = site_key( $url );
	$seeded = in_array( $key, array_map( 'site_key', array_column( $conf['seed'], 'url' ) ), true );
	$entry  = [
		'name'       => mb_substr( trim( strip_tags( (string) ( $rep['name'] ?? '' ) ) ), 0, 80 ) ?: $host,
		'url'        => $url,
		'report_url' => $report,
		'status_url' => $url . '/wp-json/egowebdesign/v1/status',
		'version'    => mb_substr( (string) $rep['plugin'], 0, 20 ),
		'last_seen'  => time(),
	];
	$ask    = null;
	$status = registry( function ( &$reg ) use ( $key, $entry, $seeded, &$ask ) {
		if ( isset( $reg[ $key ] ) ) {
			$reg[ $key ] = $entry + $reg[ $key ]; // Fresh details, same status.
			return $reg[ $key ]['status'];
		}
		if ( ! $seeded && count( array_filter( $reg, function ( $r ) { return 'pending' === $r['status']; } ) ) >= PENDING_MAX ) {
			return 'busy';
		}
		$reg[ $key ] = $entry + [ 'status' => $seeded ? 'approved' : 'pending', 'first_seen' => time(), 'token' => $seeded ? null : bin2hex( random_bytes( 16 ) ) ];
		if ( ! $seeded ) {
			$ask = $reg[ $key ];
		}
		return $reg[ $key ]['status'];
	} );
	if ( 'busy' === $status ) {
		return [ 429, [ 'error' => 'Too many sites are waiting for approval. Try again later.' ] ];
	}
	if ( $ask ) {
		approval_mail( $conf, $ask );
	}
	return [ 200, [ 'status' => $status ] ];
}

/** Refuses names that point inside a network (localhost, 10.x, 192.168.x...), so sign-ups can't make this server call them. */
function public_host( $host ) {
	$ips = gethostbynamel( $host ) ?: [];
	foreach ( $ips as $ip ) {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return false;
		}
	}
	return (bool) $ips;
}

function fetch_json( $url ) {
	$ch = curl_init( $url );
	curl_setopt_array( $ch, [
		CURLOPT_RETURNTRANSFER  => true,
		CURLOPT_FOLLOWLOCATION  => true,
		CURLOPT_MAXREDIRS       => 2,
		CURLOPT_PROTOCOLS       => CURLPROTO_HTTPS,
		CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
		CURLOPT_TIMEOUT         => 20,
		CURLOPT_USERAGENT       => 'EgoWebDesign Safe Updates watcher',
	] );
	$body = curl_exec( $ch );
	return 200 === (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ) && is_string( $body ) && strlen( $body ) < 2000000 ? json_decode( $body, true ) : null;
}

function approval_mail( $conf, $site ) {
	$link = ( $conf['approve_url'] ?? '' ) . '?t=' . $site['token'];
	$m    = [
		'badge'  => 'New site',
		'title'  => "{$site['name']} wants to be monitored",
		'lead'   => "A site running Safe updates asked to join your monitoring. Add it to watch it every 5 minutes and show it on your dashboard. If you don't know this site, ignore it: it won't ask again.",
		'accent' => '#1F4FD8',
		'tint'   => '#EEF3FF',
		'rows'   => [ [ 'Site', $site['url'] ], [ 'Safe updates', $site['version'] ], [ 'Asked', when( time() ) ] ],
		'button' => [ 'Review and add', $link ],
		'footer' => 'Nothing changes until you choose on that page.',
	];
	$text = "{$m['title']}\n\n{$m['lead']}\n\nSite: {$site['url']}\nSafe updates: {$site['version']}\n\nReview and add: $link\n";
	return send( $conf, "[Safe updates] New site: {$site['name']}", $text, email_html( $conf, $m, null ) );
}

/* ------------------------------------------------------------------ Emails */

function state_label( $state ) {
	return [ 'ok' => 'OK', 'problem' => 'Problem', 'down' => 'Down' ][ $state ] ?? 'Not checked yet';
}

function when( $t ) {
	return date( 'M j, H:i', $t );
}

function duration( $s ) {
	return $s < 3600 ? max( 1, round( $s / 60 ) ) . ' min' : ( $s < 172800 ? round( $s / 3600, 1 ) . ' h' : round( $s / 86400, 1 ) . ' days' );
}

/**
 * Table layout and inline styles only: what Gmail, Outlook and Apple Mail all render.
 * $m: badge, title, lead, accent, tint, rows [[label, value]], button [label, url] or null, footer (optional).
 * $all: every site's latest state for the "All sites" list, or null to leave it out.
 */
function email_html( $conf, $m, $all ) {
	$e    = function ( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); };
	$font = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
	$dot  = [ 'ok' => '#12B76A', 'problem' => '#F79009', 'down' => '#F04438' ];

	$rows = '';
	foreach ( $m['rows'] as $r ) {
		$value = preg_match( '#^https?://#', $r[1] ) ? '<a href="' . $e( $r[1] ) . '" style="color:#1F4FD8;text-decoration:none;">' . $e( preg_replace( '#^https?://#', '', $r[1] ) ) . '</a>' : $e( $r[1] );
		$rows .= '<tr><td style="' . $font . 'padding:10px 0;border-top:1px solid #EAECF0;color:#667085;font-size:13px;width:120px;vertical-align:top;">' . $e( $r[0] ) . '</td>'
			. '<td style="' . $font . 'padding:10px 0;border-top:1px solid #EAECF0;color:#101828;font-size:14px;vertical-align:top;">' . $value . '</td></tr>';
	}

	$button = '';
	if ( $m['button'] ) {
		$button = '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 4px;"><tr><td style="border-radius:8px;background:' . $m['accent'] . ';">'
			. '<a href="' . $e( $m['button'][1] ) . '" style="' . $font . 'display:inline-block;padding:12px 20px;font-size:14px;font-weight:600;color:#FFFFFF;text-decoration:none;border-radius:8px;">' . $e( $m['button'][0] ) . ' &rarr;</a></td></tr></table>';
	}

	$sites = '';
	if ( null !== $all ) {
		foreach ( $conf['sites'] as $s ) {
			$st     = $all[ $s['url'] ]['state'] ?? null;
			$sites .= '<tr><td style="' . $font . 'padding:8px 0;font-size:14px;color:#101828;">'
				. '<span style="display:inline-block;width:8px;height:8px;border-radius:4px;background:' . ( $dot[ $st ] ?? '#98A2B3' ) . ';margin-right:10px;vertical-align:middle;"></span>' . $e( $s['name'] ) . '</td>'
				. '<td align="right" style="' . $font . 'padding:8px 0;font-size:13px;color:' . ( 'ok' === $st ? '#667085' : ( $dot[ $st ] ?? '#98A2B3' ) ) . ';font-weight:' . ( 'ok' === $st ? '400' : '600' ) . ';">' . $e( state_label( $st ) ) . '</td></tr>';
		}
		$sites = '<tr><td style="padding:20px 32px 24px;">'
			. '<div style="' . $font . 'font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#98A2B3;padding-bottom:4px;border-bottom:1px solid #EAECF0;">All sites</div>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">' . $sites . '</table></td></tr>';
	}

	return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><meta name="supported-color-schemes" content="light"><title>' . $e( $m['title'] ) . '</title></head>'
		. '<body style="margin:0;padding:0;background:#F2F4F7;">'
		. '<div style="display:none;max-height:0;overflow:hidden;">' . $e( $m['lead'] ) . '</div>'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F2F4F7;"><tr><td align="center" style="padding:32px 16px;">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">'

		// Brand line
		. '<tr><td style="' . $font . 'padding:0 4px 14px;font-size:13px;color:#475467;">'
		. '<span style="display:inline-block;width:22px;height:22px;line-height:22px;text-align:center;border-radius:6px;background:#101828;color:#FFFFFF;font-size:12px;font-weight:700;vertical-align:middle;margin-right:8px;">S</span>'
		. '<span style="vertical-align:middle;font-weight:600;color:#101828;">Safe updates</span><span style="vertical-align:middle;"> &nbsp;·&nbsp; Watcher</span></td></tr>'

		// Card
		. '<tr><td style="background:#FFFFFF;border:1px solid #EAECF0;border-radius:14px;overflow:hidden;">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td style="height:4px;background:' . $m['accent'] . ';font-size:0;line-height:0;">&nbsp;</td></tr>'
		. '<tr><td style="padding:28px 32px ' . ( $sites ? '8px' : '28px' ) . ';">'
		. '<span style="' . $font . 'display:inline-block;padding:4px 10px;border-radius:999px;background:' . $m['tint'] . ';color:' . $m['accent'] . ';font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">' . $e( $m['badge'] ) . '</span>'
		. '<h1 style="' . $font . 'margin:14px 0 8px;font-size:24px;line-height:1.25;font-weight:700;color:#101828;">' . $e( $m['title'] ) . '</h1>'
		. '<p style="' . $font . 'margin:0;font-size:15px;line-height:1.55;color:#344054;">' . $e( $m['lead'] ) . '</p>'
		. ( $rows ? '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:20px;">' . $rows . '</table>' : '' )
		. $button
		. '</td></tr>'
		. $sites
		. '</table></td></tr>'

		// Footer
		. '<tr><td style="' . $font . 'padding:16px 8px 0;font-size:12px;line-height:1.5;color:#98A2B3;text-align:center;">'
		. $e( $m['footer'] ?? 'Checked every 5 minutes. You hear from the watcher only when something changes.' )
		. '</td></tr></table></td></tr></table></body></html>';
}

/** Plain text + HTML in one message; the subject is encoded so accents (Défi, Métalus) survive. */
function send( $conf, $subject, $text, $html ) {
	$from     = $conf['from'] ?? 'watcher@' . gethostname();
	$boundary = 'msu-' . bin2hex( random_bytes( 12 ) );
	$part     = function ( $type, $content ) use ( $boundary ) {
		return "--$boundary\r\nContent-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split( base64_encode( $content ) );
	};
	$headers = "From: Safe updates watcher <$from>\r\nMIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"$boundary\"";
	$body    = $part( 'text/plain', $text ) . $part( 'text/html', $html ) . "--$boundary--\r\n";
	return mail( $conf['email'], '=?UTF-8?B?' . base64_encode( $subject ) . '?=', $body, $headers, "-f$from" );
}

/** Write to a temp file then rename, so readers never see half a file. */
function save( $path, $data ) {
	file_put_contents( "$path.tmp", json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	rename( "$path.tmp", $path );
}
