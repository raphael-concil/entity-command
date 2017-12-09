<?php

use WP_CLI\Entity\RecursiveDataStructureTraverser;
use WP_CLI\Utils;

/**
 * Adds, updates, deletes, and lists site options in a multisite install.
 *
 * ## EXAMPLES
 *
 *     # Get site registration
 *     $ wp site option get registration
 *     none
 *
 *     # Add site option
 *     $ wp site option add my_option foobar
 *     Success: Added 'my_option' site option.
 *
 *     # Update site option
 *     $ wp site option update my_option '{"foo": "bar"}' --format=json
 *     Success: Updated 'my_option' site option.
 *
 *     # Delete site option
 *     $ wp site option delete my_option
 *     Success: Deleted 'my_option' site option.
 */
class Site_Option_Command extends WP_CLI_Command {

	/**
	 * Get a site option.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Key for the site option.
	 *
	 * [--format=<format>]
	 * : Get value in a particular format.
	 * ---
	 * default: var_export
	 * options:
	 *   - var_export
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Get site upload filetypes
	 *     $ wp site option get upload_filetypes
	 *     jpg jpeg png gif mov avi mpg
	 */
	public function get( $args, $assoc_args ) {
		list( $key ) = $args;

		$value = get_site_option( $key );

		if ( false === $value ) {
			WP_CLI::halt(1);
		}

		WP_CLI::print_value( $value, $assoc_args );
	}

	/**
	 * Add a site option.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : The name of the site option to add.
	 *
	 * [<value>]
	 * : The value of the site option to add. If ommited, the value is read from STDIN.
	 *
	 * [--format=<format>]
	 * : The serialization format for the value.
	 * ---
	 * default: plaintext
	 * options:
	 *   - plaintext
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Create a site option by reading a JSON file
	 *     $ wp site option add my_option --format=json < config.json
	 *     Success: Added 'my_option' site option.
	 */
	public function add( $args, $assoc_args ) {
		$key = $args[0];

		$value = WP_CLI::get_value_from_arg_or_stdin( $args, 1 );
		$value = WP_CLI::read_value( $value, $assoc_args );

		if ( ! add_site_option( $key, $value ) ) {
			WP_CLI::error( "Could not add site option '$key'. Does it already exist?" );
		} else {
			WP_CLI::success( "Added '$key' site option." );
		}
	}

	/**
	 * List site options.
	 *
	 * ## OPTIONS
	 *
	 * [--search=<pattern>]
	 * : Use wildcards ( * and ? ) to match option name.
	 *
	 * [--site_id=<id>]
	 * : Limit options to those of a particular site id.
	 *
	 * [--field=<field>]
	 * : Prints the value of a single field.
	 *
	 * [--fields=<fields>]
	 * : Limit the output to specific object fields.
	 *
	 * [--format=<format>]
	 * : The serialization format for the value. total_bytes displays the total size of matching options in bytes.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - count
	 *   - yaml
	 *   - total_bytes
	 * ---
	 *
	 * ## AVAILABLE FIELDS
	 *
	 * This field will be displayed by default for each matching option:
	 *
	 * * meta_key
	 * * meta_value
	 *
	 * These fields are optionally available:
	 *
	 * * meta_id
	 * * site_id
	 * * size_bytes
	 *
	 * ## EXAMPLES
	 *
	 *     # List all site options begining with "i2f_"
	 *     $ wp site option list --search="i2f_*"
	 *     +-------------+--------------+
	 *     | meta_key    | meta_value   |
	 *     +-------------+--------------+
	 *     | i2f_version | 0.1.0        |
	 *     +-------------+--------------+
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc_args ) {

		global $wpdb;
		$pattern = '%';
		$fields = array( 'meta_key', 'meta_value' );
		$size_query = ",LENGTH(meta_value) AS `size_bytes`";

		if ( isset( $assoc_args['search'] ) ) {
			$pattern = self::esc_like( $assoc_args['search'] );
			// substitute wildcards
			$pattern = str_replace( '*', '%', $pattern );
			$pattern = str_replace( '?', '_', $pattern );
		}

		if ( isset( $assoc_args['fields'] ) ) {
			$fields = explode( ',', $assoc_args['fields'] );
		}

		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'format' ) === 'total_bytes' ) {
			$fields = array( 'size_bytes' );
			$size_query = ",SUM(LENGTH(meta_value)) AS `size_bytes`";
		}

		$query = $wpdb->prepare(
			"SELECT `meta_id`, `site_id`, `meta_key`,`meta_value`" . $size_query
				. " FROM `$wpdb->sitemeta` WHERE `meta_key` LIKE %s",
			$pattern
		);

		if ( $site_id = Utils\get_flag_value( $assoc_args, 'site_id' ) ) {
			$query .= $wpdb->prepare( ' AND site_id=%d', $site_id );
		}
		$results = $wpdb->get_results( $query );

		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'format' ) === 'total_bytes' ) {
			WP_CLI::line( $results[0]->size_bytes );
		} else {
			$formatter = new \WP_CLI\Formatter(
				$assoc_args,
				$fields
			);
			$formatter->display_items( $results );
		}
	}

	/**
	 * Update a site option.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : The name of the site option to update.
	 *
	 * [<value>]
	 * : The new value. If ommited, the value is read from STDIN.
	 *
	 * [--format=<format>]
	 * : The serialization format for the value.
	 * ---
	 * default: plaintext
	 * options:
	 *   - plaintext
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Update a site option by reading from a file
	 *     $ wp site option update my_option < value.txt
	 *     Success: Updated 'my_option' site option.
	 *
	 * @alias set
	 */
	public function update( $args, $assoc_args ) {
		$key = $args[0];

		$value = WP_CLI::get_value_from_arg_or_stdin( $args, 1 );
		$value = WP_CLI::read_value( $value, $assoc_args );

		$value = sanitize_option( $key, $value );
		$old_value = sanitize_option( $key, get_site_option( $key ) );

		if ( $value === $old_value ) {
			WP_CLI::success( "Value passed for '$key' site option is unchanged." );
		} else {
			if ( update_site_option( $key, $value ) ) {
				WP_CLI::success( "Updated '$key' site option." );
			} else {
				WP_CLI::error( "Could not update site option '$key'." );
			}
		}
	}

	/**
	 * Delete a site option.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : Key for the site option.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp site option delete my_option
	 *     Success: Deleted 'my_option' site option.
	 */
	public function delete( $args ) {
		list( $key ) = $args;

		if ( ! delete_site_option( $key ) ) {
			WP_CLI::error( "Could not delete '$key' site option. Does it exist?" );
		} else {
			WP_CLI::success( "Deleted '$key' site option." );
		}
	}

	/**
	 * Get a nested value from an option.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : The option name.
	 *
	 * <key-path>...
	 * : The name(s) of the keys within the value to locate the value to pluck.
	 *
	 * [--format=<format>]
	 * : The output format of the value.
	 * ---
	 * default: plaintext
	 * options:
	 *   - plaintext
	 *   - json
	 *   - yaml
	 */
	public function pluck( $args, $assoc_args ) {
		list( $key ) = $args;

		$value = get_site_option( $key );

		if ( false === $value ) {
			WP_CLI::halt( 1 );
		}

		$key_path = array_map( function( $key ) {
			if ( is_numeric( $key ) && ( $key === (string) intval( $key ) ) ) {
				return (int) $key;
			}
			return $key;
		}, array_slice( $args, 1 ) );

		$traverser = new RecursiveDataStructureTraverser( $value );

		try {
			$value = $traverser->get( $key_path );
		} catch ( \Exception $e ) {
			die( 1 );
		}

		WP_CLI::print_value( $value, $assoc_args );
	}

	/**
	 * Update a nested value in an option.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : Patch action to perform.
	 * ---
	 * options:
	 *   - insert
	 *   - update
	 *   - delete
	 * ---
	 *
	 * <key>
	 * : The option name.
	 *
	 * <key-path>...
	 * : The name(s) of the keys within the value to locate the value to patch.
	 *
	 * [<value>]
	 * : The new value. If omitted, the value is read from STDIN.
	 *
	 * [--format=<format>]
	 * : The serialization format for the value.
	 * ---
	 * default: plaintext
	 * options:
	 *   - plaintext
	 *   - json
	 * ---
	 */
	public function patch( $args, $assoc_args ) {
		list( $action, $key ) = $args;
		$key_path = array_map( function( $key ) {
			if ( is_numeric( $key ) && ( $key === (string) intval( $key ) ) ) {
				return (int) $key;
			}
			return $key;
		}, array_slice( $args, 2 ) );

		if ( 'delete' == $action ) {
			$patch_value = null;
		} elseif ( \WP_CLI\Entity\Utils::has_stdin() ) {
			$stdin_value = WP_CLI::get_value_from_arg_or_stdin( $args, -1 );
			$patch_value = WP_CLI::read_value( trim( $stdin_value ), $assoc_args );
		} else {
			// Take the patch value as the last positional argument. Mutates $key_path to be 1 element shorter!
			$patch_value = WP_CLI::read_value( array_pop( $key_path ), $assoc_args );
		}

		/* Need to make a copy of $current_value here as it is modified by reference */
		$old_value = $current_value = sanitize_option( $key, get_site_option( $key ) );

		$traverser = new RecursiveDataStructureTraverser( $current_value );

		try {
			$traverser->$action( $key_path, $patch_value );
		} catch ( \Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}

		$patched_value = sanitize_option( $key, $traverser->value() );

		if ( $patched_value === $old_value ) {
			WP_CLI::success( "Value passed for '$key' site option is unchanged." );
		} else {
			if ( update_site_option( $key, $patched_value ) ) {
				WP_CLI::success( "Updated '$key' site option." );
			} else {
				WP_CLI::error( "Could not update site option '$key'." );
			}
		}
	}

	private static function esc_like( $old ) {
		global $wpdb;

		// Remove notices in 4.0 and support backwards compatibility
		if( method_exists( $wpdb, 'esc_like' ) ) {
			// 4.0
			$old = $wpdb->esc_like( $old );
		} else {
			// 3.9 or less
			$old = like_escape( esc_sql( $old ) );
		}

		return $old;
	}
}
