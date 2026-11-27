<?php
/**
 * Plugin Name: Safe updates
 * Description: Runs WordPress, plugin and theme updates automatically, right after saving a snapshot of the database. Holds big releases for 7 days, checks the key pages after each update, and puts back the previous version of a plugin or theme that breaks the site (the database snapshot can be put back from the Dashboard). Watches for signs of intrusion: new administrators, changed core files, unexpected PHP files. Also publishes a private update report (WordPress, theme and plugin versions) for the A2 Studio monitoring dashboard. Updates itself from GitHub. Settings: Settings > Safe updates.
 * Version: 2.8.0
 * Author: A2 Studio
 * Requires PHP: 7.4
 * Update URI: https://github.com/choikib/egowebdesign-safe-updates
 */

defined( 'ABSPATH' ) || exit;

final class EgoWebDesign_Safe_Updates {

	const VERSION        = '2.8.0';
	const REPO           = 'choikib/egowebdesign-safe-updates'; // New versions = tags "vX.Y.Z" on this public GitHub repo.
	const DEFAULT_EMAIL  = 'veronneau.mathieu@gmail.com';
	const HOLD_DAYS      = 7;                     // Big releases (x.Y changes) wait this long before installing.
	const SNAPSHOT_MAX_AGE = HOUR_IN_SECONDS;     // Updates need a database snapshot at most this old (one is taken on the spot).
	const SNAPSHOTS_KEPT = 3;
	const STALE_CHECK    = 36 * HOUR_IN_SECONDS;  // The status page reports a problem if checks stop running.
	const REGISTRY       = 'https://egowebdesign.ca/msu-watch/register.php'; // Where sites sign up for monitoring (Settings > Safe updates can turn it off).

	/** True while WordPress's automatic updater is running (not when an admin looks at the Plugins screen). */
	private static $in_run = false;

	/** WordPress version when the automatic updater started (to tell a big core release from a small one afterwards). */
	private static $core_before = '';

	/* ------------------------------------------------------------------ Settings */

	/** Settings with their defaults. Stored in one option, edited at Settings > Safe updates. */
	private static function settings() {
		$s = get_option( 'msu_settings', [] );
		return wp_parse_args( is_array( $s ) ? $s : [], [
			'email'   => self::DEFAULT_EMAIL,
			'label'   => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'pages'   => "Accueil | / |",
			'markers' => "</html> | page is cut off",
			'register' => 1,
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
		// Left over from 2.2 and before, when backups were made by UpdraftPlus.
		wp_clear_scheduled_hook( 'msu_run_backup' );
		delete_option( 'msu_backup_requested' );
		update_option( 'msu_version', self::VERSION, false );
		self::schedule_register(); // Installed or updated: sign up / refresh the details in a minute, in the background.
		if ( false === get_option( 'msu_settings' ) && ! wp_next_scheduled( 'msu_autoconfig' ) ) {
			wp_schedule_single_event( time() + 2 * MINUTE_IN_SECONDS, 'msu_autoconfig' ); // Never configured: suggest checks from the site.
		}
	}

	/* ------------------------------------------------------------------ Monitoring sign-up */

	private static function schedule_register() {
		if ( ! wp_next_scheduled( 'msu_register' ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'msu_register' );
		}
	}

	/**
	 * Signs this site up with the A2 Studio watcher, or refreshes its details there (name, report link, version).
	 * The watcher checks the report link really answers for this site; a new site is monitored once A2 Studio approves it.
	 */
	public static function register_site() {
		if ( empty( self::settings()['register'] ) ) {
			return;
		}
		$res    = wp_remote_post( self::REGISTRY, [
			'timeout' => 45,
			'headers' => [ 'Content-Type' => 'application/json' ],
			'body'    => wp_json_encode( [
				'url'        => home_url( '/' ),
				'name'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'report_url' => self::report_url(),
				'version'    => self::VERSION,
			] ),
		] );
		$body   = is_wp_error( $res ) ? [] : (array) json_decode( wp_remote_retrieve_body( $res ), true );
		$status = $body['status'] ?? ( is_wp_error( $res ) ? $res->get_error_message() : ( $body['error'] ?? 'HTTP ' . wp_remote_retrieve_response_code( $res ) ) );
		$prev   = get_option( 'msu_registry', [] );
		update_option( 'msu_registry', [ 'time' => time(), 'ok' => isset( $body['status'] ), 'status' => $status ], false );
		if ( ( $prev['status'] ?? '' ) !== $status ) {
			self::log( 'Monitoring sign-up: ' . self::registry_label( $status ) . '.' );
		}
	}

	private static function registry_label( $status ) {
		return [
			'approved' => 'monitored by A2 Studio',
			'pending'  => 'waiting for A2 Studio to approve it',
			'ignored'  => 'declined by A2 Studio, not monitored',
		][ $status ] ?? "could not sign up ($status)";
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
			array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">Settings</a>' );
			return $links;
		} );

		add_action( 'wp_maybe_auto_update', function () {
			self::$in_run      = true;
			self::$core_before = get_bloginfo( 'version' );
		}, 1 );

		foreach ( [ 'plugin', 'theme', 'core' ] as $type ) {
			add_filter( "auto_update_{$type}", function ( $update, $item ) use ( $type ) {
				return self::should_update( $type, $update, $item );
			}, 99, 2 );
		}

		add_filter( 'upgrader_pre_install', [ __CLASS__, 'save_copy' ], 10, 2 );
		add_action( 'automatic_updates_complete', [ __CLASS__, 'after_auto_updates' ] );
		add_action( 'upgrader_process_complete', [ __CLASS__, 'after_manual_update' ], 10, 2 );
		add_filter( 'auto_plugin_theme_update_email', [ __CLASS__, 'plugin_theme_update_email' ], 10, 4 );
		add_filter( 'auto_core_update_email', [ __CLASS__, 'core_update_email' ], 10, 4 );
		// Small updates that worked only go in the Monday summary: WordPress emails failures and big releases only.
		add_filter( 'auto_plugin_update_send_email', [ __CLASS__, 'send_update_email' ], 10, 2 );
		add_filter( 'auto_theme_update_send_email', [ __CLASS__, 'send_update_email' ], 10, 2 );
		add_filter( 'auto_core_update_send_email', function ( $send, $type ) {
			return $send && ( 'success' !== $type || ( self::$core_before && self::is_major( self::$core_before, get_bloginfo( 'version' ) ) ) );
		}, 10, 2 );

		add_action( 'msu_check', [ __CLASS__, 'scheduled_check' ] );
		add_action( 'msu_register', [ __CLASS__, 'register_site' ] );
		add_action( 'msu_weekly', [ __CLASS__, 'weekly_summary' ] );
		add_action( 'msu_autoconfig', [ __CLASS__, 'auto_configure' ] );
		add_action( 'admin_post_msu_suggest', [ __CLASS__, 'admin_suggest' ] );
		add_action( 'init', function () {
			if ( ! wp_next_scheduled( 'msu_check' ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'twicedaily', 'msu_check' );
			}
			if ( ! wp_next_scheduled( 'msu_weekly' ) ) {
				wp_schedule_event( ( new DateTime( 'next monday 08:00', wp_timezone() ) )->getTimestamp(), 'weekly', 'msu_weekly' );
			}
		} );

		add_action( 'rest_api_init', [ __CLASS__, 'register_status_route' ] );
		add_action( 'wp_dashboard_setup', [ __CLASS__, 'dashboard_widget' ] );
		add_action( 'admin_post_msu_check_now', [ __CLASS__, 'admin_check_now' ] );
		add_action( 'admin_post_msu_restore', [ __CLASS__, 'admin_restore' ] );
		add_action( 'admin_post_msu_unblock', [ __CLASS__, 'admin_unblock' ] );
		add_action( 'admin_post_msu_snapshot_now', [ __CLASS__, 'admin_snapshot_now' ] );
		add_action( 'admin_post_msu_restore_db', [ __CLASS__, 'admin_restore_db' ] );
		add_action( 'admin_post_msu_security_scan', [ __CLASS__, 'admin_security_scan' ] );
		add_action( 'admin_post_msu_security_review', [ __CLASS__, 'admin_security_review' ] );
		add_action( 'admin_notices', [ __CLASS__, 'admin_notice' ] );

		foreach ( [ 'set_user_role', 'add_user_role', 'grant_super_admin', 'user_register' ] as $hook ) {
			add_action( $hook, [ __CLASS__, 'admin_changed' ] );
		}
		add_action( 'upgrader_process_complete', [ __CLASS__, 'reindex_after_upgrade' ], 5, 2 );

		add_filter( 'update_plugins_github.com', [ __CLASS__, 'check_own_update' ], 10, 3 );
		add_filter( 'upgrader_source_selection', [ __CLASS__, 'fix_own_folder' ], 10, 4 );
	}

	/* ------------------------------------------------------------------ Updates of this plugin (GitHub) */

	/**
	 * Newest version on GitHub (highest "vX.Y.Z" tag), asked at most once an hour. WordPress compares it.
	 * Uses the API: raw.githubusercontent.com can keep serving an old copy for a while after a release.
	 */
	public static function check_own_update( $update, $data, $file ) {
		if ( plugin_basename( __FILE__ ) !== $file ) {
			return $update;
		}
		$version = get_site_transient( 'msu_latest' );
		if ( false === $version ) {
			$res     = wp_remote_get( 'https://api.github.com/repos/' . self::REPO . '/tags?per_page=100', [ 'timeout' => 10 ] );
			$tags    = is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ? [] : json_decode( wp_remote_retrieve_body( $res ), true );
			$version = '';
			foreach ( (array) $tags as $tag ) {
				$v = ltrim( $tag['name'] ?? '', 'v' );
				if ( preg_match( '/^\d+(\.\d+)+$/', $v ) && ( '' === $version || version_compare( $v, $version, '>' ) ) ) {
					$version = $v;
				}
			}
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
			'icons'   => [
				'svg' => plugins_url( 'assets/a2-icon.svg', __FILE__ ),
				'1x'  => plugins_url( 'assets/a2-icon-128.png', __FILE__ ),
				'2x'  => plugins_url( 'assets/a2-icon-256.png', __FILE__ ),
			],
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

		// This plugin's own releases skip the hold (they still get a database snapshot first).
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

		if ( ! self::ensure_snapshot( "$name $new" ) ) {
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

	/* ------------------------------------------------------------------ Database snapshots */

	/** True once a snapshot failed during this request, so the next update in the same run doesn't retry it. */
	private static $snapshot_failed = false;

	private static function snapshots_dir() {
		$dir = self::copies_dir() . '/db';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/index.php', "<?php // Silence.\n" );
		}
		return $dir;
	}

	/** Finished snapshots, newest first: [file, time, size]. */
	public static function snapshots() {
		$list = [];
		foreach ( glob( self::snapshots_dir() . '/db-*.sql.gz' ) ?: [] as $path ) {
			$list[] = [ 'file' => basename( $path ), 'time' => filemtime( $path ), 'size' => filesize( $path ) ];
		}
		usort( $list, function ( $a, $b ) { return $b['time'] - $a['time']; } );
		return $list;
	}

	/** Updates need a snapshot taken in the last hour; one is taken on the spot otherwise. Never more than one try per request. */
	private static function ensure_snapshot( $reason ) {
		$last = self::snapshots()[0] ?? null;
		if ( $last && time() - $last['time'] < self::SNAPSHOT_MAX_AGE ) {
			return true;
		}
		if ( self::$snapshot_failed ) {
			return false;
		}
		$res = self::take_snapshot( $reason );
		if ( is_wp_error( $res ) ) {
			self::$snapshot_failed = true;
			self::log( "Update waiting ($reason): the database snapshot failed: " . $res->get_error_message() );
			self::alert_once( 'snapshot-failing', 'Updates are on hold: the database snapshot failed', [
				'tone'   => 'amber',
				'badge'  => 'On hold',
				'title'  => 'Updates are on hold',
				'lead'   => 'Safe updates saves a copy of the database before installing updates, and that failed. Updates will be tried again on WordPress\'s next update round (about 12 h).',
				'facts'  => [ [ 'Waiting', $reason ], [ 'Why', $res->get_error_message() ] ],
				'button' => [ 'Open Safe updates', self::page_url() ],
			] );
			return false;
		}
		return true;
	}

	/**
	 * Saves the site's database tables to a gzipped SQL file, one statement per line.
	 * Read inside one transaction so the copy is consistent even if the site writes meanwhile.
	 */
	public static function take_snapshot( $reason ) {
		global $wpdb;
		if ( ! function_exists( 'gzopen' ) ) {
			return new WP_Error( 'msu_zlib', 'PHP zlib is not available.' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 600 );
		}
		$path = self::snapshots_dir() . '/db-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 12, false ) . '.sql.gz'; // Random part: not guessable on servers that ignore .htaccess.
		$gz   = gzopen( "$path.part", 'wb6' );
		if ( ! $gz ) {
			return new WP_Error( 'msu_write', 'Could not write in ' . self::snapshots_dir() . '.' );
		}
		$ok    = true;
		$write = function ( $s ) use ( $gz, &$ok ) {
			$ok = $ok && strlen( $s ) === gzwrite( $gz, $s );
		};

		$write( "-- Safe updates database snapshot, " . gmdate( 'c' ) . ", before $reason\nSET NAMES " . ( $wpdb->charset ?: 'utf8mb4' ) . ";\nSET foreign_key_checks = 0;\n" );
		$wpdb->query( 'START TRANSACTION WITH CONSISTENT SNAPSHOT' );
		$tables = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' ), ARRAY_N );
		foreach ( $tables as $t ) {
			if ( 'BASE TABLE' !== $t[1] ) {
				continue;
			}
			$table  = $t[0];
			$create = $wpdb->get_row( "SHOW CREATE TABLE `$table`", ARRAY_N );
			if ( empty( $create[1] ) ) {
				$ok = false;
				break;
			}
			$write( "DROP TABLE IF EXISTS `$table`;\n" . str_replace( [ "\r", "\n" ], ' ', $create[1] ) . ";\n" );
			for ( $offset = 0; $ok; $offset += 500 ) {
				$rows = $wpdb->get_results( "SELECT * FROM `$table` LIMIT $offset, 500", ARRAY_N );
				if ( $wpdb->last_error ) {
					$ok = false;
				}
				if ( ! $rows ) {
					break;
				}
				$values = [];
				foreach ( $rows as $row ) {
					$values[] = '(' . implode( ',', array_map( function ( $v ) use ( $wpdb ) {
						return null === $v ? 'NULL' : "'" . $wpdb->remove_placeholder_escape( $wpdb->_real_escape( $v ) ) . "'"; // Escapes line breaks too, so each statement stays on one line.
					}, $row ) ) . ')';
				}
				$write( "INSERT INTO `$table` VALUES " . implode( ',', $values ) . ";\n" );
			}
		}
		$wpdb->query( 'COMMIT' );
		$write( "SET foreign_key_checks = 1;\n" );
		gzclose( $gz );

		if ( ! $ok || ! rename( "$path.part", $path ) ) {
			@unlink( "$path.part" );
			return new WP_Error( 'msu_snapshot', 'The database could not be read or the file could not be written (disk full?).' );
		}
		foreach ( array_slice( self::snapshots(), self::SNAPSHOTS_KEPT ) as $old ) {
			unlink( self::snapshots_dir() . '/' . $old['file'] );
		}
		delete_transient( 'msu_alert_snapshot-failing' );
		self::log( 'Database snapshot saved (' . size_format( filesize( $path ) ) . ") before $reason." );
		return true;
	}

	/** Puts the whole database back as it was in a snapshot. This plugin's own state (log, saved copies, blocked versions) stays as it is now. */
	public static function restore_snapshot( $file ) {
		global $wpdb;
		$file = basename( (string) $file );
		$path = self::snapshots_dir() . '/' . $file;
		if ( ! preg_match( '/^db-[\w-]+\.sql\.gz$/', $file ) || ! is_file( $path ) ) {
			return new WP_Error( 'msu_no_snapshot', 'No such snapshot.' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 900 );
		}
		$keep = $wpdb->get_results( "SELECT option_name, option_value, autoload FROM {$wpdb->options} WHERE option_name LIKE 'msu\\_%'", ARRAY_A );

		// Straight to MySQL: wpdb::query() would refuse some valid binary or serialized values.
		$gz    = gzopen( $path, 'rb' );
		$line  = '';
		$error = '';
		while ( $gz && ! gzeof( $gz ) ) {
			$line .= (string) gzgets( $gz, 1048576 );
			if ( "\n" !== substr( $line, -1 ) && ! gzeof( $gz ) ) {
				continue; // Long INSERT: keep reading until the end of the line.
			}
			$sql  = trim( $line );
			$line = '';
			if ( '' !== $sql && 0 !== strpos( $sql, '--' ) && ! mysqli_query( $wpdb->dbh, $sql ) && ! $error ) {
				$error = mysqli_error( $wpdb->dbh );
			}
		}
		if ( $gz ) {
			gzclose( $gz );
		}
		mysqli_query( $wpdb->dbh, 'SET foreign_key_checks = 1' );
		$wpdb->set_charset( $wpdb->dbh );

		foreach ( $keep as $row ) {
			$wpdb->replace( $wpdb->options, $row );
		}
		wp_cache_flush();
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		if ( ! $gz || $error ) {
			self::log( "Database put back from $file WITH ERRORS: " . ( $error ?: 'could not open the file' ) );
			return new WP_Error( 'msu_restore_db', 'The database was only partly put back: ' . ( $error ?: 'could not open the file' ) );
		}
		self::log( 'Database put back as it was on ' . wp_date( 'Y-m-d H:i', filemtime( $path ) ) . '.' );
		return true;
	}

	/** UpdraftPlus is no longer needed; if it is there, its last backup is still shown. [time, ok], or null without UpdraftPlus. */
	public static function updraft_backup() {
		$b = get_option( 'updraft_last_backup' );
		if ( ! is_array( $b ) || empty( $b['backup_time'] ) ) {
			return null;
		}
		$files = $b['backup_array'] ?? [];
		return [ 'time' => (int) $b['backup_time'], 'ok' => ! empty( $b['success'] ) && isset( $files['db'], $files['plugins'] ) ];
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
		if ( ! self::$in_run ) {
			self::ensure_snapshot( self::item_name( $type, $id ) . ' update (by hand)' ); // Automatic updates took theirs already; this never blocks a manual one.
		}
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
		self::reindex_folder( $c['src'] );

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
			self::remember_for_week( $updated ); // Good news waits for the weekly summary.
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
			self::remember_for_week( array_udiff( $updated, $restored, function ( $a, $b ) { return strcmp( $a['key'], $b['key'] ); } ) );
			self::mail_html( "Update undone: {$culprit['name']} {$culprit['to']}", [
				'tone'   => 'amber',
				'badge'  => 'Update undone',
				'title'  => "{$culprit['name']} {$culprit['to']} was undone",
				'lead'   => "After the automatic updates, the page checks failed. Safe updates put back the previous version and the site passes its checks again. {$culprit['name']} {$culprit['to']} won't be installed again automatically: the next version will be tried normally, or you can update by hand once the problem is understood.",
				'groups' => [
					[ 'What failed', self::failure_items( self::$last_failures ) ],
					[ 'Put back', self::update_items( $restored, true ) ],
					[ 'Installed in this round', self::update_items( $updated ) ],
				],
				'note'   => 'Only the code was put back. If something still looks wrong (an update can also change the database), the database snapshot taken just before can be put back from Settings > Safe updates.',
				'button' => [ 'Open Safe updates', self::page_url() ],
			] );
		} else {
			$snap  = self::snapshots()[0] ?? null;
			$b     = self::updraft_backup();
			$facts = [ [ 'Database snapshot', $snap ? self::email_date( $snap['time'] ) . ' (put back from Settings > Safe updates; anything saved since is lost)' : 'none found' ] ];
			if ( $b ) {
				$facts[] = [ 'UpdraftPlus backup', self::email_date( $b['time'] ) . ' (Settings > UpdraftPlus Backups > Restore)' ];
			}
			self::mail_html( 'URGENT: the site has a problem after updates', [
				'tone'   => 'red',
				'badge'  => 'Urgent',
				'title'  => 'The site has a problem after updates',
				'lead'   => 'The page checks fail after the automatic updates, and putting back the previous versions did not fix it' . ( $restored ? '' : ' (no saved copy could be used)' ) . '. Someone needs to look at the site now.',
				'groups' => [
					[ 'What fails', self::failure_items( $check['failures'] ) ],
					[ 'Installed in this round', self::update_items( $updated ) ],
				],
				'facts'      => $facts,
				'facts_last' => true, // What fails first, then how to get back.
				'button'     => [ 'Open Safe updates', self::page_url() ],
			] );
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
			$recent = array_filter( get_option( 'msu_copies', [] ), function ( $c ) { return time() - $c['time'] < DAY_IN_SECONDS; } );
			$n      = count( $check['failures'] );
			self::mail_html( 'Problem detected on the website', [
				'tone'   => 'red',
				'badge'  => 'Problem',
				'title'  => 1 === $n ? 'A page check fails' : "$n page checks fail",
				'lead'   => 'manual' === $why ? 'Safe updates checked the site a minute after an update made by hand, and found a problem.' : 'The twice-daily check found a problem on the site.',
				'groups' => [
					[ 'What fails', self::failure_items( $check['failures'] ) ],
					[ 'Updated in the last 24 h', array_map( function ( $c ) { return [ $c['name'], "previous version {$c['version']} saved, can be put back" ]; }, array_values( $recent ) ) ],
				],
				'note'   => $recent ? 'If an update caused this, put back its previous version from Settings > Safe updates.' : '',
				'button' => [ 'Open Safe updates', self::page_url() ],
			] );
		} elseif ( $check['ok'] && ! $was_ok ) {
			$n = count( self::pages() );
			self::mail_html( 'Website OK again', [
				'tone'   => 'green',
				'badge'  => 'Back to normal',
				'title'  => 'The site passes its checks again',
				'lead'   => 1 === $n ? 'The page checked loads normally again.' : "All $n pages checked load normally again.",
				'button' => [ 'Open the site', home_url( '/' ) ],
			] );
		}
		self::security_scan();
		if ( time() - ( get_option( 'msu_registry', [] )['time'] ?? 0 ) > DAY_IN_SECONDS - HOUR_IN_SECONDS ) {
			self::register_site(); // Once a day, so the watcher always has the current report link.
		}
	}

	/* ------------------------------------------------------------------ Weekly summary */

	/** Updates that went well are told once a week, instead of in one email each. */
	private static function remember_for_week( $updated ) {
		$week = get_option( 'msu_week', [] );
		foreach ( $updated as $u ) {
			$week[] = [ 'time' => time(), 'name' => $u['name'], 'from' => $u['from'], 'to' => $u['to'] ];
		}
		update_option( 'msu_week', array_slice( $week, -200 ), false );
	}

	/** Monday morning: what was installed during the week, and how the site is doing. Nothing installed, no email. */
	public static function weekly_summary() {
		$week = get_option( 'msu_week', [] );
		update_option( 'msu_week', [], false );
		if ( ! $week ) {
			return;
		}
		$c     = get_option( 'msu_last_check', [] );
		$open  = count( get_option( 'msu_security', [] )['open'] ?? [] );
		$snap  = self::snapshots()[0] ?? null;
		$held  = self::held();
		$ok    = ! empty( $c['ok'] ) && ! $open;
		$n     = count( $week );
		$facts = [
			[ 'Page checks', empty( $c['time'] ) ? 'not run yet' : ( $c['ok'] ? 'all pass' : count( $c['failures'] ) . ' failing' ) ],
			[ 'Security', $open ? "$open to review" : 'nothing suspicious' ],
			[ 'Last snapshot', $snap ? self::email_date( $snap['time'] ) : 'none yet' ],
		];
		if ( $held ) {
			$facts[] = [ 'Big releases waiting', implode( ', ', array_map( function ( $s ) { return "{$s['name']} {$s['version']}"; }, $held ) ) ];
		}
		self::mail_html( "Weekly summary: $n update" . ( 1 === $n ? '' : 's' ) . ' installed', [
			'tone'   => $ok ? 'green' : 'amber',
			'badge'  => 'Weekly summary',
			'title'  => 1 === $n ? '1 update installed this week' : "$n updates installed this week",
			'lead'   => 'Each one was installed right after a database snapshot, and the site passed its page checks afterwards.' . ( $ok ? '' : ' Something still needs attention, see below.' ),
			'facts'  => $facts,
			'groups' => [ [ 'Installed', array_map( function ( $w ) { return [ $w['name'], ( $w['from'] ? "{$w['from']} → {$w['to']}" : $w['to'] ) . ' · ' . self::email_date( $w['time'], 'l M j' ) ]; }, $week ) ] ],
			'button' => [ 'Open Safe updates', self::page_url() ],
			'footer' => 'Sent on Mondays when updates were installed. Problems are emailed right away.',
		] );
		self::log( "Weekly summary sent ($n update" . ( 1 === $n ? '' : 's' ) . ').' );
	}

	/* ------------------------------------------------------------------ What to check, suggested from the site */

	/**
	 * Suggests checks from the site itself: the home page and the main menu's pages that load now, a form marker on
	 * pages that have a form, the Elementor header and footer when the home page has them, WPML's language switcher.
	 * Returns ['pages' => lines, 'markers' => lines, 'names' => page names].
	 */
	public static function suggest_checks() {
		$forms = [ 'class="elementor-form"', 'class="wpcf7-form', 'class="wpforms-form', 'gform_wrapper', 'class="jet-form-builder', 'class="frm-show-form' ];
		$form  = function ( $html ) use ( $forms ) {
			foreach ( $forms as $f ) {
				if ( false !== strpos( $html, $f ) ) {
					return $f;
				}
			}
			return '';
		};
		$res   = self::fetch( home_url( '/' ) );
		$home  = is_wp_error( $res ) ? '' : wp_remote_retrieve_body( $res );
		$pages = [ [ 0 === strpos( get_locale(), 'fr' ) ? 'Accueil' : 'Home', '/', $form( $home ) ] ];
		$base  = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ); // "/" or "/dev/" for a site in a folder.
		$host  = wp_parse_url( home_url(), PHP_URL_HOST );
		$seen  = [ '/' => true ];
		foreach ( self::main_menu_items() as $item ) {
			if ( count( $pages ) >= 9 ) {
				break;
			}
			$url  = (string) $item->url;
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			if ( ( wp_parse_url( $url, PHP_URL_HOST ) && wp_parse_url( $url, PHP_URL_HOST ) !== $host ) || '' === $path || 0 !== strpos( $path, $base ) ) {
				continue; // Another site, an anchor, or outside this site's folder.
			}
			$path = '/' . ltrim( substr( $path, strlen( $base ) ), '/' );
			if ( isset( $seen[ $path ] ) || preg_match( '#\.(pdf|jpe?g|png|zip|docx?)$#i', $path ) ) {
				continue;
			}
			$seen[ $path ] = true;
			$res           = self::fetch( home_url( $path ) );
			if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
				continue; // Only pages that work now.
			}
			$name    = str_replace( '|', '/', mb_substr( trim( wp_strip_all_tags( $item->title ) ), 0, 40 ) );
			$pages[] = [ $name ?: $path, $path, $form( wp_remote_retrieve_body( $res ) ) ];
		}

		$markers = [ '</html> | page is cut off' ];
		foreach ( [ 'elementor-location-header' => 'header missing', 'elementor-location-footer' => 'footer missing' ] as $marker => $why ) {
			if ( false !== strpos( $home, $marker ) ) {
				$markers[] = "$marker | $why";
			}
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && false !== strpos( $home, 'wpml-ls' ) ) {
			$markers[] = 'wpml-ls | language switcher missing';
		}
		return [
			'pages'   => implode( "\n", array_map( function ( $p ) { return rtrim( implode( ' | ', $p ), ' |' ); }, $pages ) ),
			'markers' => implode( "\n", $markers ),
			'names'   => array_column( $pages, 0 ),
		];
	}

	/** The menu in the theme's main location, or else the biggest menu (Elementor menus often have no location). */
	private static function main_menu_items() {
		$locations = array_filter( get_nav_menu_locations() );
		$menu      = null;
		foreach ( [ 'primary', 'main', 'main-menu', 'menu-1', 'header', 'header-menu', 'top' ] as $loc ) {
			if ( ! empty( $locations[ $loc ] ) ) {
				$menu = $locations[ $loc ];
				break;
			}
		}
		if ( ! $menu && $locations ) {
			$menu = reset( $locations );
		}
		if ( ! $menu ) {
			$most = 0;
			foreach ( wp_get_nav_menus() as $m ) {
				if ( $m->count > $most ) {
					$most = $m->count;
					$menu = $m->term_id;
				}
			}
		}
		return $menu ? (array) wp_get_nav_menu_items( $menu ) : [];
	}

	/** New install: fill in what to check from the site, unless someone already saved settings. */
	public static function auto_configure() {
		if ( false !== get_option( 'msu_settings' ) ) {
			return;
		}
		$s = self::suggest_checks();
		update_option( 'msu_settings', [ 'pages' => $s['pages'], 'markers' => $s['markers'] ] + self::settings(), false );
		self::log( 'Pages to check filled in from the site: ' . implode( ', ', $s['names'] ) . '.' );
		self::run_checks();
	}


	/* ------------------------------------------------------------------ Security watch */

	const PHP_FILE = '/\.(php[3-8]?|phtml|pht|phar)$/i'; // Extensions most servers run as PHP.

	/** Set when an account's role changes in this request, so the admin check runs once at the end of it. */
	private static $admin_actor = null;

	/**
	 * Looks for what an intruder leaves behind: a new administrator, changed WordPress core files,
	 * PHP files that appeared or changed in plugins and themes outside an update, PHP files in uploads.
	 * A finding stays open until someone marks it reviewed, and is emailed once, when first seen.
	 * $only_admins: the quick check run right after a role change; everything else stays as it was.
	 */
	public static function security_scan( $only_admins = false ) {
		if ( ! $only_admins && function_exists( 'set_time_limit' ) ) {
			set_time_limit( 600 );
		}
		$t0    = microtime( true );
		$found = $only_admins ? self::scan_admins() : array_merge( self::scan_admins(), self::scan_core(), self::scan_content(), self::scan_uploads() );
		$sec   = get_option( 'msu_security', [] );
		$prev  = $sec['open'] ?? [];
		$open  = [];
		foreach ( $prev as $key => $f ) {
			if ( $only_admins || 'admin' === $f['type'] ) {
				$open[ $key ] = $f; // A new admin stays listed until reviewed; file findings are re-checked by each full scan.
			}
		}
		foreach ( $found as $key => $f ) {
			$open[ $key ] = $prev[ $key ] ?? $f + [ 'seen' => time() ];
		}
		$new         = array_diff_key( $open, $prev );
		$sec['open'] = $open;
		if ( ! $only_admins ) {
			$sec['checked'] = time();
			$sec['took']    = round( microtime( true ) - $t0, 1 );
		}
		update_option( 'msu_security', $sec, false );
		if ( $new ) {
			self::log( 'Security: ' . implode( '; ', array_map( function ( $f ) { return "{$f['title']} ({$f['detail']})"; }, $new ) ) );
			self::security_mail( $new, $open );
		}
		return $sec;
	}

	/** Role changes (also accounts created with a role): check the administrators when the request ends. */
	public static function admin_changed() {
		if ( null === self::$admin_actor ) {
			self::$admin_actor = wp_get_current_user()->user_login ?: '';
			add_action( 'shutdown', function () { self::security_scan( true ); } );
		}
	}

	/** Administrators who weren't there at the last check. The first check only notes who is there. */
	private static function scan_admins() {
		$now = [];
		foreach ( get_users( [ 'role' => 'administrator' ] ) as $u ) {
			$now[ $u->ID ] = $u;
		}
		if ( is_multisite() ) {
			foreach ( get_super_admins() as $login ) {
				$u = get_user_by( 'login', $login );
				if ( $u ) {
					$now[ $u->ID ] = $u;
				}
			}
		}
		$known = get_option( 'msu_admins' );
		update_option( 'msu_admins', array_map( function ( $u ) { return $u->user_login; }, $now ), false );
		if ( ! is_array( $known ) ) {
			return [];
		}
		$by    = null === self::$admin_actor ? '' : ( self::$admin_actor ? ', by ' . self::$admin_actor : ', by no logged-in user' );
		$found = [];
		foreach ( array_diff_key( $now, $known ) as $id => $u ) {
			$found[ "admin:$id" ] = [
				'type'   => 'admin',
				'title'  => "New administrator: {$u->user_login}",
				'detail' => $u->user_email . ', registered ' . substr( $u->user_registered, 0, 16 ) . $by, // As stored: WordPress writes UTC, other tools (WP-CLI) local time.
			];
		}
		return $found;
	}

	/** WordPress's own files compared with the official checksums, plus PHP files WordPress doesn't ship. */
	private static function scan_core() {
		global $wp_version, $wp_local_package;
		$sums = get_site_transient( 'msu_core_sums' );
		if ( ! is_array( $sums ) || ( $sums['version'] ?? '' ) !== $wp_version ) {
			$sums = null;
			foreach ( array_unique( [ $wp_local_package ?? 'en_US', 'en_US' ] ) as $locale ) {
				$res  = wp_remote_get( add_query_arg( [ 'version' => $wp_version, 'locale' => $locale ], 'https://api.wordpress.org/core/checksums/1.0/' ), [ 'timeout' => 20 ] );
				$body = is_wp_error( $res ) ? [] : json_decode( wp_remote_retrieve_body( $res ), true );
				if ( ! empty( $body['checksums'] ) && is_array( $body['checksums'] ) ) {
					$sums = [ 'version' => $wp_version, 'files' => $body['checksums'] ];
					break;
				}
			}
			if ( ! $sums ) {
				self::log( 'Security: could not get the official WordPress checksums, core files not checked this time.' );
				return [];
			}
			set_site_transient( 'msu_core_sums', $sums, WEEK_IN_SECONDS );
		}

		$reviewed = get_option( 'msu_sec_reviewed', [] );
		$found    = [];
		$add      = function ( $type, $rel, $title ) use ( &$found, $reviewed ) {
			$key = "$type:$rel:" . md5_file( ABSPATH . $rel );
			if ( ! isset( $reviewed[ $key ] ) ) {
				$found[ $key ] = [ 'type' => $type, 'title' => $title, 'detail' => $rel ];
			}
		};
		foreach ( $sums['files'] as $rel => $md5 ) {
			if ( 0 !== strpos( $rel, 'wp-content/' ) && is_file( ABSPATH . $rel ) && md5_file( ABSPATH . $rel ) !== $md5 ) {
				$add( 'core', $rel, 'WordPress core file changed' );
			}
		}
		foreach ( [ 'wp-admin', 'wp-includes' ] as $dir ) {
			foreach ( self::php_files( ABSPATH . $dir ) as $abs ) {
				$rel = substr( $abs, strlen( ABSPATH ) );
				if ( ! isset( $sums['files'][ $rel ] ) ) {
					$add( 'core-extra', $rel, 'PHP file WordPress doesn\'t ship' );
				}
			}
		}
		foreach ( glob( ABSPATH . '*' ) ?: [] as $abs ) {
			$rel = basename( $abs );
			if ( is_file( $abs ) && preg_match( self::PHP_FILE, $rel ) && ! isset( $sums['files'][ $rel ] ) && 'wp-config.php' !== $rel ) {
				$add( 'core-extra', $rel, 'PHP file WordPress doesn\'t ship' );
			}
		}
		return $found;
	}

	/** PHP files in plugins, themes and must-use plugins compared with their fingerprints. The first scan takes the fingerprints. */
	private static function scan_content() {
		// Files read while an update replaces them would look changed: skip this time, the next scan compares them.
		if ( get_option( 'auto_updater.lock' ) || file_exists( ABSPATH . '.maintenance' ) ) {
			self::log( 'Security: an update was running, plugin and theme files not compared this time.' );
			return [];
		}
		$start = time();
		$now   = self::content_hashes( self::read_index() ?: [] );
		wp_cache_delete( 'msu_reindexed', 'options' );
		if ( (int) get_option( 'msu_reindexed', 0 ) >= $start ) {
			self::log( 'Security: an update finished during the scan, plugin and theme files not compared this time.' );
			return [];
		}
		$old = self::read_index(); // Read again: an update may have stored new fingerprints meanwhile.
		if ( null === $old ) {
			self::write_index( $now );
			return [];
		}
		$found = [];
		foreach ( $now as $rel => $f ) {
			if ( ! isset( $old[ $rel ] ) ) {
				$found[ "content-new:$rel:{$f[0]}" ] = [ 'type' => 'content-new', 'title' => 'New PHP file (not from an update)', 'detail' => "wp-content/$rel" ];
			} elseif ( $old[ $rel ][0] !== $f[0] ) {
				$found[ "content-changed:$rel:{$f[0]}" ] = [ 'type' => 'content-changed', 'title' => 'PHP file changed (not by an update)', 'detail' => "wp-content/$rel" ];
			}
		}
		if ( array_diff_key( $old, $now ) ) {
			self::write_index( array_intersect_key( $old, $now ) ); // Deleted files (a plugin removed): nothing to report.
		}
		return $found;
	}

	/** Any PHP file in uploads is suspect, except the empty "Silence is golden" ones plugins leave there. */
	private static function scan_uploads() {
		$base     = wp_get_upload_dir()['basedir'];
		$reviewed = get_option( 'msu_sec_reviewed', [] );
		$found    = [];
		foreach ( self::php_files( $base ) as $abs ) {
			if ( filesize( $abs ) < 512 && '' === trim( preg_replace( '#<\?php|\?>|//[^\n]*|\#[^\n]*|/\*.*?\*/#s', '', (string) file_get_contents( $abs ) ) ) ) {
				continue;
			}
			$rel = 'wp-content/uploads' . substr( $abs, strlen( $base ) );
			$key = "upload:$rel:" . md5_file( $abs );
			if ( ! isset( $reviewed[ $key ] ) ) {
				$found[ $key ] = [ 'type' => 'upload', 'title' => 'PHP file in uploads', 'detail' => $rel ];
			}
		}
		return $found;
	}

	private static function php_files( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return [];
		}
		$files = [];
		$it    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::LEAVES_ONLY, RecursiveIteratorIterator::CATCH_GET_CHILD );
		foreach ( $it as $file ) {
			if ( $file->isFile() && preg_match( self::PHP_FILE, $file->getFilename() ) ) {
				$files[] = $file->getPathname();
			}
		}
		return $files;
	}

	/** Folders whose PHP files are fingerprinted, relative to wp-content. */
	private static function content_dirs() {
		return array_filter( [ WP_PLUGIN_DIR, get_theme_root(), WPMU_PLUGIN_DIR ], 'is_dir' );
	}

	/**
	 * [path relative to wp-content => [md5, ctime, size]] for every PHP file in plugins, themes, mu-plugins and wp-content itself.
	 * A file whose size and ctime didn't move keeps its md5 (ctime can't be set back without root, unlike mtime).
	 */
	private static function content_hashes( $old, $dirs = null ) {
		$files = [];
		foreach ( $dirs ?? self::content_dirs() as $dir ) {
			$files = array_merge( $files, self::php_files( $dir ) );
		}
		if ( null === $dirs ) {
			foreach ( glob( WP_CONTENT_DIR . '/*' ) ?: [] as $abs ) {
				if ( is_file( $abs ) && preg_match( self::PHP_FILE, $abs ) ) {
					$files[] = $abs; // advanced-cache.php, object-cache.php, db.php... and anything dropped next to them.
				}
			}
		}
		$out = [];
		foreach ( $files as $abs ) {
			if ( false !== strpos( $abs, '-msu-replaced/' ) ) {
				continue;
			}
			$rel   = ltrim( substr( $abs, strlen( WP_CONTENT_DIR ) ), '/' );
			$ctime = filectime( $abs );
			$size  = filesize( $abs );
			$out[ $rel ] = isset( $old[ $rel ] ) && $old[ $rel ][1] === $ctime && $old[ $rel ][2] === $size ? $old[ $rel ] : [ md5_file( $abs ), $ctime, $size ];
		}
		return $out;
	}

	private static function read_index() {
		$index = json_decode( (string) @file_get_contents( self::copies_dir() . '/php-index.json' ), true );
		return is_array( $index ) ? $index : null;
	}

	private static function write_index( $index ) {
		file_put_contents( self::copies_dir() . '/php-index.json', wp_json_encode( $index ) );
	}

	/** After an update, install or put-back: new fingerprints for that folder only, so its new files aren't reported. */
	public static function reindex_folder( $abs ) {
		$index = self::read_index();
		if ( null === $index || ! $abs ) {
			return;
		}
		$prefix = ltrim( substr( untrailingslashit( $abs ), strlen( WP_CONTENT_DIR ) ), '/' ) . '/';
		foreach ( array_keys( $index ) as $rel ) {
			if ( 0 === strpos( $rel, $prefix ) ) {
				unset( $index[ $rel ] );
			}
		}
		self::write_index( $index + self::content_hashes( [], is_dir( $abs ) ? [ $abs ] : [] ) );
		update_option( 'msu_reindexed', time(), false ); // Tells a scan running at the same time that its file readings are out of date.
	}

	public static function reindex_after_upgrade( $upgrader, $extra ) {
		$type    = $extra['type'] ?? '';
		$folders = [];
		if ( 'plugin' === $type ) {
			foreach ( (array) ( $extra['plugins'] ?? ( $extra['plugin'] ?? [] ) ) as $file ) {
				$folders[] = WP_PLUGIN_DIR . '/' . dirname( $file );
			}
		} elseif ( 'theme' === $type ) {
			foreach ( (array) ( $extra['themes'] ?? ( $extra['theme'] ?? [] ) ) as $slug ) {
				$folders[] = get_theme_root( $slug ) . '/' . $slug;
			}
		}
		if ( ! empty( $upgrader->result['destination'] ) && in_array( $type, [ 'plugin', 'theme' ], true ) ) {
			$folders[] = $upgrader->result['destination']; // New installs.
		}
		foreach ( array_unique( array_filter( $folders, function ( $f ) { return '.' !== basename( $f ); } ) ) as $folder ) {
			self::reindex_folder( $folder );
		}
	}

	/** "It was us": the open findings become the new normal and stop being reported. */
	public static function security_review() {
		$sec      = get_option( 'msu_security', [] );
		$reviewed = get_option( 'msu_sec_reviewed', [] );
		$index    = self::read_index() ?? [];
		foreach ( $sec['open'] ?? [] as $key => $f ) {
			if ( 0 === strpos( $f['type'], 'content-' ) ) {
				$rel = substr( $f['detail'], strlen( 'wp-content/' ) );
				$abs = WP_CONTENT_DIR . '/' . $rel;
				if ( is_file( $abs ) ) {
					$index[ $rel ] = [ md5_file( $abs ), filectime( $abs ), filesize( $abs ) ];
				} else {
					unset( $index[ $rel ] );
				}
			} elseif ( 'admin' !== $f['type'] ) {
				$reviewed[ $key ] = time();
			}
		}
		self::write_index( $index );
		update_option( 'msu_sec_reviewed', $reviewed, false );
		$n           = count( $sec['open'] ?? [] );
		$sec['open'] = [];
		update_option( 'msu_security', $sec, false );
		self::log( "Security: $n finding" . ( 1 === $n ? '' : 's' ) . ' marked as reviewed by ' . ( wp_get_current_user()->user_login ?: 'someone' ) . '.' );
	}

	private static function security_mail( $new, $open ) {
		$admins = array_filter( $new, function ( $f ) { return 'admin' === $f['type']; } );
		$n      = count( $new );
		$title  = 1 === $n && $admins ? 'New administrator account' : ( 1 === $n ? 'One thing to check' : "$n things to check" );
		$items  = [];
		foreach ( array_slice( $new, 0, 15 ) as $f ) {
			$items[] = [ $f['title'], $f['detail'] ];
		}
		if ( $n > 15 ) {
			$items[] = [ 'And ' . ( $n - 15 ) . ' more', 'listed in Settings > Safe updates' ];
		}
		self::mail_html( "Security: $title", [
			'tone'   => 'red',
			'badge'  => 'Security',
			'title'  => $title,
			'lead'   => 'Safe updates found changes an intruder could have made. If you or someone you trust did this, mark it as reviewed. If not, act now: remove unknown administrators, change the admin passwords, and replace changed files with clean copies.',
			'groups' => [ [ '', $items, true ] ],
			'button' => [ 'Review in Safe updates', self::page_url() ],
			'note'   => count( $open ) > $n ? ( count( $open ) - $n ) . ' earlier finding' . ( count( $open ) - $n > 1 ? 's are' : ' is' ) . ' also still open.' : '',
			'footer' => 'Safe updates checks this twice a day and right after any role change.',
		] );
	}

	/* ------------------------------------------------------------------ Status page (for the watcher) and update report */

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
		$snap = self::snapshots()[0] ?? null;
		$b    = self::updraft_backup();
		$sec  = get_option( 'msu_security', [] );
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
				'last_backup' => $fmt( $snap['time'] ?? 0 ), // The database snapshot updates rely on (was UpdraftPlus before 2.3).
				'backup_ok'   => (bool) $snap,
				'snapshot'    => $snap ? [ 'time' => $fmt( $snap['time'] ), 'size' => $snap['size'] ] : null,
				'updraftplus' => $b ? [ 'time' => $fmt( $b['time'] ), 'ok' => $b['ok'] ] : null,
				'held'        => $held,
				'blocked'     => $blocked,
			],
			'security'      => [
				'checked'  => $fmt( $sec['checked'] ?? 0 ),
				'open'     => count( $sec['open'] ?? [] ),
				'findings' => array_values( array_map( function ( $f ) use ( $fmt ) { return [ 'type' => $f['type'], 'title' => $f['title'], 'detail' => $f['detail'], 'seen' => $fmt( $f['seen'] ) ]; }, $sec['open'] ?? [] ) ),
			],
		];
		$res = new WP_REST_Response( $data, 200 );
		$res->header( 'Cache-Control', 'no-store' );
		$res->header( 'X-Robots-Tag', 'noindex' );
		return $res;
	}

	/* ------------------------------------------------------------------ Admin screens: shared pieces */

	private static function page_url( $tab = '' ) {
		return admin_url( 'options-general.php?page=msu-settings' . ( $tab ? "&tab=$tab" : '' ) );
	}

	/** "3 h ago" / "in 2 days", with the exact date on hover. */
	private static function ago( $t ) {
		if ( ! $t ) {
			return '—';
		}
		$diff = human_time_diff( $t, time() );
		return '<time datetime="' . esc_attr( gmdate( 'c', $t ) ) . '" title="' . esc_attr( wp_date( 'Y-m-d H:i', $t ) ) . '">' . esc_html( $t > time() ? "in $diff" : "$diff ago" ) . '</time>';
	}

	/** Name of an item from its key ("plugin:elementor/elementor.php"). */
	private static function key_name( $key ) {
		if ( 'core' === $key ) {
			return 'WordPress';
		}
		list( $type, $id ) = array_pad( explode( ':', $key, 2 ), 2, '' );
		return self::item_name( $type, $id );
	}

	private static function held() {
		return array_filter( get_option( 'msu_seen', [] ), function ( $s ) { return time() - $s['time'] < self::HOLD_DAYS * DAY_IN_SECONDS; } );
	}

	/** One line that sums up the site: [level ok|warning|error, headline]. Worst problem wins. */
	private static function overall() {
		$c    = get_option( 'msu_last_check', [] );
		$open = count( get_option( 'msu_security', [] )['open'] ?? [] );
		if ( $open ) {
			return [ 'error', 1 === $open ? 'Security: 1 thing to review' : "Security: $open things to review" ];
		}
		if ( ! empty( $c['time'] ) && ! $c['ok'] ) {
			$n = count( $c['failures'] );
			return [ 'error', $n > 1 ? "$n checks fail" : 'A check fails' ];
		}
		if ( get_transient( 'msu_alert_snapshot-failing' ) ) {
			return [ 'warning', 'Updates on hold: the database snapshot failed' ];
		}
		if ( empty( $c['time'] ) ) {
			return [ 'warning', 'Page checks have not run yet' ];
		}
		if ( time() - $c['time'] > self::STALE_CHECK ) {
			return [ 'warning', 'Page checks have stopped running' ];
		}
		return [ 'ok', 'Site OK' ];
	}

	/** Status headline and the key facts, shared by the dashboard box and the Overview tab. */
	private static function render_summary() {
		$c       = get_option( 'msu_last_check', [] );
		$snap    = self::snapshots()[0] ?? null;
		$b       = self::updraft_backup();
		$held    = self::held();
		$blocked = get_option( 'msu_blocked', [] );
		$next    = wp_next_scheduled( 'msu_check' );
		list( $level, $headline ) = self::overall();

		echo '<div class="msu-head msu-' . esc_attr( $level ) . '"><span class="msu-dot" aria-hidden="true"></span><strong>' . esc_html( $headline ) . '</strong>';
		if ( $next ) {
			echo '<span class="msu-sub">Next check ' . ( $next > time() ? self::ago( $next ) : 'due now' ) . '</span>';
		}
		echo '</div><dl class="msu-rows">';

		echo '<dt>Page checks</dt><dd>';
		if ( empty( $c['time'] ) ) {
			echo 'Not run yet';
		} else {
			$n = count( self::pages() );
			echo esc_html( $c['ok'] ? ( 1 === $n ? '1 page passes' : "All $n pages pass" ) : 'Problem found' ) . ' · ' . self::ago( $c['time'] );
			if ( ! empty( $c['failures'] ) ) {
				echo '<ul class="msu-fails">';
				foreach ( $c['failures'] as $k => $v ) {
					echo '<li><strong>' . esc_html( $k ) . '</strong> ' . esc_html( $v ) . '</li>';
				}
				echo '</ul>';
			}
		}
		echo '</dd>';

		echo '<dt>Database snapshot</dt><dd>' . ( $snap ? self::ago( $snap['time'] ) . ' <span class="msu-muted">' . esc_html( size_format( $snap['size'] ) ) . '</span>' : 'None yet <span class="msu-muted">saved before each update</span>' ) . '</dd>';
		$sec  = get_option( 'msu_security', [] );
		$open = count( $sec['open'] ?? [] );
		echo '<dt>Security</dt><dd>' . ( empty( $sec['checked'] ) ? 'Not scanned yet' : ( $open ? '<span class="msu-warn">' . esc_html( 1 === $open ? '1 thing to review' : "$open things to review" ) . '</span>' : 'Nothing suspicious' ) . ' · ' . self::ago( $sec['checked'] ) ) . '</dd>';
		if ( $b ) {
			echo '<dt>UpdraftPlus</dt><dd>' . self::ago( $b['time'] ) . ( $b['ok'] ? '' : ' <span class="msu-warn">incomplete</span>' ) . ' <span class="msu-muted">not needed for updates</span></dd>';
		}

		if ( $held ) {
			echo '<dt>On hold</dt><dd><ul class="msu-list">';
			foreach ( $held as $s ) {
				echo '<li>' . esc_html( "{$s['name']} {$s['version']}" ) . ' <span class="msu-muted">installs ' . self::ago( $s['time'] + self::HOLD_DAYS * DAY_IN_SECONDS ) . '</span></li>';
			}
			echo '</ul></dd>';
		}

		if ( $blocked ) {
			echo '<dt>Blocked</dt><dd><ul class="msu-list">';
			foreach ( $blocked as $key => $version ) {
				echo '<li>' . esc_html( self::key_name( $key ) . " $version" ) . ' <span class="msu-muted">broke the site</span> ';
				self::action_button( 'msu_unblock', 'Allow again', [ 'key' => $key ], 'button-link', 'Let ' . self::key_name( $key ) . " $version install automatically again?" );
				echo '</li>';
			}
			echo '</ul></dd>';
		}
		echo '</dl>';
	}

	/** Small POST form with one button, for admin-post.php actions. */
	private static function action_button( $action, $label, $fields = [], $class = 'button', $confirm = '', $busy = '' ) {
		echo '<form class="msu-inline" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"' . ( $confirm ? ' data-msu-confirm="' . esc_attr( $confirm ) . '"' : '' ) . '>';
		wp_nonce_field( $action );
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		echo '<button type="submit" class="' . esc_attr( $class ) . '"' . ( $busy ? ' data-msu-busy="' . esc_attr( $busy ) . '"' : '' ) . '>' . esc_html( $label ) . '</button></form>';
	}

	private static function render_copies( $copies ) {
		echo '<table class="msu-table"><tbody>';
		foreach ( $copies as $key => $copy ) {
			echo '<tr><td><strong>' . esc_html( $copy['name'] ) . '</strong> ' . esc_html( $copy['version'] ) . '</td><td class="msu-muted">saved ' . self::ago( $copy['time'] ) . '</td><td class="msu-end">';
			self::action_button( 'msu_restore', 'Put back', [ 'key' => $key ], 'button button-small', "Put back {$copy['name']} {$copy['version']}? The version installed now will be replaced.", 'Putting back…' );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_snapshots() {
		echo '<table class="msu-table"><tbody>';
		foreach ( self::snapshots() as $snap ) {
			$when = wp_date( 'Y-m-d H:i', $snap['time'] );
			echo '<tr><td><strong>' . esc_html( $when ) . '</strong> <span class="msu-muted">' . esc_html( size_format( $snap['size'] ) ) . '</span></td><td class="msu-muted">' . self::ago( $snap['time'] ) . '</td><td class="msu-end">';
			self::action_button( 'msu_restore_db', 'Put back', [ 'file' => $snap['file'] ], 'button button-small', "Put back the whole database as it was on $when? Everything saved since then (pages, form entries, orders, settings) will be lost.", 'Putting back…' );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private static function render_log( $entries ) {
		echo '<table class="msu-table msu-log"><tbody>';
		foreach ( $entries as $l ) {
			echo '<tr><td class="msu-muted msu-when">' . self::ago( $l[0] ) . '</td><td>' . esc_html( $l[1] ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** A2 Studio artwork, straight from assets/ (never retyped): the app icon, or the horizontal "A² Studio" logo. */
	private static function brand_svg( $which, $class ) {
		$icon = 'icon' === $which;
		$svg  = trim( (string) file_get_contents( __DIR__ . ( $icon ? '/assets/a2-icon.svg' : '/assets/a2-logo.svg' ) ) );
		$attr = ' class="' . esc_attr( $class ) . '"' . ( $icon ? ' width="20" height="20" aria-hidden="true"' : ' width="100" height="30" role="img" aria-label="A2 Studio"' );
		return preg_replace( '/^<svg /', '<svg' . $attr . ' ', $svg );
	}

	/** Styles and behaviour for the dashboard box and the settings screen, printed once. */
	private static function assets() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		?>
<style>
.msu-head{display:flex;align-items:center;gap:8px;margin:0 0 14px}
.msu-head strong{font-size:14px}
.msu-dot{flex:none;width:10px;height:10px;border-radius:50%;background:#00a32a;box-shadow:0 0 0 3px #edfaef}
.msu-warning .msu-dot{background:#dba617;box-shadow:0 0 0 3px #fcf9e8}
.msu-error .msu-dot{background:#d63638;box-shadow:0 0 0 3px #fcf0f1}
.msu-sub{margin-left:auto;color:#646970;font-size:12px}
.msu-rows{display:grid;grid-template-columns:max-content 1fr;gap:8px 20px;margin:0}
.msu-rows dt{color:#646970}
.msu-rows dd{margin:0}
.msu-list,.msu-fails{margin:0}
.msu-list li,.msu-fails li{margin:0 0 4px}
.msu-fails{margin-top:6px;padding:8px 10px;border-left:3px solid #d63638;background:#fcf0f1}
.msu-fails strong{margin-right:4px}
.msu-muted{color:#646970}
.msu-version{color:#646970;font-size:13px;font-weight:400}
.msu-warn{color:#996800}
.msu-inline{display:inline}
.msu-inline .button-link{vertical-align:baseline}
.msu-actions{display:flex;flex-wrap:wrap;align-items:center;gap:12px;margin:16px -12px 0;padding:12px 12px 0;border-top:1px solid #f0f0f1}
.msu-actions a{margin-left:auto;text-decoration:none}
.msu-more{margin:12px -12px 0;border-top:1px solid #f0f0f1}
.msu-more summary{padding:10px 12px 0;cursor:pointer;font-weight:600}
.msu-more[open] summary{padding-bottom:6px}
.msu-more > :not(summary){margin:0 12px}
.msu-table{width:100%;border-collapse:collapse}
.msu-table td{padding:6px 8px 6px 0;border-top:1px solid #f0f0f1;vertical-align:top}
.msu-table tr:first-child td{border-top:0}
.msu-end{text-align:right;white-space:nowrap}
.msu-when{width:1%;white-space:nowrap}
.msu-wrap{max-width:860px}
.msu-wrap .card{max-width:none;margin:16px 0 0;padding:16px 20px}
.msu-wrap .card h2{margin:0 0 4px;font-size:14px}
.msu-wrap .card > p.description{margin:0 0 14px}
.msu-wrap .msu-actions,.msu-wrap .msu-more{margin-left:-20px;margin-right:-20px;padding-left:20px;padding-right:20px}
.msu-wrap .msu-more summary{padding-left:0}
.msu-wrap .msu-more > :not(summary){margin:0}
.msu-rows-edit td{padding:4px 8px 4px 0;vertical-align:middle}
.msu-rows-edit th{padding:0 8px 6px 0;text-align:left;font-weight:600}
.msu-rows-edit input{width:100%}
.msu-rows-edit .msu-x{width:1%}
.msu-field{display:flex;gap:8px;align-items:center}
.msu-field input{flex:1;min-width:0}
.msu-form-actions{display:flex;gap:8px;margin-top:10px}
.msu-brand{display:flex;align-items:center;gap:14px;margin:12px 0 18px;padding:16px 20px;background:#fff;border:1px solid #E2E2EC;border-radius:10px}
.msu-brand .msu-icon{flex:none;width:44px;height:44px}
.msu-brand h1{margin:0;padding:0;font-size:22px;line-height:1.2;font-weight:700;color:#101013}
.msu-brand p{margin:2px 0 0;color:#5B5B6B}
.msu-by{display:flex;align-items:center;gap:8px;margin-left:auto;color:#5B5B6B;font-size:12px;text-decoration:none}
.msu-by .msu-logo{width:100px;height:30px}
.msu-wrap .nav-tab-active,.msu-wrap .nav-tab-active:hover{box-shadow:inset 0 3px 0 #3A3AFF}
.msu-wrap .button-primary{background:#3A3AFF;border-color:#3A3AFF;color:#fff}
.msu-wrap .button-primary:hover,.msu-wrap .button-primary:focus{background:#2B2BE0;border-color:#2B2BE0;color:#fff}
.msu-wrap .button-primary:focus{box-shadow:0 0 0 1px #fff,0 0 0 3px #3A3AFF}
.msu-widget-title{display:inline-flex;align-items:center;gap:8px}
.msu-widget-title .msu-icon{width:20px;height:20px}
@media (max-width:600px){.msu-by span{display:none}.msu-sub{display:none}.msu-rows{grid-template-columns:1fr;gap:2px}.msu-rows dd{margin-bottom:8px}}
</style>
<script>
document.addEventListener('submit', function (e) {
	var f = e.target, msg = f.getAttribute('data-msu-confirm');
	if (msg && !window.confirm(msg)) { e.preventDefault(); return; }
	var b = f.querySelector('[data-msu-busy]');
	if (b) { setTimeout(function () { b.disabled = true; b.textContent = b.getAttribute('data-msu-busy'); }); }
});
document.addEventListener('click', function (e) {
	var t = e.target.closest('[data-msu-copy],[data-msu-add],.msu-x button');
	if (!t) { return; }
	if (t.hasAttribute('data-msu-copy')) {
		var input = document.getElementById(t.getAttribute('data-msu-copy')), label = t.textContent;
		input.select();
		(navigator.clipboard ? navigator.clipboard.writeText(input.value) : Promise.resolve(document.execCommand('copy'))).then(function () {
			t.textContent = 'Copied';
			setTimeout(function () { t.textContent = label; }, 1500);
		});
		return;
	}
	if (t.hasAttribute('data-msu-add')) {
		var body = document.getElementById(t.getAttribute('data-msu-add')), rows = JSON.parse(t.getAttribute('data-msu-rows') || '[[]]');
		rows.forEach(function (values) {
			var row = document.getElementById(body.id + '-tpl').content.firstElementChild.cloneNode(true);
			row.querySelectorAll('input').forEach(function (input, i) { input.value = values[i] || ''; });
			body.appendChild(row);
			if (!values.length) { row.querySelector('input').focus(); }
		});
		if (t.hasAttribute('data-msu-rows')) { t.remove(); }
		return;
	}
	var tr = t.closest('tr'), tbody = tr.parentNode;
	tr.remove();
	if (!tbody.children.length) { document.querySelector('[data-msu-add="' + tbody.id + '"]').click(); }
});
</script>
		<?php
	}

	/* ------------------------------------------------------------------ Dashboard box */

	public static function dashboard_widget() {
		if ( current_user_can( 'update_plugins' ) ) {
			wp_add_dashboard_widget( 'msu_widget', '<span class="msu-widget-title">' . self::brand_svg( 'icon', 'msu-icon' ) . 'Safe updates</span>', [ __CLASS__, 'render_widget' ] );
		}
	}

	public static function render_widget() {
		$copies = get_option( 'msu_copies', [] );
		$log    = array_slice( array_reverse( get_option( 'msu_log', [] ) ), 0, 5 );
		self::assets();
		self::render_summary();

		echo '<div class="msu-actions">';
		self::action_button( 'msu_check_now', 'Run checks now', [], 'button', '', 'Checking…' );
		if ( current_user_can( 'manage_options' ) ) {
			echo '<a href="' . esc_url( self::page_url() ) . '">Details and settings →</a>';
		}
		echo '</div>';

		if ( $copies ) {
			echo '<details class="msu-more"><summary>Previous versions (' . count( $copies ) . ')</summary>';
			self::render_copies( $copies );
			echo '</details>';
		}
		if ( current_user_can( 'manage_options' ) && self::snapshots() ) {
			echo '<details class="msu-more"><summary>Database snapshots (' . count( self::snapshots() ) . ')</summary>';
			self::render_snapshots();
			echo '</details>';
		}
		if ( $log ) {
			echo '<details class="msu-more"><summary>Recent activity</summary>';
			self::render_log( $log );
			echo '</details>';
		}
	}

	/* ------------------------------------------------------------------ Settings screen */

	public static function settings_menu() {
		add_options_page( 'Safe updates', 'Safe updates', 'manage_options', 'msu-settings', [ __CLASS__, 'render_settings' ] );
	}

	public static function render_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = [ 'overview' => 'Overview', 'settings' => 'Settings', 'monitoring' => 'Monitoring' ];
		$tab  = sanitize_key( $_GET['tab'] ?? '' );
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'overview';
		self::assets();

		echo '<div class="wrap msu-wrap"><div class="msu-brand">' . self::brand_svg( 'icon', 'msu-icon' )
			. '<div><h1>Safe updates <span class="msu-version">v' . esc_html( self::VERSION ) . '</span></h1><p>Updates, backups and security for this site</p></div>'
			. '<div class="msu-by"><span>by</span>' . self::brand_svg( 'logo', 'msu-logo' ) . '</div></div><hr class="wp-header-end">';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $id => $label ) {
			echo '<a href="' . esc_url( self::page_url( 'overview' === $id ? '' : $id ) ) . '" class="nav-tab' . ( $id === $tab ? ' nav-tab-active' : '' ) . '"' . ( $id === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		call_user_func( [ __CLASS__, "render_tab_$tab" ] );
		echo '</div>';
	}

	public static function render_tab_overview() {
		$copies = get_option( 'msu_copies', [] );
		$log    = array_reverse( get_option( 'msu_log', [] ) );

		echo '<div class="card">';
		self::render_summary();
		echo '<div class="msu-actions">';
		self::action_button( 'msu_check_now', 'Run checks now', [], 'button', '', 'Checking…' );
		echo '<span class="msu-muted">Updates install right after a database snapshot. Big releases wait ' . esc_html( self::HOLD_DAYS ) . ' days.</span></div></div>';

		$sec = get_option( 'msu_security', [] );
		echo '<div class="card" id="msu-security"><h2>Security</h2><p class="description">Twice a day, and right after any role change, Safe updates looks for what an intruder leaves behind: a new administrator, WordPress core files that differ from the official ones, PHP files that appeared or changed in plugins and themes outside an update, and PHP files in uploads.</p>';
		if ( ! empty( $sec['open'] ) ) {
			echo '<table class="msu-table"><tbody>';
			foreach ( $sec['open'] as $f ) {
				echo '<tr><td><strong>' . esc_html( $f['title'] ) . '</strong><br><code>' . esc_html( $f['detail'] ) . '</code></td><td class="msu-muted msu-end">found ' . self::ago( $f['seen'] ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="msu-muted">' . ( empty( $sec['checked'] ) ? 'Not scanned yet. The first scan notes what is there now; later scans report what changes.' : 'Nothing suspicious. Last scan ' . self::ago( $sec['checked'] ) . ( isset( $sec['took'] ) ? ', took ' . esc_html( $sec['took'] ) . ' s' : '' ) . '.' ) . '</p>';
		}
		echo '<div class="msu-actions">';
		self::action_button( 'msu_security_scan', 'Scan now', [], 'button', '', 'Scanning…' );
		if ( ! empty( $sec['open'] ) ) {
			self::action_button( 'msu_security_review', 'Mark all as reviewed', [], 'button', 'Only do this if you know where each of these comes from. They will stop being reported. Continue?', 'Saving…' );
		}
		echo '</div></div>';

		echo '<div class="card"><h2>Previous versions</h2><p class="description">A copy of each plugin and theme is kept just before it updates. If an update breaks the site, it is put back automatically.</p>';
		if ( $copies ) {
			self::render_copies( $copies );
		} else {
			echo '<p class="msu-muted">No copies yet. One is saved at the next update.</p>';
		}
		echo '</div>';

		echo '<div class="card"><h2>Database snapshots</h2><p class="description">The database is saved just before updates install (the last ' . esc_html( self::SNAPSHOTS_KEPT ) . ' are kept). Putting one back undoes everything saved since, so it is never done automatically.</p>';
		if ( self::snapshots() ) {
			self::render_snapshots();
		} else {
			echo '<p class="msu-muted">None yet. One is saved at the next update.</p>';
		}
		echo '<div class="msu-actions">';
		self::action_button( 'msu_snapshot_now', 'Save a snapshot now', [], 'button', '', 'Saving…' );
		echo '</div></div>';

		echo '<div class="card"><h2>Activity</h2><p class="description">The last ' . count( $log ) . ' events.</p>';
		if ( $log ) {
			self::render_log( $log );
		} else {
			echo '<p class="msu-muted">Nothing yet.</p>';
		}
		echo '</div>';
	}

	/** Editable rows for a "a | b | c" setting. $cols = [label => placeholder]. */
	private static function render_rows_editor( $id, $name, $cols, $rows, $add_label ) {
		$cell = function ( $values ) use ( $name, $cols ) {
			$html = '<tr>';
			$i    = 0;
			foreach ( $cols as $label => $placeholder ) {
				$html .= '<td><input type="text" class="code" name="' . esc_attr( $name ) . '[' . $i . '][]" value="' . esc_attr( $values[ $i ] ?? '' ) . '" placeholder="' . esc_attr( $placeholder ) . '" aria-label="' . esc_attr( $label ) . '"></td>';
				$i++;
			}
			return $html . '<td class="msu-x"><button type="button" class="button-link button-link-delete">Remove</button></td></tr>';
		};
		echo '<table class="msu-table msu-rows-edit"><thead><tr>';
		foreach ( array_keys( $cols ) as $label ) {
			echo '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		echo '<th></th></tr></thead><tbody id="' . esc_attr( $id ) . '">';
		foreach ( $rows ?: [ [] ] as $values ) {
			echo $cell( $values ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in $cell.
		}
		echo '</tbody></table><template id="' . esc_attr( $id ) . '-tpl">' . $cell( [] ) . '</template>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<button type="button" class="button button-small" data-msu-add="' . esc_attr( $id ) . '" style="margin-top:8px">' . esc_html( $add_label ) . '</button>';
	}

	/** "a | b | c" lines => [[a, b, c], …]. */
	private static function split_lines( $text, $limit ) {
		$rows = [];
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			if ( '' !== trim( $line ) ) {
				$rows[] = array_map( 'trim', explode( '|', $line, $limit ) );
			}
		}
		return $rows;
	}

	public static function render_tab_settings() {
		$s       = self::settings();
		$markers = self::split_lines( $s['markers'], 2 );
		$has     = implode( ' ', array_column( $markers, 0 ) );

		echo '<div class="card"><h2>Suggest from the site</h2><p class="description">Fills the two lists below from the site itself: the home page and the pages of the main menu that load now, a form check on pages with a form, and the Elementor header and footer when the site has them. It replaces what is there.</p>';
		echo '<div class="msu-actions">';
		self::action_button( 'msu_suggest', 'Fill in from the site', [], 'button', 'Replace the pages and texts to check with suggestions from the site?', 'Looking at the site…' );
		echo '</div></div>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'msu_save_settings' );
		echo '<input type="hidden" name="action" value="msu_save_settings">';

		echo '<div class="card"><h2>Pages to check</h2><p class="description">Loaded after every update and twice a day. A page fails if it does not answer, shows a PHP error, or is missing the text you list (separate several with commas).</p>';
		self::render_rows_editor( 'msu-pages', 'pages', [ 'Name' => 'Contact', 'Path' => '/contact/', 'Must contain (optional)' => 'wpforms-form' ], self::split_lines( $s['pages'], 3 ), 'Add page' );
		echo '</div>';

		echo '<div class="card"><h2>Every page must contain</h2><p class="description">Bits of the page\'s HTML code. When one is missing, the page fails with your message.</p>';
		self::render_rows_editor( 'msu-markers', 'markers', [ 'Text in the HTML' => '</html>', 'Message when missing' => 'page is cut off' ], $markers, 'Add text' );
		if ( defined( 'ELEMENTOR_PRO_VERSION' ) && false === strpos( $has, 'elementor-location-header' ) ) {
			echo ' <button type="button" class="button button-small" data-msu-add="msu-markers" data-msu-rows="' . esc_attr( wp_json_encode( [ [ 'elementor-location-header', 'header missing' ], [ 'elementor-location-footer', 'footer missing' ] ] ) ) . '" style="margin-top:8px">Add Elementor header and footer</button>';
		}
		echo '</div>';

		echo '<div class="card"><h2>Alerts</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row"><label for="msu-email">Send alerts to</label></th><td><input id="msu-email" name="email" type="email" class="regular-text" value="' . esc_attr( $s['email'] ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="msu-label">Site name in subjects</label></th><td><input id="msu-label" name="label" type="text" class="regular-text" value="' . esc_attr( $s['label'] ) . '"><p class="description">Subjects look like <code>[' . esc_html( $s['label'] ?: wp_parse_url( home_url(), PHP_URL_HOST ) ) . '] Problem detected on the website</code></p></td></tr>';
		echo '</table></div>';

		$reg = get_option( 'msu_registry', [] );
		echo '<div class="card"><h2>A2 Studio monitoring</h2><p class="description">Once a day, this site sends its name, address and private report link to the A2 Studio watcher, so A2 Studio can watch it every 5 minutes and show it on the monitoring dashboard. A new site is monitored only once A2 Studio approves it.</p>';
		echo '<p><label><input type="checkbox" name="register" value="1"' . checked( ! empty( $s['register'] ), true, false ) . '> Sign up this site for monitoring</label></p>';
		if ( ! empty( $reg['time'] ) ) {
			echo '<p class="msu-muted">' . esc_html( ucfirst( self::registry_label( $reg['status'] ) ) ) . ' · last contact ' . self::ago( $reg['time'] ) . '</p>';
		}
		echo '</div>';

		echo '<div class="msu-form-actions">';
		submit_button( 'Save changes', 'primary', 'submit', false );
		echo '</div></form>';
	}

	public static function render_tab_monitoring() {
		echo '<div class="card"><h2>Update report link</h2><p class="description">For the A2 Studio monitoring dashboard. Anyone with this link can see the WordPress, theme and plugin versions of this site, nothing else, and it changes nothing.</p>';
		echo '<div class="msu-field"><input id="msu-report" type="text" readonly class="code" value="' . esc_attr( self::report_url() ) . '"><button type="button" class="button" data-msu-copy="msu-report">Copy</button></div>';
		echo '<div class="msu-actions">';
		self::action_button( 'msu_new_key', 'Make a new link', [], 'button-link button-link-delete', 'The current link will stop working right away. Continue?' );
		echo '<span class="msu-muted">Use this if the link was shared by mistake.</span></div></div>';

		echo '<div class="card"><h2>Status link</h2><p class="description">For the A2 Studio watcher, which checks it every 5 minutes and emails when the site goes down or comes back. Answers <code>200</code> while the page checks pass and <code>503</code> when they fail or stop running. The site signs up for this on its own (Settings tab).</p>';
		echo '<div class="msu-field"><input id="msu-status" type="text" readonly class="code" value="' . esc_attr( rest_url( 'egowebdesign/v1/status' ) ) . '"><button type="button" class="button" data-msu-copy="msu-status">Copy</button></div></div>';
	}

	/* ------------------------------------------------------------------ Admin actions */

	/** Back to the screen the action came from, with a message. */
	private static function back( $notice, $item = '' ) {
		$to = remove_query_arg( [ 'msu_notice', 'msu_item' ], wp_get_referer() ?: admin_url() );
		wp_safe_redirect( add_query_arg( array_filter( [ 'msu_notice' => $notice, 'msu_item' => rawurlencode( $item ) ] ), $to ) );
		exit;
	}

	public static function admin_notice() {
		if ( empty( $_GET['msu_notice'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}
		$item     = sanitize_text_field( wp_unslash( $_GET['msu_item'] ?? '' ) );
		$messages = [
			'saved'     => [ 'success', 'Settings saved.' ],
			'new-key'   => [ 'success', 'New report link made. The monitoring gets it within a few minutes.' ],
			'checks-ok' => [ 'success', 'Page checks pass.' ],
			'checks-ko' => [ 'error', 'Page checks found a problem. Details are in the Safe updates box.' ],
			'restored'  => [ 'success', "$item is back. Page checks pass." ],
			'restored-ko' => [ 'warning', "$item is back, but page checks still fail." ],
			'restore-failed' => [ 'error', "Could not put the previous version back: $item" ],
			'unblocked' => [ 'success', "$item can update automatically again." ],
			'snapshot-saved'  => [ 'success', 'Database snapshot saved.' ],
			'snapshot-failed' => [ 'error', "The database snapshot failed: $item" ],
			'db-restored'     => [ 'success', "Database put back as it was on $item. Page checks pass." ],
			'db-restored-ko'  => [ 'warning', "Database put back as it was on $item, but page checks still fail." ],
			'db-restore-failed' => [ 'error', "Could not put the database back: $item" ],
			'suggested'         => [ 'success', "Filled in from the site: $item pages to check. They all pass." ],
			'suggested-ko'      => [ 'warning', "Filled in from the site: $item pages to check, but a check fails. See the Overview tab." ],
			'security-clean'    => [ 'success', 'Security scan done: nothing suspicious.' ],
			'security-found'    => [ 'error', "Security scan done: $item to review. Details are in Settings > Safe updates." ],
			'security-reviewed' => [ 'success', 'Marked as reviewed. Those findings will not be reported again unless they change.' ],
		];
		$code = sanitize_key( $_GET['msu_notice'] );
		if ( isset( $messages[ $code ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $messages[ $code ][0] ) . ' is-dismissible"><p>' . esc_html( $messages[ $code ][1] ) . '</p></div>';
		}
	}

	public static function admin_save_settings() {
		check_admin_referer( 'msu_save_settings' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$in = wp_unslash( $_POST );
		// Rows from the editor => "a | b | c" lines. Markers are bits of HTML, kept as typed.
		$lines = function ( $cols, $required, $clean = null ) {
			$out  = [];
			$cols = (array) $cols;
			ksort( $cols );
			foreach ( $cols[ $required ] ?? [] as $i => $value ) {
				$row = [];
				foreach ( $cols as $c => $values ) {
					$row[ $c ] = trim( str_replace( [ "\r", "\n", '|' ], ' ', (string) ( $values[ $i ] ?? '' ) ) );
				}
				if ( '' === $row[ $required ] ) {
					continue;
				}
				$out[] = rtrim( implode( ' | ', $clean ? $clean( $row ) : $row ), ' |' );
			}
			return implode( "\n", $out );
		};
		update_option( 'msu_settings', [
			'email'   => sanitize_email( $in['email'] ?? '' ) ?: self::DEFAULT_EMAIL,
			'label'   => sanitize_text_field( $in['label'] ?? '' ),
			'pages'   => $lines( $in['pages'] ?? [], 1, function ( $row ) {
				$row[1] = '/' . ltrim( preg_replace( '#^https?://[^/]+#', '', $row[1] ), '/' );
				return $row;
			} ),
			'markers' => $lines( $in['markers'] ?? [], 0 ),
			'register' => empty( $in['register'] ) ? 0 : 1,
		], false );
		if ( ! empty( $in['register'] ) ) {
			self::schedule_register();
		}
		self::back( 'saved' );
	}

	public static function admin_suggest() {
		check_admin_referer( 'msu_suggest' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$s = self::suggest_checks();
		update_option( 'msu_settings', [ 'pages' => $s['pages'], 'markers' => $s['markers'] ] + self::settings(), false );
		self::log( 'Pages to check filled in from the site by ' . wp_get_current_user()->user_login . ': ' . implode( ', ', $s['names'] ) . '.' );
		self::back( self::run_checks()['ok'] ? 'suggested' : 'suggested-ko', (string) count( $s['names'] ) );
	}

	public static function admin_new_key() {
		check_admin_referer( 'msu_new_key' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		update_option( 'msu_report_key', wp_generate_password( 32, false ), false );
		self::schedule_register(); // The watcher and the dashboard get the new link.
		self::back( 'new-key' );
	}

	public static function admin_check_now() {
		check_admin_referer( 'msu_check_now' );
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'Not allowed.' );
		}
		self::back( self::run_checks()['ok'] ? 'checks-ok' : 'checks-ko' );
	}

	public static function admin_restore() {
		check_admin_referer( 'msu_restore' );
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'Not allowed.' );
		}
		$key    = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
		$copies = get_option( 'msu_copies', [] );
		$name   = isset( $copies[ $key ] ) ? "{$copies[ $key ]['name']} {$copies[ $key ]['version']}" : $key;
		$res    = self::restore_copy( $key );
		if ( is_wp_error( $res ) ) {
			self::back( 'restore-failed', $res->get_error_message() );
		}
		self::back( self::run_checks()['ok'] ? 'restored' : 'restored-ko', $name );
	}

	public static function admin_unblock() {
		check_admin_referer( 'msu_unblock' );
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( 'Not allowed.' );
		}
		$key     = sanitize_text_field( wp_unslash( $_POST['key'] ?? '' ) );
		$blocked = get_option( 'msu_blocked', [] );
		$name    = self::key_name( $key ) . ' ' . ( $blocked[ $key ] ?? '' );
		unset( $blocked[ $key ] );
		update_option( 'msu_blocked', $blocked, false );
		self::log( "$name allowed to update automatically again." );
		self::back( 'unblocked', $name );
	}

	public static function admin_snapshot_now() {
		check_admin_referer( 'msu_snapshot_now' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$res = self::take_snapshot( 'a manual request' );
		if ( is_wp_error( $res ) ) {
			self::back( 'snapshot-failed', $res->get_error_message() );
		}
		self::back( 'snapshot-saved' );
	}

	public static function admin_restore_db() {
		check_admin_referer( 'msu_restore_db' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$file = sanitize_file_name( wp_unslash( $_POST['file'] ?? '' ) );
		$snap = wp_list_filter( self::snapshots(), [ 'file' => $file ] );
		$when = $snap ? wp_date( 'Y-m-d H:i', reset( $snap )['time'] ) : $file;
		$res  = self::restore_snapshot( $file );
		if ( is_wp_error( $res ) ) {
			self::back( 'db-restore-failed', $res->get_error_message() );
		}
		self::back( self::run_checks()['ok'] ? 'db-restored' : 'db-restored-ko', $when );
	}

	public static function admin_security_scan() {
		check_admin_referer( 'msu_security_scan' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		$n = count( self::security_scan()['open'] ?? [] );
		self::back( $n ? 'security-found' : 'security-clean', 1 === $n ? '1 thing' : "$n things" );
	}

	public static function admin_security_review() {
		check_admin_referer( 'msu_security_review' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		self::security_review();
		self::back( 'security-reviewed' );
	}

	/* ------------------------------------------------------------------ Log and email */

	public static function log( $message ) {
		$log   = get_option( 'msu_log', [] );
		$log[] = [ time(), $message ];
		update_option( 'msu_log', array_slice( $log, -50 ), false );
	}

	/**
	 * Every email the plugin sends: the same card design as the watcher's, with a plain-text copy for mail apps without HTML.
	 * $m: tone (red | amber | green | blue), badge, title, lead,
	 *     facts  [[label, value]]                                   two-column table,
	 *     groups [[heading, [[title, detail], ...], mono], ...]     lists (mono: details are file paths),
	 *     facts_last (facts after the lists), note, button [label, url], footer.
	 */
	private static function mail_html( $subject, $m ) {
		list( $html, $text, $site ) = self::mail_parts( $m );
		$alt = function ( $mailer ) use ( $text ) { $mailer->AltBody = $text; };
		add_action( 'phpmailer_init', $alt );
		$sent = wp_mail( self::settings()['email'], "[$site] $subject", $html, [ 'Content-Type: text/html; charset=UTF-8' ] );
		remove_action( 'phpmailer_init', $alt );
		return $sent;
	}

	/** [html, plain text, site label] of an email, see mail_html(). */
	private static function mail_parts( $m ) {
		$s    = self::settings();
		$site = $s['label'] ?: wp_parse_url( home_url(), PHP_URL_HOST );
		$e    = function ( $v ) { return esc_html( (string) $v ); };
		$font = "font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;";
		list( $accent, $tint ) = [
			'red'   => [ '#C4320A', '#FEF3EE' ],
			'amber' => [ '#B54708', '#FFF8EB' ],
			'green' => [ '#067647', '#ECFDF3' ],
			'blue'  => [ '#3A3AFF', '#DEDEFF' ], // A2 Studio blue: news, nothing wrong.
		][ $m['tone'] ?? 'red' ];
		$text  = "{$m['title']}\n\n{$m['lead']}\n";
		$body  = '';
		$facts = function () use ( $m, $font, $e, &$body, &$text ) {
			if ( empty( $m['facts'] ) ) {
				return;
			}
			$body .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:20px;">';
			$text .= "\n";
			foreach ( $m['facts'] as $f ) {
				$body .= '<tr><td style="' . $font . 'padding:10px 0;border-top:1px solid #E2E2EC;color:#667085;font-size:13px;width:130px;vertical-align:top;">' . $e( $f[0] ) . '</td>'
					. '<td style="' . $font . 'padding:10px 0;border-top:1px solid #E2E2EC;color:#101013;font-size:14px;vertical-align:top;">' . $e( $f[1] ) . '</td></tr>';
				$text .= "{$f[0]}: {$f[1]}\n";
			}
			$body .= '</table>';
		};

		if ( empty( $m['facts_last'] ) ) {
			$facts();
		}
		foreach ( $m['groups'] ?? [] as $g ) {
			list( $heading, $items, $mono ) = array_pad( $g, 3, false );
			if ( ! $items ) {
				continue;
			}
			$body .= ( $heading ? '<div style="' . $font . 'margin:22px 0 2px;font-size:12px;font-weight:600;letter-spacing:.06em;text-transform:uppercase;color:#98A2B3;">' . $e( $heading ) . '</div>' : '<div style="height:20px;"></div>' )
				. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
			$text .= "\n" . ( $heading ? "$heading:\n" : '' );
			foreach ( $items as $i ) {
				$detail = '' === (string) ( $i[1] ?? '' ) ? '' : '<div style="' . ( $mono ? 'font-family:SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;word-break:break-all;' : $font . 'font-size:13px;' ) . 'line-height:1.5;color:#475467;padding-top:2px;">' . $e( $i[1] ) . '</div>';
				$body  .= '<tr><td style="' . $font . 'padding:10px 0;border-top:1px solid #E2E2EC;vertical-align:top;"><div style="font-size:14px;font-weight:600;color:#101013;">' . $e( $i[0] ) . '</div>' . $detail . '</td></tr>';
				$text  .= "- {$i[0]}" . ( '' === (string) ( $i[1] ?? '' ) ? '' : ": {$i[1]}" ) . "\n";
			}
			$body .= '</table>';
		}
		if ( ! empty( $m['facts_last'] ) ) {
			$facts();
		}
		if ( ! empty( $m['note'] ) ) {
			$body .= '<p style="' . $font . 'margin:16px 0 0;font-size:13px;line-height:1.5;color:#667085;">' . $e( $m['note'] ) . '</p>';
			$text .= "\n{$m['note']}\n";
		}
		if ( ! empty( $m['button'] ) ) {
			$body .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 0;"><tr><td style="border-radius:8px;background:' . $accent . ';">'
				. '<a href="' . esc_url( $m['button'][1] ) . '" style="' . $font . 'display:inline-block;padding:12px 20px;font-size:14px;font-weight:600;color:#FFFFFF;text-decoration:none;border-radius:8px;">' . $e( $m['button'][0] ) . ' &rarr;</a></td></tr></table>';
			$text .= "\n{$m['button'][0]}: {$m['button'][1]}\n";
		}
		$footer = $m['footer'] ?? 'Safe updates watches this site\'s updates, pages and security.';
		$icon   = plugins_url( 'assets/a2-icon-72.png', __FILE__ ) . '?v=' . self::VERSION; // Versioned: mail apps cache images by address.

		$html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"><title>' . $e( $m['title'] ) . '</title></head>'
			. '<body style="margin:0;padding:0;background:#F2F2F7;"><div style="display:none;max-height:0;overflow:hidden;">' . $e( $m['lead'] ) . '</div>'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F2F2F7;"><tr><td align="center" style="padding:32px 16px;">'
			. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">'
			. '<tr><td style="' . $font . 'padding:0 4px 14px;font-size:13px;color:#475467;"><img src="' . esc_url( $icon ) . '" width="24" height="24" alt="A2 Studio" style="display:inline-block;width:24px;height:24px;border:0;vertical-align:middle;margin-right:8px;">'
			. '<span style="vertical-align:middle;font-weight:700;color:#101013;">Safe updates</span><span style="vertical-align:middle;"> &nbsp;·&nbsp; ' . $e( $site ) . '</span></td></tr>'
			. '<tr><td style="background:#FFFFFF;border:1px solid #E2E2EC;border-radius:14px;overflow:hidden;"><table role="presentation" width="100%" cellpadding="0" cellspacing="0">'
			. '<tr><td style="height:4px;background:' . $accent . ';font-size:0;line-height:0;">&nbsp;</td></tr><tr><td style="padding:28px 32px 28px;">'
			. '<span style="' . $font . 'display:inline-block;padding:4px 10px;border-radius:999px;background:' . $tint . ';color:' . $accent . ';font-size:12px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;">' . $e( $m['badge'] ) . '</span>'
			. '<h1 style="' . $font . 'margin:14px 0 8px;font-size:24px;line-height:1.25;font-weight:700;color:#101013;">' . $e( $m['title'] ) . '</h1>'
			. '<p style="' . $font . 'margin:0;font-size:15px;line-height:1.55;color:#344054;">' . $e( $m['lead'] ) . '</p>'
			. $body
			. '</td></tr></table></td></tr>'
			. '<tr><td style="' . $font . 'padding:16px 8px 0;font-size:12px;line-height:1.5;color:#98A2B3;text-align:center;">' . $e( home_url( '/' ) ) . ' · ' . $e( $footer ) . '<br>Safe updates by <span style="color:#5B5B6B;font-weight:600;">A2 Studio</span></td></tr>'
			. '</table></td></tr></table></body></html>';

		return [ $html, $text, $site ];
	}

	/** WordPress's email about automatic plugin (or theme) updates: only when one failed or one was a big release. */
	public static function send_update_email( $send, $results ) {
		foreach ( $results as $r ) {
			if ( ! $r->result || is_wp_error( $r->result ) || ( ! empty( $r->item->current_version ) && self::is_major( $r->item->current_version, $r->item->new_version ) ) ) {
				return $send;
			}
		}
		return false;
	}

	/** WordPress's own email after automatic plugin/theme updates, in the same design. It is sent before the page checks run. */
	public static function plugin_theme_update_email( $email, $type, $successful, $failed ) {
		$ok     = count( $successful['plugin'] ?? [] ) + count( $successful['theme'] ?? [] );
		$ko     = count( $failed['plugin'] ?? [] ) + count( $failed['theme'] ?? [] );
		$groups = [];
		foreach ( [ 'plugin' => 'Plugins', 'theme' => 'Themes' ] as $kind => $label ) {
			$groups[] = [ "$label updated", array_map( function ( $i ) {
				return [ html_entity_decode( $i->name ), ( empty( $i->item->current_version ) ? '' : "{$i->item->current_version} → " ) . $i->item->new_version ];
			}, $successful[ $kind ] ?? [] ) ];
		}
		foreach ( [ 'plugin' => 'Plugins', 'theme' => 'Themes' ] as $kind => $label ) {
			$groups[] = [ "$label not updated", array_map( function ( $i ) {
				return [ html_entity_decode( $i->name ), ( empty( $i->item->current_version ) ? '' : "Still on {$i->item->current_version}, " ) . "{$i->item->new_version} could not be installed" ];
			}, $failed[ $kind ] ?? [] ) ];
		}
		$installed = 1 === $ok ? '1 update installed' : "$ok updates installed";
		$failures  = 1 === $ko ? '1 update failed' : "$ko updates failed";
		$snap      = self::snapshots()[0] ?? null;
		$m         = [
			'groups' => $groups,
			'facts'  => $ok ? [ [ 'Database snapshot', $snap ? self::email_date( $snap['time'] ) : 'none found' ] ] : [],
			'facts_last' => true,
			'button' => [ 'Open Updates', admin_url( 'update-core.php' ) ],
		];
		if ( 'success' === $type ) {
			$m += [ 'tone' => 'green', 'badge' => 'Updated', 'title' => $installed, 'lead' => 'WordPress installed these updates automatically. Safe updates now checks the pages and emails you right away if something broke.' ];
		} elseif ( 'fail' === $type ) {
			$m += [ 'tone' => 'red', 'badge' => 'Update failed', 'title' => $failures, 'lead' => 'WordPress tried to install these updates automatically and could not. The previous versions are still in place; WordPress tries again later, or you can update from Dashboard > Updates.' ];
		} else {
			$m += [ 'tone' => 'amber', 'badge' => 'Partly updated', 'title' => "$installed, $ko failed", 'lead' => 'WordPress installed some updates automatically, others could not be installed (their previous versions are still in place). Safe updates now checks the pages and emails you right away if something broke.' ];
		}
		return self::restyle_wp_email( $email, $m['title'], $m );
	}

	/** WordPress's own email about a core update (installed, failed, needs a manual update, or broke midway), in the same design. */
	public static function core_update_email( $email, $type, $core_update, $result ) {
		$next  = get_preferred_from_update_core() ?: $core_update;
		$v     = version_compare( $next->current, $core_update->current, '>' ) ? $next->current : $core_update->current; // Like WordPress: a newer release than the one tried, if any.
		$facts = is_wp_error( $result ) ? [ [ 'Error', $result->get_error_message() ] ] : [];
		$m     = [
			'success'  => [ 'green', 'Updated', "WordPress $v installed", "The site was updated automatically to WordPress $v. Safe updates now checks the pages and emails you right away if something broke." ],
			'fail'     => [ 'amber', 'Update failed', "WordPress $v could not be installed", 'WordPress tried to update automatically and could not, so the site is still on WordPress ' . get_bloginfo( 'version' ) . '. It tries again later, or you can update from Dashboard > Updates.' ],
			'manual'   => [ 'blue', 'Update available', "WordPress $v is available", 'This site cannot install it automatically. Update from Dashboard > Updates.' ],
			'critical' => [ 'red', 'Urgent', 'The site may be down after a failed WordPress update', "The update to WordPress $v started copying files, then failed. Someone needs to look at the site now." ],
		][ $type ] ?? null;
		if ( ! $m ) {
			return $email;
		}
		return self::restyle_wp_email( $email, $m[2], [
			'tone'   => $m[0],
			'badge'  => $m[1],
			'title'  => $m[2],
			'lead'   => $m[3],
			'facts'  => $facts,
			'button' => [ 'Open Updates', admin_url( 'update-core.php' ) ],
		] );
	}

	/** Swaps a WordPress email's subject and body for ours; the recipient stays WordPress's. */
	private static function restyle_wp_email( $email, $subject, $m ) {
		list( $html, $text, $site ) = self::mail_parts( $m );
		$alt = function ( $mailer ) use ( $text, &$alt ) {
			$mailer->AltBody = $text;
			remove_action( 'phpmailer_init', $alt );
		};
		add_action( 'phpmailer_init', $alt );
		return array_merge( $email, [
			'subject' => "[$site] $subject",
			'body'    => $html,
			'headers' => [ 'Content-Type: text/html; charset=UTF-8' ],
		] );
	}

	/** The same alert at most once a day. */
	private static function alert_once( $id, $subject, $m ) {
		$key = 'msu_alert_' . $id;
		if ( ! get_transient( $key ) ) {
			set_transient( $key, 1, DAY_IN_SECONDS );
			self::log( $subject );
			self::mail_html( $subject, $m );
		}
	}

	/** Dates in emails: the site's time zone, always in English like the rest of the email (wp_date() would translate the day names). */
	private static function email_date( $t, $format = 'M j, H:i' ) {
		return ( new DateTime( '@' . (int) $t ) )->setTimezone( wp_timezone() )->format( $format );
	}

	/** [[page, what fails]] for an email list. */
	private static function failure_items( $failures ) {
		return array_map( function ( $page, $why ) { return [ $page, ucfirst( $why ) ]; }, array_keys( $failures ), $failures );
	}

	/** [[name, from → to]] for an email list. */
	private static function update_items( $updated, $back = false ) {
		return array_map( function ( $u ) use ( $back ) {
			return [ $u['name'], $u['from'] ? ( $back ? "back to {$u['from']} (from {$u['to']})" : "{$u['from']} → {$u['to']}" ) : $u['to'] ];
		}, $updated );
	}
}

add_action( 'plugins_loaded', [ 'EgoWebDesign_Safe_Updates', 'init' ], 0 );
