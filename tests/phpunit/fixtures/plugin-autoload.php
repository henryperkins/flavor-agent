<?php

declare(strict_types=1);

use FlavorAgent\Activity\PersistenceAssurance;
use FlavorAgent\Activity\PersistenceSaveObserver;
use FlavorAgent\Activity\PersistenceWorker;
use FlavorAgent\Tests\Support\WordPressTestState;

define( 'FLAVOR_AGENT_TESTS_RUNNING', 1 );
require dirname( __DIR__ ) . '/bootstrap.php';

$plugin_root = dirname( __DIR__, 3 );
$loader      = require $plugin_root . '/vendor/autoload.php';
$loader->unregister();
$loader = new \Composer\Autoload\ClassLoader();
$loader->setPsr4( 'FlavorAgent\\', [ $plugin_root . '/inc' ] );
$loader->register();
$mode = $argv[1] ?? 'normal';

if ( class_exists( PersistenceSaveObserver::class, false ) ) {
	throw new RuntimeException( 'The observer must not be loaded before the plugin bootstrap.' );
}

if ( 'authoritative' === $mode ) {
	// Simulate release metadata that has not picked up these new classes.
	$classmap = [];
	$files    = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $plugin_root . '/inc', FilesystemIterator::SKIP_DOTS ) );
	foreach ( $files as $file ) {
		if ( 'php' !== $file->getExtension() ) {
			continue;
		}
		$relative = substr( $file->getPathname(), strlen( $plugin_root . '/inc/' ), -4 );
		$class    = 'FlavorAgent\\' . str_replace( '/', '\\', $relative );
		if ( ! in_array( $class, [ PersistenceSaveObserver::class, PersistenceWorker::class, PersistenceAssurance::class ], true ) ) {
			$classmap[ $class ] = $file->getPathname();
		}
	}
	$loader->addClassMap( $classmap );
	$loader->setClassMapAuthoritative( true );
} elseif ( 'cached-miss' === $mode ) {
	$loader->setPsr4( 'FlavorAgent\\', [] );
	$loader->findFile( PersistenceSaveObserver::class );
	$loader->setPsr4( 'FlavorAgent\\', [ $plugin_root . '/inc' ] );
} elseif ( 'normal' !== $mode ) {
	throw new RuntimeException( 'Unknown autoload test mode.' );
}

$composer_miss = false === $loader->findFile( PersistenceSaveObserver::class );

require $plugin_root . '/flavor-agent.php';

$save_hook_registered = false;
foreach ( WordPressTestState::$filters['wp_after_insert_post'][20] ?? [] as $hook ) {
	if ( [ PersistenceSaveObserver::class, 'after_save' ] === $hook['callback'] && 4 === $hook['accepted_args'] ) {
		$save_hook_registered = is_callable( $hook['callback'] );
	}
}

$worker_hook_callable = false;
foreach ( WordPressTestState::$filters['flavor_agent_verify_saved_applies'][10] ?? [] as $hook ) {
	if ( [ PersistenceWorker::class, 'run' ] === $hook['callback'] ) {
		$worker_hook_callable = is_callable( $hook['callback'] );
	}
}

echo json_encode(
	[
		'composerMiss'       => $composer_miss,
		'saveHookRegistered' => $save_hook_registered,
		'workerHookCallable' => $worker_hook_callable,
		'lateClassLoadable'  => class_exists( PersistenceAssurance::class ),
	],
	JSON_THROW_ON_ERROR
);
