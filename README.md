WP-CLI Rename Database Prefix
===============================

A [WP-CLI](http://wp-cli.org/) command to rename WordPress' database prefix.

## Installing

`wp package install tobias992/wp-cli-rename-db-prefix`

## Usage

`wp rename-db-prefix <new_prefix>`

`wp rename-db-prefix <new_prefix> [--dry-run] [--no-config-update]`

Using the --no-config-update option will not update your `wp-config.php` (useful for non-standard environments).

## Warning

Use this at your own risk. If something goes wrong, it could break your site. Before running this, make sure to back up your `wp-config.php` file and run `wp db export`.

## Notes

A lot of the code is based on [iThemes Security](https://wordpress.org/plugins/better-wp-security/). Forked from iandunn/wp-cli-rename-db-prefix.
