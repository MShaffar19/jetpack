<?php
/* HEADER */ // phpcs:ignore

/**
 * This class selects the package version for the autoloader.
 */
class Autoloader_Handler {

	// The name of the autoloader function registered by v1.* autoloaders.
	const V1_AUTOLOADER_NAME = 'Automattic\Jetpack\Autoloader\autoloader';

	/*
	 * The autoloader function for v2.* autoloaders is named __NAMESPACE__ . \autoloader.
	 * The namespace is defined in AutoloadGenerator as
	 * 'Automattic\Jetpack\Autoloader\jp' plus a unique suffix.
	 */
	const V2_AUTOLOADER_BASE = 'Automattic\Jetpack\Autoloader\jp';

	const AUTOLOAD_GENERATOR_CLASS_NAME = 'Automattic\Jetpack\Autoloader\AutoloadGenerator';

	/**
	 * The Plugins_Handler object.
	 *
	 * @var Plugins_Handler
	 */
	private $plugins_handler = null;

	/**
	 * The Version_Selector object.
	 *
	 * @var Version_Selector
	 */
	private $version_selector = null;

	/**
	 * The constructor.
	 *
	 * @param Plugins_Handler  $plugins_handler The Plugins_Handler object.
	 * @param Version_Selector $version_selector The Version_Selector object.
	 */
	public function __construct( $plugins_handler, $version_selector ) {
		$this->plugins_handler  = $plugins_handler;
		$this->version_selector = $version_selector;
	}

	/**
	 * Finds the latest installed autoloader.
	 */
	public function find_latest_autoloader() {
		global $jetpack_autoloader_latest_version;

		$current_autoloader_path = trailingslashit( dirname( __FILE__ ) ) . 'autoload_packages.php';

		$selected_autoloader_version = null;
		$selected_autoloader_path    = null;

		$active_plugins = $this->plugins_handler->get_all_active_plugins();

		foreach ( $active_plugins as $plugin ) {
			$plugin_path   = trailingslashit( WP_PLUGIN_DIR ) . $plugin;
			$classmap_path = trailingslashit( $plugin_path ) . 'vendor/composer/jetpack_autoload_classmap.php';

			if ( file_exists( $classmap_path ) ) {
				$packages = require $classmap_path;

				$compare_version = $packages[ self::AUTOLOAD_GENERATOR_CLASS_NAME ]['version'];
				$compare_path    = trailingslashit( $plugin_path ) . 'vendor/autoload_packages.php';

				if ( $this->version_selector->is_version_update_required( $selected_autoloader_version, $compare_version ) ) {
					$selected_autoloader_version = $compare_version;
					$selected_autoloader_path    = $compare_path;
				}
			}
		}

		if ( ! $selected_autoloader_version || ! $selected_autoloader_path ) {
			// Something's wrong with the selected version or path, so just use this directory's autoloader.
			// This would happen if Jetpack is loaded a mu-plugin and no other Jetpack ecosystem plugins are installed.
			$selected_autoloader_version = $this->get_current_autoloader_version();
			$selected_autoloader_path    = $current_autoloader_path;
		}

		$jetpack_autoloader_latest_version = $selected_autoloader_version;
		if ( $current_autoloader_path !== $selected_autoloader_path ) {
			require $selected_autoloader_path;
		}
	}

	/**
	 * Get this autoloader's package version.
	 *
	 * @return String The autoloader's package version.
	 */
	public function get_current_autoloader_version() {
		$classmap_file       = trailingslashit( dirname( __FILE__ ) ) . 'composer/jetpack_autoload_classmap.php';
		$autoloader_packages = require $classmap_file;

		return $autoloader_packages[ self::AUTOLOAD_GENERATOR_CLASS_NAME ]['version'];
	}


	/**
	 * Updates the spl autoloader chain:
	 *  - Registers this namespace's autoloader function.
	 *  - If a v1 autoloader function is registered, moves it to the end of the chain.
	 *  - Removes any other v2 autoloader functions that have already been registered. This
	 *    can occur when the autoloader is being reset by an activating plugin.
	 */
	public function update_autoloader_chain() {
		spl_autoload_register( __NAMESPACE__ . '\autoloader' );

		$autoload_chain = spl_autoload_functions();

		foreach ( $autoload_chain as $autoloader ) {
			if ( ! is_string( $autoloader ) ) {
				/*
				 * The Jetpack Autoloader functions are registered as strings, so
				 * just continue if $autoloader isn't a string.
				 */
				continue;
			}

			if ( self::V1_AUTOLOADER_NAME === $autoloader ) {
				// Move the v1.* autoloader function to the end of the spl autoloader chain.
				spl_autoload_unregister( $autoloader );
				spl_autoload_register( $autoloader );

			} elseif (
				self::V2_AUTOLOADER_BASE === substr( $autoloader, 0, strlen( self::V2_AUTOLOADER_BASE ) )
				&& __NAMESPACE__ !== substr( $autoloader, 0, strlen( __NAMESPACE__ ) )
			) {
				// Unregister any other v2.* autoloader functions if they're in the chain.
				spl_autoload_unregister( $autoloader );
			}
		}
	}
}
