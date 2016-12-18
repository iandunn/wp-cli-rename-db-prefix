<?php

/*
Plugin Name: WP-CLI Rename Database Prefix
Plugin URI:  https://github.com/iandunn/wp-cli-rename-db-prefix
Description: A WP-CLI command to rename WordPress' database prefix.
Version:     0.1
Author:      Ian Dunn
Author URI:  http://iandunn.name
License:     GPLv2
*/

if ( ! defined( 'WP_CLI' ) ) {
    exit;
}

class WP_CLI_DB_Rename_Prefix extends \WP_CLI_Command {
	public $old_prefix;
	public $new_prefix;

	public $is_dry_run = false;

	/**
	 * Rename database prefix.
	 *
	 * You will be prompted for confirmation before the command makes any changes.
	 *
	 * ## OPTIONS
	 *
	 * <new_prefix>
	 * : The new database prefix
	 *
	 * [--dry-run]
	 * : Preview which data would be updated.
	 *
	 * ## EXAMPLES
	 *
	 * wp db rename-prefix foo_
	 *
	 * @param array $args
	 * @param array $assoc_args
	 *
	 * @alias change-prefix
	 */
	public function __invoke( $args, $assoc_args ) {
		global $wpdb;

		$this->is_dry_run = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		// Reset `display_errors` after WP-CLI overrides it, see https://github.com/wp-cli/wp-cli/issues/706#issuecomment-203610437
		wp_debug_mode();

		// Make it easier to catch errors while developing this command, but we don't need to show them to users
		$wpdb->show_errors( WP_DEBUG );

		$this->old_prefix = $wpdb->base_prefix;
		$this->new_prefix = $args[0];

		if ( is_multisite() ) {
			\WP_CLI::error( "This command doesn't support MultiSite yet." );
		}

		$this->confirm();

		try {
			\WP_CLI::line();

			$this->update_wp_config();
			$this->rename_wordpress_tables();
			$this->update_blog_options_tables();
			$this->update_options_table();
			$this->update_usermeta_table();

			\WP_CLI::success( 'Successfully renamed database prefix.' );
		} catch ( Exception $exception ) {
			\WP_CLI::error( $exception->getMessage(), false );
			\WP_CLI::error( "You should check your site to see if it's broken. If it is, you can fix it by restoring your `wp-config.php` file and your database from backups." );
		}
	}

	/**
	 * Confirm that the user wants to rename the prefix.
	 */
	protected function confirm() {
		\WP_CLI::line();

		if ( $this->is_dry_run ) {
			\WP_CLI::line( 'Running in dry run mode.' );
			return;
		}

		\WP_CLI::warning( 'Use this at your own risk. If something goes wrong, it could break your site. Before running this, make sure to back up your `wp-config.php` file and run `wp db export`.' );

		\WP_CLI::confirm( sprintf(
			"\nAre you sure you want to rename %s's database prefix from `%s` to `%s`?",
			parse_url( site_url(), PHP_URL_HOST ),
			$this->old_prefix,
			$this->new_prefix
		) );
	}

	/**
	 * Update the prefix in wp-config.php.
	 *
	 * @throws Exception
	 */
	protected function update_wp_config() {
		if ( $this->is_dry_run ) {
			return;
		}

		// We know this is valid, because wp-cli won't run if it's not
		$wp_config_path     = \WP_CLI\Utils\locate_wp_config();
		$wp_config_contents = file_get_contents( $wp_config_path );
		$search_pattern     = '/(\$table_prefix\s*=\s*)([\'"]).+?\\2(\s*;)/';
		$replace_pattern    = "\${1}'{$this->new_prefix}'\${3}";
		$wp_config_contents = preg_replace( $search_pattern, $replace_pattern, $wp_config_contents, -1, $number_replacements );

		if ( 0 === $number_replacements ) {
			throw new Exception( 'Failed to replace `\$table_prefix` in `wp-config.php`.' );
		}

		if ( ! file_put_contents( $wp_config_path, $wp_config_contents ) ) {
			throw new Exception( 'Failed to update updated `wp-config.php` file.' );
		}
	}

	/**
	 * Rename all database tables.
	 *
	 * @throws Exception
	 */
	protected function rename_wordpress_tables() {
		global $wpdb;

		$show_table_query = sprintf(
			'SHOW TABLES LIKE "%s%%";',
			$wpdb->esc_like( $this->old_prefix )
		);

		$tables = $wpdb->get_results( $wpdb->prepare( '%s', $show_table_query ), ARRAY_N );

		if ( ! $tables ) {
			throw new Exception( 'MySQL error: ' . $wpdb->last_error );
		}

		foreach ( $tables as $table ) {
			$table = substr( $table[0], strlen( $this->old_prefix ) );

			$rename_query = $wpdb->prepare(
				'RENAME TABLE `%s` TO `%s`;',
				$this->old_prefix . $table,
				$this->new_prefix . $table
			);

			if ( $this->is_dry_run ) {
				\WP_CLI::line( $rename_query );
				continue;
			}

			if ( false === $wpdb->query( $rename_query ) ) { // WPCS: unprepared SQL ok.
				throw new Exception( 'MySQL error: ' . $wpdb->last_error );
			}
		}
	}

	/**
	 * Update rows in all site's `options` tables.
	 *
	 * @throws Exception
	 */
	protected function update_blog_options_tables() {
		global $wpdb;

		if ( ! is_multisite() ) {
			return;
		}

		throw new Exception( 'Not done yet' );

		// TODO Can't use b/c already renamed tables?
		$sites = wp_get_sites( array( 'limit' => false ) );
		//blogs = $wpdb->get_col( "SELECT blog_id FROM `" . $this->new_prefix . "blogs` WHERE public = '1' AND archived = '0' AND mature = '0' AND spam = '0' ORDER BY blog_id DESC" );

		if ( ! $sites ) {
			throw new Exception( 'Failed to get all sites.' );
		}

		foreach ( $sites as $site ) {
			$update_query = $wpdb->prepare( '
				UPDATE `%s_options`
				SET   option_name = %s
				WHERE option_name = %s
				LIMIT 1;',
				$this->new_prefix . $site->blog_id,
				$this->new_prefix . $site->blog_id . '_user_roles',
				$this->old_prefix . $site->blog_id . '_user_roles'
			);

			if ( $this->is_dry_run ) {
				\WP_CLI::line( $update_query );
				continue;
			}

			if ( ! $wpdb->query( $update_query ) ) { // WPCS: unprepared SQL ok.
				throw new Exception( 'MySQL error: ' . $wpdb->last_error );
			}
		}
	}

	/**
	 * Update rows in the `options` table.
	 *
	 * @throws Exception
	 */
	protected function update_options_table() {
		global $wpdb;

		$update_query = $wpdb->prepare( '
			UPDATE `%soptions`
			SET   option_name = %s
			WHERE option_name = %s
			LIMIT 1;',
			$this->new_prefix,
			$this->new_prefix . 'user_roles',
			$this->old_prefix . 'user_roles'
		);

		if ( $this->is_dry_run ) {
			\WP_CLI::line( $update_query );
			return;
		}

		if ( ! $wpdb->query( $update_query ) ) { // WPCS: unprepared SQL ok.
			throw new Exception( 'MySQL error: ' . $wpdb->last_error );
		}
	}

	/**
	 * Update rows in the `usermeta` table.
	 *
	 * @throws Exception
	 */
	protected function update_usermeta_table() {
		global $wpdb;

		if ( $this->is_dry_run ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT meta_key FROM `%susermeta`;', $this->old_prefix ) );
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT meta_key FROM `%susermeta`;', $this->new_prefix ) );
		}

		if ( ! $rows ) {
			throw new Exception( 'MySQL error: ' . $wpdb->last_error );
		}

		foreach ( $rows as $row ) {
			$meta_key_prefix = substr( $row->meta_key, 0, strlen( $this->old_prefix ) );

			if ( $meta_key_prefix !== $this->old_prefix ) {
				continue;
			}

			$new_key = $this->new_prefix . substr( $row->meta_key, strlen( $this->old_prefix ) );

			$update_query = $wpdb->prepare( '
				UPDATE `%susermeta`
				SET meta_key=%s
				WHERE meta_key=%s
				LIMIT 1;',
				$this->new_prefix,
				$new_key,
				$row->meta_key
			);

			if ( $this->is_dry_run ) {
				\WP_CLI::line( $update_query );
				continue;
			}

			if ( ! $wpdb->query( $update_query ) ) { // WPCS: unprepared SQL ok.
				throw new Exception( 'MySQL error: ' . $wpdb->last_error );
			}
		}
	}
}

\WP_CLI::add_command( 'db rename-prefix', 'WP_CLI_DB_Rename_Prefix' );
