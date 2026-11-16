<?php
/**
 * Plugin Name: EgoWebDesign – Safe updates
 * Description: Runs WordPress, plugin and theme updates automatically, but only after a fresh UpdraftPlus backup. Holds big releases for 7 days, checks the key pages after each update, and puts back the previous version of a plugin or theme that breaks the site. Also publishes a private update report (WordPress, theme and plugin versions) for the EgoWebDesign monitoring dashboard. Updates itself from GitHub. Settings: Settings > Safe updates.
 * Version: 2.1.0
 * Author: EgoWebDesign
 * Requires PHP: 7.4
 * Update URI: https://github.com/choikib/egowebdesign-safe-updates
 */

defined( 'ABSPATH' ) || exit;

final class EgoWebDesign_Safe_Updates {

	const VERSION        = '2.1.0';
	const REPO           = 'choikib/egowebdesign-safe-updates'; // New versions = tags "vX.Y.Z" on this public GitHub repo.
	const DEFAULT_EMAIL  = 'veronneau.mathieu@gmail.com';
	const HOLD_DAYS      = 7;                     // Big releases (x.Y changes) wait this long before installing.
	const BACKUP_MAX_AGE = 20 * HOUR_IN_SECONDS;  // A backup older than this doesn't count as "fresh".
	const STALE_CHECK    = 36 * HOUR_IN_SECONDS;  // The status page reports a problem if checks stop running.

	/** True while WordPress's automatic updater is running (not when an admin looks at the Plugins screen). */
	private static $in_run = false;

	/* ------------------------------------------------------------------ Settings */

	/** Settings with their defaults. Stored in one option, edited at Settings > Safe updates. */
	private static function settings() {
		$s = get_option( 'msu_settings', [] );
		return wp_parse_args( is_array( $s ) ? $s : [], [
			'email'   => self::DEFAULT_EMAIL,
			'label'   => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'pages'   => "Accueil | / |",
			'markers' => "</html> | page is cut off",
		] );
	}

	/** First run: create the report key. */
	public static function setup() {
		if ( get_option( 'msu_version' ) === self::VERSION ) {
			return;
		}
		if ( ! get_option( 'msu_report_key' ) ) {
			update_option( 'msu_report_key', wp_generate_password( 32, false ), false );
		}
		update_option( 'msu_version', self::VERSION, false );
	}

	/** Pages checked after every update and twice a day: [label, path, [markers that page must contain]]. */
	private static function pages() {
		$pages = [];
		foreach ( preg_split( '/\R/', self::settings()['pages'] ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( count( $parts ) < 2 || '' === $parts[1] ) {
				continue;
			}
			$markers = isset( $parts[2] ) && '' !== $parts[2] ? array_filter( array_map( 'trim', explode( ',', $parts[2] ) ) ) : [];
			$pages[] = [ $parts[0] ?: $parts[1], '/' . ltrim( $parts[1], '/' ), array_values( $markers ) ];
		}
		return $pages ?: [ [ 'Home', '/', [] ] ];
	}

	/** Text every checked page must contain => message shown when it is missing. */
	private static function common_markers() {
		$markers = [];
		foreach ( preg_split( '/\R/', self::settings()['markers'] ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( '' !== $parts[0] ) {
				$markers[ $parts[0] ] = $parts[1] ?? '';
			}
		}
		return $markers;
	}

	public static function init() {
		add_action( 'init', [ __CLASS__, 'setup' ], 5 );
		add_action( 'admin_menu', [ __CLASS__, 'settings_menu' ] );
		add_action( 'admin_post_msu_save_settings', [ __CLASS__, 'admin_save_settings' ] );
		add_action( 'admin_post_msu_new_key', [ __CLASS__, 'admin_new_key' ] );
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), function ( $links ) {
			array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=msu-settings' ) ) . '">Settings</a>' );
			return $links;
		} );

		add_action( 'wp_maybe_auto_update', function () { self::$in_run = true; }, 1 );

		foreach ( [ 'plugin', 'theme', 'core' ] as $type ) {
			add_filter( "auto_update_{$type}", function ( $update, $item ) use ( $type ) {
				return self::should_update( $type, $update, $item );
			}, 99, 2 );
		}

		add_filter( 'upgrader_pre_install', [ __CLASS__, 'save_copy' ], 10, 2 );
		add_action( 'automatic_updates_complete', [ __CLASS__, 'after_auto_updates' ] );
		add_action( 'upgrader_process_complete', [ __CLASS__, 'after_manual_update' ], 10, 2 );

		add_action( 'msu_run_backup', function () {
			do_action( 'updraft_backupnow_backup_all', [] );
		} );
		add_action( 'msu_check', [ __CLASS__, 'scheduled_check' ] );
		add_action( 'init', function () {
			if ( ! wp_next_scheduled( 'msu_check' ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', 'msu_check' );
			}
		} );

		add_action( 'rest_api_init', [ __CLASS__, 'register_status_route' ] );
		add_action( 'wp_dashboard_setup', [ __CLASS__, 'dashboard_widget' ] );
		add_action( 'admin_post_msu_check_now', [ __CLASS__, 'admin_check_now' ] );
		add_action( 'admin_post_msu_restore', [ __CLASS__, 'admin_restore' ] );

		add_filter( 'update_plugins_github.com', [ __CLASS__, 'check_own_update' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ __CLASS__, 'fix_own_folder' ], 10, 4 );
	}

	/* ------------------------------------------------------------------ Updates of this plugin (GitHub) */

	/** Newest version on GitHub (the Version line on the main branch), asked at most once an hour. WordPress compares it. */
	public static function check_own_update( $update, $data, $file ) {
		if ( plugin_basename( __FILE__ ) !== $file ) {
			return $update;
		}
		$version = get_site_transient( 'msu_latest' );
		if ( false === $version ) {
			$res     = wp_remote_get( 'https://raw.githubusercontent.com/' . self::REPO . '/main/egowebdesign-safe-updates.php', [ 'timeout' => 10 ] );
			$body    = is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ? '' : wp_remote_retrieve_body( $res );
			$version = preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $body, $m ) ? $m[1] : '';
			set_site_transient( 'msu_latest', $version, HOUR_IN_SECONDS );
		}
		if ( '' === $version ) {
			return $update;
		}
		return [
			'slug'    => dirname( plugin_basename( __FILE__ ) ),
			'version' => $version,
			'url'     => 'https://github.com/' . self::REPO,
			'package' => 'https://github.com/' . self::REPO . "/archive/refs/tags/v$version.zip",
		];
	}

	/** GitHub's zip unpacks to "egowebdesign-safe-updates-2.1.0/"; rename it so the plugin keeps its folder. */
	public static function fix_own_folder( $source, $remote_source, $upgrader, $extra ) {
		if ( is_wp_error( $source ) || ( $extra['plugin'] ?? '' ) !== plugin_basename( __FILE__ ) ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . dirname( plugin_basename( __FILE__ ) ) . '/';
		if ( trailingslashit( $source ) === $wanted ) {
			return $source;
		}
		return self::fs()->move( $source, $wanted ) ? $wanted : new WP_Error( 'msu_folder', 'Could not rename the update folder.' );
	}

	/* ------------------------------------------------------------------ Deciding which updates run */

	private static function should_update( $type, $update, $item ) {
		if ( ! self::$in_run ) {
			// Outside the updater (e.g. the Plugins screen asking), show plugins and themes as managed automatically.
			return 'core' === $type ? $update : true;
		}

		if ( 'core' === $type ) {
			if ( empty( $item->current ) ) {
				return $update;
			}
			$key     = 'core';
			$name    = 'WordPress';
			$current = get_bloginfo( 'version' );
			$new     = $item->current;
		} else {
			$key     = $type . ':' . $item->{$type};
			$name    = self::item_name( $type, $item->{$type} );
			$current = self::installed_version( $type, $item->{$type} );
			$new     = $item->new_version ?? '';
		}
		if ( '' === $new || ! $current ) {
			return $update;
		}

		$only = get_transient( 'msu_test_only' );
		if ( $only && $only !== $key ) {
			return false;
		}

		$blocked = get_option( 'msu_blocked', [] );
		if ( isset( $blocked[ $key ] ) && $blocked[ $key ] === $new ) {
			return false; // This version broke the site before; wait for the next one or a manual update.
		}

		// This plugin's own releases skip the hold (they still wait for a fresh backup).
		$major = 'plugin:' . plugin_basename( __FILE__ ) !== $key && self::is_major( $current, $new );
		if ( $major ) {
			$seen = get_option( 'msu_seen', [] );
			if ( ! isset( $seen[ $key ] ) || $seen[ $key ]['version'] !== $new ) {
				$seen[ $key ] = [ 'version' => $new, 'time' => time(), 'name' => $name, 'from' => $current ];
				update_option( 'msu_seen', $seen, false );
				self::log( "$name $new is a big release: waiting " . self::HOLD_DAYS . ' days before installing.' );
			}
			if ( time() - $seen[ $key ]['time'] < self::HOLD_DAYS * DAY_IN_SECONDS ) {
				return false;
			}
		}

		if ( ! self::backup_is_fresh() ) {
			self::request_backup( "$name $new" );
			return false;
		}

		// Core small releases follow the site's own WordPress setting; big ones install once the hold is over.
		return 'core' === $type && ! $major ? $update : true;
	}

	private static function is_major( $current, $new ) {
		$a = array_slice( explode( '.', $current ), 0, 2 );
		$b = array_slice( explode( '.', $new ), 0, 2 );
		return $a !== $b;
	}

	private static function installed_version( $type, $id ) {
		if ( 'theme' === $type ) {
			$theme = wp_get_theme( $id );
			return $theme->exists() ? $theme->get( 'Version' ) : '';
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		return $plugins[ $id ]['Version'] ?? '';
	}

	private static function item_name( $type, $id ) {
		if ( 'theme' === $type ) {
			return wp_get_theme( $id )->get( 'Name' ) ?: $id;
		}
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = get_plugins();
		return $plugins[ $id ]['Name'] ?? $id;
	}

	/* ------------------------------------------------------------------ Backups (UpdraftPlus) */

	/** Last complete UpdraftPlus backup: [time, ok]. */
	public static function last_backup() {
		$b = get_option( 'updraft_last_backup' );
		if ( ! is_array( $b ) || empty( $b['backup_time'] ) ) {
			return [ 'time' => 0, 'ok' => false ];
		}
		$files = $b['backup_array'] ?? [];
		$ok    = ! empty( $b['success'] ) && isset( $files['db'], $files['plugins'] );
		return [ 'time' => (int) $b['backup_time'], 'ok' => $ok ];
	}

	private static function backup_is_fresh() {
		$b = self::last_backup();
		return $b['ok'] && ( time() - $b['time'] ) < self::BACKUP_MAX_AGE;
	}

	/** Starts a full backup in its own background request, at most once every 6 hours. */
	private static function request_backup( $reason ) {
		if ( ! has_action( 'updraft_backupnow_backup_all' ) ) {
			self::log( "Update waiting ($reason): UpdraftPlus is not active, so no backup can be made." );
			self::alert_once( 'no-updraft', 'Updates are on hold: UpdraftPlus is not active', "Safe updates only install updates after a fresh backup, and UpdraftPlus (which makes the backups) is not active.\n\nReactivate UpdraftPlus to let updates resume." );
			return;
		}
		$last = (int) get_option( 'msu_backup_requested', 0 );
		if ( time() - $last < 6 * HOUR_IN_SECONDS ) {
			if ( $last && time() - $last > 5 * HOUR_IN_SECONDS && ! self::backup_is_fresh() ) {
				self::alert_once( 'backup-failing', 'Updates are on hold: the backup did not finish', "A backup was started before installing updates, but UpdraftPlus has not recorded a complete backup (database + plugins) since.\n\nOpen Settings > UpdraftPlus Backups to see what went wrong." );
			}
			return;
		}
		update_option( 'msu_backup_requested', time(), false );
		wp_schedule_single_event( time() + 30, 'msu_run_backup' );
		self::log( "Backup started before installing $reason. The update will run on WordPress's next update round (about 12 h)." );
	}

	/* ------------------------------------------------------------------ Copies of the previous version */

	private static function copies_dir() {
		$dir = WP_CONTENT_DIR . '/egowebdesign-safe-updates';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
			file_put_contents( $dir . '/index.php', "<?php // Silence.\n" );
		}
		return $dir;
	}

	private static function fs() {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		return $wp_filesystem;
	}

	/** Runs just before WordPress replaces a plugin or theme: keeps a copy of the version being replaced. */
	public static function save_copy( $response, $extra ) {
		if ( is_wp_error( $response ) || ( $extra['action'] ?? '' ) === 'install' ) {
			return $response;
		}
		if ( ! empty( $extra['plugin'] ) && '.' !== dirname( $extra['plugin'] ) ) {
			$type = 'plugin';
			$id   = $extra['plugin'];
			$src  = WP_PLUGIN_DIR . '/' . dirname( $id );
		} elseif ( ! empty( $extra['theme'] ) ) {
			$type = 'theme';
			$id   = $extra['theme'];
			$src  = get_theme_root( $id ) . '/' . $id;
		} else {
			return $response;
		}

		$version = self::installed_version( $type, $id );
		$fs      = self::fs();
		$dest    = self::copies_dir() . '/' . $type . '-' . sanitize_file_name( 'plugin' === $type ? dirname( $id ) : $id );
		if ( ! $fs || ! $version || ! is_dir( $src ) ) {
			return $response;
		}

		if ( $fs->exists( $dest ) ) {
			$fs->delete( $dest, true );
		}
		$fs->mkdir( $dest );
		$copied = copy_dir( $src, $dest );

		$copies = get_option( 'msu_copies', [] );
		if ( is_wp_error( $copied ) ) {
			unset( $copies[ "$type:$id" ] );
			self::log( 'Could not save a copy of ' . self::item_name( $type, $id ) . " $version: " . $copied->get_error_message() );
		} else {
			$copies[ "$type:$id" ] = [ 'version' => $version, 'path' => $dest, 'src' => $src, 'time' => time(), 'name' => self::item_name( $type, $id ) ];
		}
		update_option( 'msu_copies', $copies, false );

		return $response;
	}

	/** Puts the saved copy back in place of the current plugin/theme folder. */
	public static function restore_copy( $key ) {
		$copies = get_option( 'msu_copies', [] );
		if ( empty( $copies[ $key ] ) || ! is_dir( $copies[ $key ]['path'] ) ) {
			return new WP_Error( 'msu_no_copy', 'No saved copy.' );
		}
		$c       = $copies[ $key ];
		$fs      = self::fs();
		$aside   = $c['src'] . '-msu-replaced';

		if ( $fs->exists( $aside ) ) {
			$fs->delete( $aside, true );
		}
		if ( $fs->exists( $c['src'] ) && ! $fs->move( $c['src'], $aside ) ) {
			return new WP_Error( 'msu_move', 'Could not move the current version aside.' );
		}
		$fs->mkdir( $c['src'] );
		$copied = copy_dir( $c['path'], $c['src'] );
		if ( is_wp_error( $copied ) ) {
			$fs->delete( $c['src'], true );
			$fs->move( $aside, $c['src'] );
			return $copied;
		}
		$fs->delete( $aside, true );

		if ( function_exists( 'wp_opcache_invalidate_directory' ) ) {
			wp_opcache_invalidate_directory( $c['src'] );
		}
		if ( ! function_exists( 'wp_clean_plugins_cache' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		wp_clean_plugins_cache( false );
		wp_clean_themes_cache( false );
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}

		self::log( "Put back {$c['name']} {$c['version']}." );
		return true;
	}

	/* ------------------------------------------------------------------ After updates */

	public static function after_auto_updates( $results ) {
		$updated = [];
		foreach ( [ 'plugin', 'theme', 'core' ] as $type ) {
			foreach ( $results[ $type ] ?? [] as $r ) {
				if ( is_wp_error( $r->result ) || ! $r->result ) {
					$msg = is_wp_error( $r->result ) ? $r->result->get_error_message() : 'failed';
					self::log( "Update of {$r->name} did not install: $msg" );
					continue;
				}
				$id        = 'core' === $type ? 'core' : $r->item->{$type};
				$updated[] = [
					'type' => $type,
					'key'  => 'core' === $type ? 'core' : "$type:$id",
					'name' => $r->name,
					'from' => $r->item->current_version ?? '',
					'to'   => 'core' === $type ? ( $r->item->current ?? '' ) : ( $r->item->new_version ?? '' ),
				];
			}
		}
		if ( ! $updated ) {
			return;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 600 ); // Checks, restores and re-checks can take a few minutes.
		}

		$list = implode( "\n", array_map( function ( $u ) { return "- {$u['name']}" . ( $u['from'] ? " ({$u['from']} → {$u['to']})" : '' ); }, $updated ) );
		self::log( 'Installed: ' . str_replace( "\n", ', ', str_replace( '- ', '', $list ) ) );

		$check = self::run_checks();
		if ( $check['ok'] ) {
			self::mail( 'Updates installed, site checked OK', "These updates were installed automatically after a fresh backup:\n\n$list\n\nAll page checks passed." );
			return;
		}

		// Something broke: put back the updated plugins/themes one at a time, newest first, until the checks pass.
		$copies   = get_option( 'msu_copies', [] );
		$restored = [];
		$culprit  = null;
		foreach ( array_reverse( $updated ) as $u ) {
			if ( 'core' === $u['type'] || empty( $copies[ $u['key'] ] ) || $copies[ $u['key'] ]['version'] !== $u['from'] ) {
				continue;
			}
			$res = self::restore_copy( $u['key'] );
			if ( is_wp_error( $res ) ) {
				self::log( "Could not put back {$u['name']}: " . $res->get_error_message() );
				continue;
			}
			$restored[] = $u;
			sleep( 3 );
			$check = self::run_checks();
			if ( $check['ok'] ) {
				$culprit = $u;
				break;
			}
		}

		if ( $culprit ) {
			$blocked                     = get_option( 'msu_blocked', [] );
			$blocked[ $culprit['key'] ] = $culprit['to'];
			update_option( 'msu_blocked', $blocked, false );
			$back = implode( "\n", array_map( function ( $u ) { return "- {$u['name']} back to {$u['from']}"; }, $restored ) );
			self::mail(
				"Update undone: {$culprit['name']} {$culprit['to']} broke the site",
				"These updates were installed automatically:\n\n$list\n\nAfter the update, the page checks failed. The previous version was put back and the site passes the checks again:\n\n$back\n\n{$culprit['name']} {$culprit['to']} will not be installed again automatically. The next version will be tried normally, or you can update by hand once the problem is understood.\n\nWhat failed before the fix:\n" . self::format_failures( self::$last_failures )
			);
		} else {
			$b = self::last_backup();
			self::mail(
				'URGENT: the site has a problem after updates',
				"These updates were installed automatically:\n\n$list\n\nThe page checks fail, and putting back the previous versions did not fix it" . ( $restored ? '' : ' (no saved copy could be used)' ) . ".\n\nWhat fails:\n" . self::format_failures( $check['failures'] ) .
				"\n\nTo restore the whole site, use the UpdraftPlus backup from " . ( $b['time'] ? wp_date( 'Y-m-d H:i', $b['time'] ) : '(none found)' ) . ' (Settings > UpdraftPlus Backups > Restore).'
			);
		}
	}

	/** Manual updates from wp-admin: check the site a minute later and warn if something broke. */
	public static function after_manual_update( $upgrader, $extra ) {
		if ( self::$in_run || ( $extra['action'] ?? '' ) !== 'update' ) {
			return;
		}
		if ( ! wp_next_scheduled( 'msu_check', [ 'manual' ] ) ) {
			wp_schedule_single_event( time() + 60, 'msu_check', [ 'manual' ] );
		}
	}

	/* ------------------------------------------------------------------ Page checks */

	private static $last_failures = [];

	/** Loads every key page and checks it rendered properly. Failed pages are retried once to rule out a blip. */
	public static function run_checks() {
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 300 );
		}
		$failures = [];
		foreach ( self::pages() as $page ) {
			$error = self::check_page( $page );
			if ( $error ) {
				sleep( 5 );
				$error = self::check_page( $page );
			}
			if ( $error ) {
				$failures[ $page[0] ] = $error;
			}
		}

		$css = self::check_styles();
		if ( $css ) {
			$failures['Elementor styles'] = $css;
		}

		$previous = get_option( 'msu_last_check', [] );
		$result   = [ 'time' => time(), 'ok' => ! $failures, 'failures' => $failures ];
		update_option( 'msu_last_check', $result, false );
		if ( $failures ) {
			self::$last_failures = $failures;
			self::log( 'Page checks FAILED: ' . implode( '; ', array_map( function ( $k, $v ) { return "$k: $v"; }, array_keys( $failures ), $failures ) ) );
		} elseif ( isset( $previous['ok'] ) && ! $previous['ok'] ) {
			self::log( 'Page checks pass again.' );
		}
		return $result;
	}

	private static function fetch( $url ) {
		return wp_remote_get( add_query_arg( 'msu', time(), $url ), [
			'timeout'     => 30,
			'redirection' => 3,
			'user-agent'  => 'EgoWebDesign Safe Updates',
			'headers'     => [ 'Cache-Control' => 'no-cache' ],
		] );
	}

	private static function check_page( $page ) {
		list( , $path, $markers ) = $page;
		$res = self::fetch( home_url( $path ) );
		if ( is_wp_error( $res ) ) {
			return 'not reachable (' . $res->get_error_message() . ')';
		}
		$code = wp_remote_retrieve_response_code( $res );
		$body = wp_remote_retrieve_body( $res );
		if ( 200 !== $code ) {
			return "HTTP $code";
		}
		foreach ( [ 'critical error', 'erreur critique', 'Fatal error', 'Parse error' ] as $bad ) {
			if ( false !== stripos( $body, $bad ) ) {
				return "shows \"$bad\"";
			}
		}
		$required = array_merge( self::common_markers(), array_fill_keys( $markers, '' ) );
		foreach ( $required as $marker => $label ) {
			if ( false === strpos( $body, $marker ) ) {
				return $label ?: "missing $marker";
			}
		}
		return '';
	}

	/** Elementor writes each page's styles to a CSS file; if that file is missing, pages look unstyled. */
	private static function check_styles() {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return '';
		}
		$res = self::fetch( home_url( '/' ) );
		if ( is_wp_error( $res ) || ! preg_match( '#https?://[^"\']+/uploads/elementor/css/post-\d+\.css[^"\']*#', wp_remote_retrieve_body( $res ), $m ) ) {
			return ''; // Page problems are reported by check_page.
		}
		$css = wp_remote_get( html_entity_decode( $m[0] ), [ 'timeout' => 20 ] );
		$code = is_wp_error( $css ) ? 0 : wp_remote_retrieve_response_code( $css );
		return 200 === $code ? '' : "style file not loading (HTTP $code)";
	}

	/** Twice-daily check (and one minute after a manual update): emails only when the state changes. */
	public static function scheduled_check( $why = '' ) {
		$before = get_option( 'msu_last_check', [] );
		$check  = self::run_checks();
		$was_ok = $before['ok'] ?? true;
		if ( ! $check['ok'] && ( $was_ok || 'manual' === $why ) ) {
			$copies = get_option( 'msu_copies', [] );
			$recent = array_filter( $copies, function ( $c ) { return time() - $c['time'] < DAY_IN_SECONDS; } );
			$hint   = $recent ? "\n\nRecently updated (a copy of the previous version is saved; you can put it back from the Dashboard > Safe updates box):\n" . implode( "\n", array_map( function ( $c ) { return "- {$c['name']} (previous version {$c['version']})"; }, $recent ) ) : '';
			self::mail( 'Problem detected on the website', "The page checks found a problem:\n\n" . self::format_failures( $check['failures'] ) . $hint );
		} elseif ( $check['ok'] && ! $was_ok ) {
			self::mail( 'Website OK again', 'The page checks pass again.' );
		}
	}

	private static function format_failures( $failures ) {
		return implode( "\n", array_map( function ( $k, $v ) { return "- $k: $v"; }, array_keys( $failures ), $failures ) );
	}

	/* ------------------------------------------------------------------ Status page (UptimeRobot) and update report */

	public static function register_status_route() {
		$status = [
			'methods'             => 'GET',
			'permission_callback' => '__return_true',
			'callback'            => function () {
				$c     = get_option( 'msu_last_check', [] );
				$stale = empty( $c['time'] ) || time() - $c['time'] > self::STALE_CHECK;
				$ok    = ! empty( $c['ok'] ) && ! $stale;
				$data  = [
					'status'  => $ok ? 'ok' : ( $stale ? 'checks-not-running' : 'problem' ),
					'checked' => empty( $c['time'] ) ? null : gmdate( 'c', $c['time'] ),
					'failing' => array_keys( $c['failures'] ?? [] ),
				];
				$res = new WP_REST_Response( $data, $ok ? 200 : 503 );
				$res->header( 'Cache-Control', 'no-store' );
				return $res;
			},
		];
		register_rest_route( 'egowebdesign/v1', '/status', $status );

		register_rest_route( 'egowebdesign/v1', '/report', [
			'methods'             => 'GET',
			'permission_callback' => '__return_true', // Protected by the secret key below instead of a login.
			'callback'            => [ __CLASS__, 'report' ],
		] );
	}

	public static function report_url() {
		return add_query_arg( 'key', get_option( 'msu_report_key', '' ), rest_url( 'egowebdesign/v1/report' ) );
	}

	/**
	 * Read-only update report for the monitoring dashboard: what WordPress itself shows in Dashboard > Updates,
	 * including premium plugins whose licence is active. Needs the secret key; changes nothing.
	 */
	public static function report( WP_REST_Request $request ) {
		$key = (string) get_option( 'msu_report_key', '' );
		if ( '' === $key || ! hash_equals( $key, (string) $request->get_param( 'key' ) ) ) {
			return new WP_Error( 'msu_forbidden', 'Not allowed.', [ 'status' => 403 ] );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';

		$fmt = function ( $t ) { return $t ? gmdate( 'c', (int) $t ) : null; };

		// WordPress core.
		$wp_version = get_bloginfo( 'version' );
		$core_t     = get_site_transient( 'update_core' );
		$offer      = function_exists( 'get_preferred_from_update_core' ) ? get_preferred_from_update_core() : false;
		$latest     = $wp_version;
		foreach ( (array) ( $core_t->updates ?? [] ) as $u ) {
			if ( isset( $u->response, $u->current ) && 'upgrade' === $u->response && version_compare( $u->current, $latest, '>' ) ) {
				$latest = $u->current;
			}
		}
		if ( $offer && isset( $offer->current ) && version_compare( $offer->current, $latest, '>' ) ) {
			$latest = $offer->current;
		}

		// Plugins.
		$plugin_t  = get_site_transient( 'update_plugins' );
		$auto_list = (array) get_site_option( 'auto_update_plugins', [] );
		$plugins   = [];
		foreach ( get_plugins() as $file => $p ) {
			$new       = $plugin_t->response[ $file ]->new_version ?? '';
			$plugins[] = [
				'file'      => $file,
				'slug'      => '.' === dirname( $file ) ? basename( $file, '.php' ) : dirname( $file ),
				'name'      => wp_strip_all_tags( $p['Name'] ),
				'version'   => $p['Version'],
				'latest'    => $new ?: $p['Version'],
				'update'    => '' !== $new && version_compare( $new, $p['Version'], '>' ),
				'active'    => is_plugin_active( $file ),
				'auto'      => in_array( $file, $auto_list, true ),
				'checkable' => isset( $plugin_t->response[ $file ] ) || isset( $plugin_t->no_update[ $file ] ),
			];
		}
		$mu = [];
		foreach ( get_mu_plugins() as $file => $p ) {
			$mu[] = [ 'file' => $file, 'name' => wp_strip_all_tags( $p['Name'] ), 'version' => $p['Version'] ];
		}

		// Themes.
		$theme_t = get_site_transient( 'update_themes' );
		$active  = wp_get_theme();
		$themes  = [];
		foreach ( wp_get_themes() as $slug => $t ) {
			$new      = $theme_t->response[ $slug ]['new_version'] ?? '';
			$themes[] = [
				'slug'    => $slug,
				'name'    => $t->get( 'Name' ),
				'version' => $t->get( 'Version' ),
				'latest'  => $new ?: $t->get( 'Version' ),
				'update'  => '' !== $new && version_compare( $new, $t->get( 'Version' ), '>' ),
				'active'  => $slug === $active->get_stylesheet(),
				'parent'  => $slug === $active->get_template() && $active->get_stylesheet() !== $active->get_template(),
			];
		}

		$c    = get_option( 'msu_last_check', [] );
		$b    = self::last_backup();
		$seen = get_option( 'msu_seen', [] );
		$held = [];
		foreach ( $seen as $k => $sv ) {
			if ( time() - $sv['time'] < self::HOLD_DAYS * DAY_IN_SECONDS ) {
				$held[] = [ 'key' => $k, 'name' => $sv['name'], 'version' => $sv['version'], 'installs_after' => $fmt( $sv['time'] + self::HOLD_DAYS * DAY_IN_SECONDS ) ];
			}
		}
		$blocked = [];
		foreach ( get_option( 'msu_blocked', [] ) as $k => $v ) {
			$blocked[] = [ 'key' => $k, 'version' => $v ];
		}

		$data = [
			'site'          => wp_parse_url( home_url(), PHP_URL_HOST ),
			'name'          => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'url'           => home_url( '/' ),
			'generated'     => $fmt( time() ),
			'plugin'        => self::VERSION,
			'wordpress'     => [
				'version' => $wp_version,
				'latest'  => $latest,
				'update'  => version_compare( $latest, $wp_version, '>' ),
				'checked' => $fmt( $core_t->last_checked ?? 0 ),
			],
			'php'           => PHP_VERSION,
			'theme'         => [ 'name' => $active->get( 'Name' ), 'slug' => $active->get_stylesheet(), 'version' => $active->get( 'Version' ) ],
			'themes'        => $themes,
			'plugins'       => $plugins,
			'mu_plugins'    => $mu,
			'updates_checked' => [ 'plugins' => $fmt( $plugin_t->last_checked ?? 0 ), 'themes' => $fmt( $theme_t->last_checked ?? 0 ) ],
			'safe_updates'  => [
				'page_checks' => empty( $c['time'] ) ? 'not-run' : ( $c['ok'] ? 'ok' : 'problem' ),
				'checked'     => $fmt( $c['time'] ?? 0 ),
				'failing'     => array_keys( $c['failures'] ?? [] ),
				'last_backup' => $fmt( $b['time'] ),
				'backup_ok'   => $b['ok'],
				'held'        => $held,
				'blocked'     => $blocked,
			],
		];
		$res = new WP_REST_Response( $data, 200 );
		$res->header( 'Cache-Control', 'no-store' );
		$res->header( 'X-Robots-Tag', 'noindex' );
		return $res;
	}

	/* ------------------------------------------------------------------ Settings screen */

	public static function settings_menu() {
		add_options_page( 'Safe updates', 'Safe updates', 'manage_options', 'msu-settings', [ __CLASS__, 'render_settings' ] );
	}

	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s   = self::settings();
		$url = self::report_url();
		echo '<div class="wrap"><h1>Safe updates</h1>';
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
		}

		echo '<h2>Update report link</h2><p>Give this private link to the monitoring dashboard. Anyone who has it can see this site\'s WordPress, theme and plugin versions (nothing else, and it can\'t change anything).</p>';
		echo '<p><input type="text" readonly class="large-text code" onclick="this.select()" value="' . esc_attr( $url ) . '"></p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'The old link will stop working. Continue?\')">';
		wp_nonce_field( 'msu_new_key' );
		echo '<input type="hidden" name="action" value="msu_new_key"><button class="button">Make a new link</button></form>';
		echo '<p>Status link for UptimeRobot: <code>' . esc_html( rest_url( 'egowebdesign/v1/status' ) ) . '</code></p>';

		echo '<hr><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'msu_save_settings' );
		echo '<input type="hidden" name="action" value="msu_save_settings"><table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="msu-email">Alert email</label></th><td><input id="msu-email" name="email" type="email" class="regular-text" value="' . esc_attr( $s['email'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="msu-label">Name in email subjects</label></th><td><input id="msu-label" name="label" type="text" class="regular-text" value="' . esc_attr( $s['label'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="msu-pages">Pages to check</label></th><td><textarea id="msu-pages" name="pages" rows="10" class="large-text code">' . esc_textarea( $s['pages'] ) . '</textarea><p class="description">One page per line: <code>Name | /path/ | text the page must contain, more text</code>. The last part is optional.</p></td></tr>';
		echo '<tr><th scope="row"><label for="msu-markers">Every page must contain</label></th><td><textarea id="msu-markers" name="markers" rows="5" class="large-text code">' . esc_textarea( $s['markers'] ) . '</textarea><p class="description">One per line: <code>text in the page\'s code | message when it\'s missing</code>. For an Elementor site with a theme-builder header and footer, add <code>elementor-location-header | header missing</code> and <code>elementor-location-footer | footer missing</code>.</p></td></tr>';
		echo '</table>';
		submit_button( 'Save' );
		echo '</form></div>';
	}

	public static function admin_save_settings() {
		check_admin_referer( 'msu_save_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$in = wp_unslash( $_POST );
		update_option( 'msu_settings', [
			'email'   => sanitize_email( $in['email'] ?? '' ) ?: self::DEFAULT_EMAIL,
			'label'   => sanitize_text_field( $in['label'] ?? '' ),
			'pages'   => trim( str_replace( "\r", '', (string) ( $in['pages'] ?? '' ) ) ), // Markers are bits of HTML, kept as typed.
			'markers' => trim( str_replace( "\r", '', (string) ( $in['markers'] ?? '' ) ) ), // Markers are bits of HTML, kept as typed.
		], false );
		wp_safe_redirect( admin_url( 'options-general.php?page=msu-settings&saved=1' ) );
		exit;
	}

	public static function admin_new_key() {
		check_admin_referer( 'msu_new_key' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		update_option( 'msu_report_key', wp_generate_password( 32, false ), false );
		wp_safe_redirect( admin_url( 'options-general.php?page=msu-settings&saved=1' ) );
		exit;
	}

	/* ------------------------------------------------------------------ Dashboard box */

	public static function dashboard_widget() {
		if ( current_user_can( 'update_plugins' ) ) {
			wp_add_dashboard_widget( 'msu_widget', 'Safe updates', [ __CLASS__, 'render_widget' ] );
		}
	}

	public static function render_widget() {
		$c      = get_option( 'msu_last_check', [] );
		$b      = self::last_backup();
		$seen   = get_option( 'msu_seen', [] );
		$copies = get_option( 'msu_copies', [] );
		$log    = array_slice( array_reverse( get_option( 'msu_log', [] ) ), 0, 8 );
		$fmt    = function ( $t ) { return $t ? wp_date( 'Y-m-d H:i', $t ) : '—'; };

		echo '<p><strong>Page checks:</strong> ';
		if ( empty( $c['time'] ) ) {
			echo 'not run yet';
		} else {
			echo $c['ok'] ? '<span style="color:#008a20">✔ all pass</span>' : '<span style="color:#d63638">✖ problem</span>';
			echo ' (' . esc_html( $fmt( $c['time'] ) ) . ')';
			foreach ( $c['failures'] ?? [] as $k => $v ) {
				echo '<br>&nbsp;&nbsp;' . esc_html( "$k: $v" );
			}
		}
		echo '</p><p><strong>Last complete backup:</strong> ' . esc_html( $fmt( $b['time'] ) ) . ( $b['time'] && ! $b['ok'] ? ' (incomplete)' : '' ) . '</p>';

		$held = array_filter( $seen, function ( $s ) { return time() - $s['time'] < self::HOLD_DAYS * DAY_IN_SECONDS; } );
		if ( $held ) {
			echo '<p><strong>Big releases waiting:</strong>';
			foreach ( $held as $s ) {
				echo '<br>&nbsp;&nbsp;' . esc_html( "{$s['name']} {$s['version']} — from " . $fmt( $s['time'] + self::HOLD_DAYS * DAY_IN_SECONDS ) );
			}
			echo '</p>';
		}

		if ( current_user_can( 'manage_options' ) ) {
			echo '<p><a href="' . esc_url( admin_url( 'options-general.php?page=msu-settings' ) ) . '">Settings and update report link</a></p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:8px 0">';
		wp_nonce_field( 'msu_check_now' );
		echo '<input type="hidden" name="action" value="msu_check_now"><button class="button">Run page checks now</button></form>';

		if ( $copies ) {
			echo '<p><strong>Saved previous versions:</strong></p>';
			foreach ( $copies as $key => $copy ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:0 0 6px" onsubmit="return confirm(\'Put back this version?\')">';
				wp_nonce_field( 'msu_restore' );
				echo '<input type="hidden" name="action" value="msu_restore"><input type="hidden" name="key" value="' . esc_attr( $key ) . '">';
				echo esc_html( "{$copy['name']} {$copy['version']} (" . $fmt( $copy['time'] ) . ') ' ) . '<button class="button button-small">Put back</button></form>';
			}
		}

		if ( $log ) {
			echo '<p><strong>Recent activity:</strong></p><ul style="margin-top:0">';
			foreach ( $log as $l ) {
				echo '<li>' . esc_html( $fmt( $l[0] ) . ' — ' . $l[1] ) . '</li>';
			}
			echo '</ul>';
		}
	}

	public static function admin_check_now() {
		check_admin_referer( 'msu_check_now' );
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'Not allowed.' );
		}
		self::run_checks();
		wp_safe_redirect( admin_url( '#msu_widget' ) );
		exit;
	}

	public static function admin_restore() {
		check_admin_referer( 'msu_restore' );
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'Not allowed.' );
		}
		$res = self::restore_copy( sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) ) );
		if ( is_wp_error( $res ) ) {
			wp_die( esc_html( $res->get_error_message() ) );
		}
		self::run_checks();
		wp_safe_redirect( admin_url( '#msu_widget' ) );
		exit;
	}

	/* ------------------------------------------------------------------ Log and email */

	public static function log( $message ) {
		$log   = get_option( 'msu_log', [] );
		$log[] = [ time(), $message ];
		update_option( 'msu_log', array_slice( $log, -50 ), false );
	}

	private static function mail( $subject, $body ) {
		$body .= "\n\n— Safe updates, " . home_url( '/' ) . "\nDashboard: " . admin_url();
		$s = self::settings();
		wp_mail( $s['email'], '[' . ( $s['label'] ?: wp_parse_url( home_url(), PHP_URL_HOST ) ) . '] ' . $subject, $body );
	}

	/** Same alert at most once a day. */
	private static function alert_once( $id, $subject, $body ) {
		$key = 'msu_alert_' . $id;
		if ( ! get_transient( $key ) ) {
			set_transient( $key, 1, DAY_IN_SECONDS );
			self::log( $subject );
			self::mail( $subject, $body );
		}
	}
}

add_action( 'plugins_loaded', [ 'EgoWebDesign_Safe_Updates', 'init' ], 0 );
