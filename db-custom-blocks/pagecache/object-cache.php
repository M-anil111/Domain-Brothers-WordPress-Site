<?php
/**
 * Plugin Name: Domain Brothers object cache (APCu)
 * Description: Persistent object cache in PHP shared memory (APCu). Installed by Domain Brothers Custom Blocks; delete this file to turn it off.
 *
 * Keeps WordPress's object cache between requests in APCu, so options, posts,
 * terms and query results are not read from the database on every request.
 *
 * Safety:
 *   - Keys are prefixed per site (database name, table prefix, install path)
 *     and per generation; a flush moves to a new generation, so no stale entry
 *     can be read after it.
 *   - Requests where APCu is off (WP-CLI usually runs with apc.enable_cli=0)
 *     keep a per-request cache only. If they change data they touch a flag
 *     file, and the next web request flushes the shared cache, so a change
 *     made outside the web server never leaves stale data behind.
 *   - Values are copied on read and write, as WordPress's own cache does.
 *
 * @package DomainBrothers
 */

defined( 'ABSPATH' ) || exit;

define( 'DB_OBJECT_CACHE_DROPIN', 1 );

class WP_Object_Cache {

	/** @var array<string,array<string,mixed>> Per-request copy. */
	private $cache = array();

	/** @var array<string,bool> */
	private $global_groups = array();

	/** @var array<string,bool> */
	private $non_persistent_groups = array();

	/** @var array<string,int> Group generations read this request. */
	private $group_gens = array();

	/** @var bool */
	private $persistent;

	/** @var string */
	private $site;

	/** @var int */
	private $gen = 1;

	/** @var int */
	private $blog_prefix = 1;

	/** @var bool */
	private $dirty_without_apcu = false;

	/** @var int */
	public $cache_hits = 0;

	/** @var int */
	public $cache_misses = 0;

	/** Default lifetime of entries stored without an expiry (6 hours). */
	const DEFAULT_TTL = 21600;

	public function __construct() {
		global $table_prefix, $blog_id;
		$this->persistent  = function_exists( 'apcu_enabled' ) && apcu_enabled();
		$this->site        = 'dboc:' . substr( md5( ABSPATH . '|' . ( defined( 'DB_NAME' ) ? DB_NAME : '' ) . '|' . (string) $table_prefix ), 0, 12 );
		$this->blog_prefix = is_multisite() ? (int) $blog_id : 1;
		if ( $this->persistent ) {
			$g         = apcu_fetch( $this->site . ':gen' );
			$this->gen = is_int( $g ) && $g > 0 ? $g : 1;
			if ( ! is_int( $g ) ) {
				apcu_add( $this->site . ':gen', 1 );
			}
			// A change made where APCu was off (WP-CLI) asks for a flush.
			$flag = self::flag_file();
			$mt   = @filemtime( $flag ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( $mt ) {
				$seen = apcu_fetch( $this->site . ':flag' );
				if ( ! is_int( $seen ) || $seen < $mt ) {
					apcu_store( $this->site . ':flag', $mt );
					$this->bump_generation();
				}
			}
		}
	}

	/** Flag file touched by requests that changed data without APCu. */
	public static function flag_file() {
		return WP_CONTENT_DIR . '/cache/db-object-cache.flag';
	}

	private function bump_generation() {
		if ( ! $this->persistent ) {
			return;
		}
		$n = apcu_inc( $this->site . ':gen' );
		if ( false === $n ) {
			$n = $this->gen + 1;
			apcu_store( $this->site . ':gen', $n );
		}
		$this->gen        = (int) $n;
		$this->group_gens = array();
	}

	private function group_gen( $group ) {
		if ( ! isset( $this->group_gens[ $group ] ) ) {
			$v                          = apcu_fetch( $this->site . ':' . $this->gen . ':ggen:' . $group );
			$this->group_gens[ $group ] = is_int( $v ) ? $v : 0;
		}
		return $this->group_gens[ $group ];
	}

	private function norm_group( $group ) {
		return ( '' === (string) $group ) ? 'default' : (string) $group;
	}

	private function is_persistent( $group ) {
		return $this->persistent && ! isset( $this->non_persistent_groups[ $group ] );
	}

	private function local_key( $key, $group ) {
		return ( isset( $this->global_groups[ $group ] ) ? '' : $this->blog_prefix . ':' ) . $key;
	}

	private function apcu_key( $key, $group ) {
		return $this->site . ':' . $this->gen . ':' . $group . ':' . $this->group_gen( $group ) . ':' . $this->local_key( $key, $group );
	}

	private function valid_key( $key ) {
		if ( is_int( $key ) ) {
			return true;
		}
		if ( is_string( $key ) && '' !== trim( $key ) ) {
			return true;
		}
		return false;
	}

	private static function copy( $value ) {
		return is_object( $value ) ? clone $value : $value;
	}

	private function mark_dirty( $group ) {
		if ( ! $this->persistent && ! isset( $this->non_persistent_groups[ $group ] ) ) {
			$this->dirty_without_apcu = true;
		}
	}

	public function add( $key, $data, $group = 'default', $expire = 0 ) {
		if ( function_exists( 'wp_suspend_cache_addition' ) && wp_suspend_cache_addition() ) {
			return false;
		}
		if ( ! $this->valid_key( $key ) ) {
			return false;
		}
		$group = $this->norm_group( $group );
		$found = false;
		$this->get( $key, $group, false, $found );
		if ( $found ) {
			return false;
		}
		return $this->set( $key, $data, $group, $expire );
	}

	public function add_multiple( array $data, $group = '', $expire = 0 ) {
		$out = array();
		foreach ( $data as $k => $v ) {
			$out[ $k ] = $this->add( $k, $v, $group, $expire );
		}
		return $out;
	}

	public function replace( $key, $data, $group = 'default', $expire = 0 ) {
		if ( ! $this->valid_key( $key ) ) {
			return false;
		}
		$group = $this->norm_group( $group );
		$found = false;
		$this->get( $key, $group, false, $found );
		if ( ! $found ) {
			return false;
		}
		return $this->set( $key, $data, $group, $expire );
	}

	public function set( $key, $data, $group = 'default', $expire = 0 ) {
		if ( ! $this->valid_key( $key ) ) {
			return false;
		}
		$group                                                    = $this->norm_group( $group );
		$this->cache[ $group ][ $this->local_key( $key, $group ) ] = self::copy( $data );
		if ( $this->is_persistent( $group ) ) {
			$ttl = (int) $expire > 0 ? (int) $expire : self::DEFAULT_TTL;
			apcu_store( $this->apcu_key( $key, $group ), self::copy( $data ), $ttl );
		} else {
			$this->mark_dirty( $group );
		}
		return true;
	}

	public function set_multiple( array $data, $group = '', $expire = 0 ) {
		$out = array();
		foreach ( $data as $k => $v ) {
			$out[ $k ] = $this->set( $k, $v, $group, $expire );
		}
		return $out;
	}

	public function get( $key, $group = 'default', $force = false, &$found = null ) {
		$found = false;
		if ( ! $this->valid_key( $key ) ) {
			return false;
		}
		$group = $this->norm_group( $group );
		$lk    = $this->local_key( $key, $group );
		if ( ! $force && isset( $this->cache[ $group ] ) && array_key_exists( $lk, $this->cache[ $group ] ) ) {
			$found = true;
			++$this->cache_hits;
			return self::copy( $this->cache[ $group ][ $lk ] );
		}
		if ( $this->is_persistent( $group ) ) {
			$ok    = false;
			$value = apcu_fetch( $this->apcu_key( $key, $group ), $ok );
			if ( $ok ) {
				$found                         = true;
				$this->cache[ $group ][ $lk ] = $value;
				++$this->cache_hits;
				return self::copy( $value );
			}
		}
		++$this->cache_misses;
		return false;
	}

	public function get_multiple( $keys, $group = 'default', $force = false ) {
		$out   = array();
		$group = $this->norm_group( $group );
		$fetch = array();
		foreach ( (array) $keys as $key ) {
			if ( ! $this->valid_key( $key ) ) {
				$out[ $key ] = false;
				continue;
			}
			$lk = $this->local_key( $key, $group );
			if ( ! $force && isset( $this->cache[ $group ] ) && array_key_exists( $lk, $this->cache[ $group ] ) ) {
				++$this->cache_hits;
				$out[ $key ] = self::copy( $this->cache[ $group ][ $lk ] );
			} elseif ( $this->is_persistent( $group ) ) {
				$fetch[ $this->apcu_key( $key, $group ) ] = $key;
				$out[ $key ]                               = false;
			} else {
				++$this->cache_misses;
				$out[ $key ] = false;
			}
		}
		if ( $fetch ) {
			$got = apcu_fetch( array_keys( $fetch ) );
			$got = is_array( $got ) ? $got : array();
			foreach ( $fetch as $ak => $key ) {
				if ( array_key_exists( $ak, $got ) ) {
					++$this->cache_hits;
					$this->cache[ $group ][ $this->local_key( $key, $group ) ] = $got[ $ak ];
					$out[ $key ] = self::copy( $got[ $ak ] );
				} else {
					++$this->cache_misses;
				}
			}
		}
		return $out;
	}

	public function delete( $key, $group = 'default', $deprecated = false ) {
		if ( ! $this->valid_key( $key ) ) {
			return false;
		}
		$group = $this->norm_group( $group );
		$lk    = $this->local_key( $key, $group );
		$had   = isset( $this->cache[ $group ] ) && array_key_exists( $lk, $this->cache[ $group ] );
		unset( $this->cache[ $group ][ $lk ] );
		if ( $this->is_persistent( $group ) ) {
			return apcu_delete( $this->apcu_key( $key, $group ) ) || $had;
		}
		$this->mark_dirty( $group );
		return $had;
	}

	public function delete_multiple( array $keys, $group = '' ) {
		$out = array();
		foreach ( $keys as $k ) {
			$out[ $k ] = $this->delete( $k, $group );
		}
		return $out;
	}

	public function incr( $key, $offset = 1, $group = 'default' ) {
		$group = $this->norm_group( $group );
		$found = false;
		$value = $this->get( $key, $group, false, $found );
		if ( ! $found ) {
			return false;
		}
		$value = is_numeric( $value ) ? (int) $value : 0;
		$value = max( 0, $value + (int) $offset );
		$this->set( $key, $value, $group );
		return $value;
	}

	public function decr( $key, $offset = 1, $group = 'default' ) {
		return $this->incr( $key, -1 * (int) $offset, $group );
	}

	public function flush() {
		$this->cache = array();
		if ( $this->persistent ) {
			$this->bump_generation();
		} else {
			$this->dirty_without_apcu = true;
		}
		return true;
	}

	public function flush_runtime() {
		$this->cache = array();
		return true;
	}

	public function flush_group( $group ) {
		$group = $this->norm_group( $group );
		unset( $this->cache[ $group ] );
		if ( $this->is_persistent( $group ) ) {
			$k = $this->site . ':' . $this->gen . ':ggen:' . $group;
			$n = apcu_inc( $k );
			if ( false === $n ) {
				$n = $this->group_gen( $group ) + 1;
				apcu_store( $k, $n );
			}
			$this->group_gens[ $group ] = (int) $n;
		} else {
			$this->mark_dirty( $group );
		}
		return true;
	}

	public function add_global_groups( $groups ) {
		foreach ( (array) $groups as $g ) {
			$this->global_groups[ (string) $g ] = true;
		}
	}

	public function add_non_persistent_groups( $groups ) {
		foreach ( (array) $groups as $g ) {
			$this->non_persistent_groups[ (string) $g ] = true;
		}
	}

	public function switch_to_blog( $blog_id ) {
		$this->blog_prefix = is_multisite() ? (int) $blog_id : 1;
	}

	public function close() {
		if ( $this->dirty_without_apcu ) {
			$flag = self::flag_file();
			if ( ! is_dir( dirname( $flag ) ) ) {
				@mkdir( dirname( $flag ), 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			}
			@touch( $flag ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$this->dirty_without_apcu = false;
		}
		return true;
	}

	public function stats() {
		echo '<p><strong>Cache hits:</strong> ' . (int) $this->cache_hits . '<br><strong>Cache misses:</strong> ' . (int) $this->cache_misses . '<br><strong>Persistent (APCu):</strong> ' . ( $this->persistent ? 'yes' : 'no' ) . '</p>';
	}

	public function is_persistent_store() {
		return $this->persistent;
	}
}

function wp_cache_init() {
	$GLOBALS['wp_object_cache'] = new WP_Object_Cache();
}

function wp_cache_add( $key, $data, $group = '', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->add( $key, $data, $group, (int) $expire );
}

function wp_cache_add_multiple( array $data, $group = '', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->add_multiple( $data, $group, (int) $expire );
}

function wp_cache_replace( $key, $data, $group = '', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->replace( $key, $data, $group, (int) $expire );
}

function wp_cache_set( $key, $data, $group = '', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->set( $key, $data, $group, (int) $expire );
}

function wp_cache_set_multiple( array $data, $group = '', $expire = 0 ) {
	return $GLOBALS['wp_object_cache']->set_multiple( $data, $group, (int) $expire );
}

function wp_cache_get( $key, $group = '', $force = false, &$found = null ) {
	return $GLOBALS['wp_object_cache']->get( $key, $group, $force, $found );
}

function wp_cache_get_multiple( $keys, $group = '', $force = false ) {
	return $GLOBALS['wp_object_cache']->get_multiple( $keys, $group, $force );
}

function wp_cache_delete( $key, $group = '' ) {
	return $GLOBALS['wp_object_cache']->delete( $key, $group );
}

function wp_cache_delete_multiple( array $keys, $group = '' ) {
	return $GLOBALS['wp_object_cache']->delete_multiple( $keys, $group );
}

function wp_cache_incr( $key, $offset = 1, $group = '' ) {
	return $GLOBALS['wp_object_cache']->incr( $key, $offset, $group );
}

function wp_cache_decr( $key, $offset = 1, $group = '' ) {
	return $GLOBALS['wp_object_cache']->decr( $key, $offset, $group );
}

function wp_cache_flush() {
	return $GLOBALS['wp_object_cache']->flush();
}

function wp_cache_flush_runtime() {
	return $GLOBALS['wp_object_cache']->flush_runtime();
}

function wp_cache_flush_group( $group ) {
	return $GLOBALS['wp_object_cache']->flush_group( $group );
}

function wp_cache_supports( $feature ) {
	return in_array( $feature, array( 'add_multiple', 'set_multiple', 'get_multiple', 'delete_multiple', 'flush_runtime', 'flush_group' ), true );
}

function wp_cache_close() {
	return $GLOBALS['wp_object_cache']->close();
}

function wp_cache_add_global_groups( $groups ) {
	$GLOBALS['wp_object_cache']->add_global_groups( $groups );
}

function wp_cache_add_non_persistent_groups( $groups ) {
	$GLOBALS['wp_object_cache']->add_non_persistent_groups( $groups );
}

function wp_cache_switch_to_blog( $blog_id ) {
	$GLOBALS['wp_object_cache']->switch_to_blog( $blog_id );
}

function wp_cache_reset() {
	_deprecated_function( __FUNCTION__, '3.5.0', 'wp_cache_switch_to_blog()' );
	return false;
}
