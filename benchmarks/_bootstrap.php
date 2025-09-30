<?php
/**
 * PhpBench bootstrap for modern MediaWiki.
 * - Loads LocalSettings.php via MW_CONFIG_CALLBACK
 * - forces SQLite for the bench if MW_BENCH_SQLITE=1
 * - Boots MW
 */
$IP = getenv( 'MW_INSTALL_PATH' ) ?: dirname( __DIR__, 3 );
if ( !defined( 'MW_INSTALL_PATH' ) ) {
	define( 'MW_INSTALL_PATH', $IP );
}
if ( !defined( 'MEDIAWIKI' ) ) {
	define( 'MEDIAWIKI', true );
}

// Minimal server vars some code paths expect
$_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'] ?? 'phpbench';

// Deterministic, writable temp dir
define( 'MW_BENCH_TMPDIR', MW_INSTALL_PATH . '/tmp/phpbench' );
if ( !is_dir( MW_BENCH_TMPDIR ) ) {
	@mkdir( MW_BENCH_TMPDIR, 0777, true );
}

define( 'MW_CONFIG_CALLBACK', static function () {
	require MW_INSTALL_PATH . '/LocalSettings.php';

	if ( getenv( 'MATH_BENCH_WAN' ) === 'memcached' ) {
		$GLOBALS['wgMainCacheType'] = CACHE_MEMCACHED;
		$GLOBALS['wgParserCacheType'] = CACHE_MEMCACHED;
		$GLOBALS['wgMessageCacheType'] = CACHE_MEMCACHED;
		$GLOBALS['wgSessionsInObjectCache'] = true;
		$GLOBALS['wgSessionCacheType'] = CACHE_MEMCACHED;
		$GLOBALS['wgMemCachedServers'] = [ '127.0.0.1:11211' ];
		$GLOBALS['wgWANObjectCaches']['bench-memc'] = [
			'class'   => \Wikimedia\ObjectCache\WANObjectCache::class,
			'cacheId' => CACHE_MEMCACHED,
		];
		$GLOBALS['wgMainWANCache'] = 'bench-memc';
	}
	if ( getenv( 'MATH_BENCH_WAN' ) === 'hash' ) {
		$GLOBALS['wgWANObjectCaches']['bench-hash'] = [
			'class'   => \Wikimedia\ObjectCache\WANObjectCache::class,
			'cacheId' => CACHE_HASH,
		];
		$GLOBALS['wgMainWANCache'] = 'bench-hash';
	}

	// Provide CLI defaults if missing
	global $wgServer, $wgTmpDirectory;
	if ( empty( $wgServer ) ) {
		$wgServer = 'http://localhost';
	}
	if ( empty( $wgTmpDirectory ) ) {
		$wgTmpDirectory = MW_BENCH_TMPDIR;
	}

	// force SQLite for this bench run (avoids MySQL driver requirement)
	if ( !empty( getenv( 'MW_BENCH_SQLITE' ) ) ) {
		global $wgDBtype, $wgDBname, $wgDBprefix, $wgSQLiteDataDir;
		$wgDBtype = 'sqlite';
		$wgDBname = 'bench.sqlite';
		$wgDBprefix = '';
		$wgSQLiteDataDir = MW_BENCH_TMPDIR;
	}
} );

// Bootstrap MediaWiki
require_once MW_INSTALL_PATH . '/includes/WebStart.php';

// Math must be loaded
if ( !\ExtensionRegistry::getInstance()->isLoaded( 'Math' ) ) {
	fwrite( STDERR, "Math extension not loaded. Add wfLoadExtension('Math') to LocalSettings.php\n" );
	exit( 1 );
}
