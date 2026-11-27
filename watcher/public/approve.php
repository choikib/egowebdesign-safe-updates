<?php
/**
 * Opened from the "New site wants to be monitored" email. Showing the page changes nothing (mail
 * scanners open links on their own); only the buttons, which post the email's token, do.
 */
require dirname( __DIR__, 2 ) . '/msu-watcher/lib.php';

header( 'Cache-Control: no-store' );
header( 'X-Robots-Tag: noindex' );
header( 'Referrer-Policy: no-referrer' );

$conf  = load_conf();
$token = preg_replace( '/[^a-f0-9]/', '', (string) ( $_POST['t'] ?? $_GET['t'] ?? '' ) );
$do    = $_POST['do'] ?? '';
$site  = null;
$done  = '';

if ( $conf && 32 === strlen( $token ) ) {
	$site = registry( function ( &$reg ) use ( $token, $do, &$done ) {
		foreach ( $reg as $key => &$r ) {
			if ( ( $r['token'] ?? '' ) !== $token ) {
				continue;
			}
			if ( 'POST' === $_SERVER['REQUEST_METHOD'] && in_array( $do, [ 'approve', 'ignore' ], true ) ) {
				$r['status']  = 'approve' === $do ? 'approved' : 'ignored';
				$r['decided'] = time();
				$r['token']   = null;
				$done         = $r['status'];
			}
			return $r;
		}
		return null;
	} );
}

$e = function ( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' ); };
if ( 'approved' === $done ) {
	$badge = [ 'Added', '#067647', '#ECFDF3' ];
	$title = "{$site['name']} is now monitored";
	$lead  = 'The watcher checks it within 5 minutes and every 5 minutes after that. It shows on your dashboard after the next daily update.';
} elseif ( 'ignored' === $done ) {
	$badge = [ 'Ignored', '#475467', '#F6F5F1' ];
	$title = "{$site['name']} was ignored";
	$lead  = 'It is not monitored and will not ask again.';
} elseif ( $site ) {
	$badge = [ 'New site', '#15151C', '#EEEDE8' ];
	$title = "{$site['name']} wants to be monitored";
	$lead  = 'Add it to watch it every 5 minutes and show it on your dashboard. If you don\'t know this site, ignore it: it won\'t ask again.';
} else {
	$badge = [ 'Link used', '#475467', '#F6F5F1' ];
	$title = 'Nothing to decide here';
	$lead  = 'This link was already used, or it is not valid.';
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
<title><?php echo $e( $title ); ?> · Safe updates</title>
<style>
	:root { color-scheme: light; }
	* { box-sizing: border-box; }
	body { margin: 0; padding: 40px 16px; background: #F6F5F1; color: #15151C; font: 15px/1.55 -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; }
	.wrap { max-width: 560px; margin: 0 auto; }
	.brand { display: flex; align-items: center; gap: 8px; margin: 0 4px 14px; font-size: 13px; color: #475467; }
	.brand b { color: #15151C; }
	.logo { width: 24px; height: 24px; }
	.card { background: #fff; border: 1px solid #E4E3DE; border-radius: 14px; overflow: hidden; }
	.bar { height: 4px; background: <?php echo $badge[1]; ?>; }
	.body { padding: 28px 32px; }
	.badge { display: inline-block; padding: 4px 10px; border-radius: 999px; background: <?php echo $badge[2]; ?>; color: <?php echo $badge[1]; ?>; font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
	h1 { margin: 14px 0 8px; font-size: 24px; line-height: 1.25; }
	p { margin: 0; color: #344054; }
	dl { display: grid; grid-template-columns: 120px 1fr; margin: 20px 0 0; }
	dt, dd { margin: 0; padding: 10px 0; border-top: 1px solid #E4E3DE; }
	dt { color: #667085; font-size: 13px; }
	dd { font-size: 14px; overflow-wrap: anywhere; }
	dd a { color: #15151C; text-decoration: underline; text-decoration-color: #FF5A36; text-underline-offset: 3px; }
	form { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 24px; }
	button { font: inherit; font-size: 14px; font-weight: 600; padding: 12px 20px; border-radius: 8px; border: 1px solid transparent; cursor: pointer; }
	.add { background: #15151C; color: #fff; }
	.add:hover { background: #2C2C36; }
	.ignore { background: #fff; color: #344054; border-color: #D0D5DD; }
	.ignore:hover { background: #F9FAFB; }
	button:focus-visible { outline: 3px solid #FF5A36; outline-offset: 2px; }
	.foot { margin-top: 16px; text-align: center; font-size: 12px; color: #98A2B3; }
	@media (max-width: 480px) { .body { padding: 24px 20px; } dl { grid-template-columns: 1fr; } dt { border-top: 1px solid #E4E3DE; padding-bottom: 0; } dd { border-top: 0; } }
</style>
</head>
<body>
<div class="wrap">
	<div class="brand"><img class="logo" src="a2-icon-72.png" width="24" height="24" alt="A2 Studio"><b>Safe updates</b> · Watcher</div>
	<div class="card">
		<div class="bar"></div>
		<div class="body">
			<span class="badge"><?php echo $e( $badge[0] ); ?></span>
			<h1><?php echo $e( $title ); ?></h1>
			<p><?php echo $e( $lead ); ?></p>
			<?php if ( $site ) : ?>
			<dl>
				<dt>Site</dt><dd><a href="<?php echo $e( $site['url'] ); ?>" target="_blank" rel="noopener"><?php echo $e( preg_replace( '#^https?://#', '', $site['url'] ) ); ?></a></dd>
				<dt>Safe updates</dt><dd><?php echo $e( $site['version'] ); ?></dd>
				<dt>First asked</dt><dd><?php echo $e( when( $site['first_seen'] ) ); ?></dd>
			</dl>
			<?php endif; ?>
			<?php if ( $site && ! $done ) : ?>
			<form method="post">
				<input type="hidden" name="t" value="<?php echo $e( $token ); ?>">
				<button class="add" name="do" value="approve">Add to monitoring</button>
				<button class="ignore" name="do" value="ignore">Ignore this site</button>
			</form>
			<?php endif; ?>
		</div>
	</div>
	<p class="foot">Safe updates by A2 Studio</p>
</div>
</body>
</html>
