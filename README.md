# WPOptimizing

**Version 0.1.0** · Requires WordPress 6.6 and PHP 7.4 · License: GPL-3.0-or-later

Speeds up WordPress and cleans up what is left over, knowing which plugin or theme owns each record and file before you delete anything.

Most cleanup plugins delete blindly. WPOptimizing attributes every option, post/user/term meta, custom post type, taxonomy, shortcode, widget, cron job, Action Scheduler action and file to its owning plugin or theme, with a confidence level for each one, so you always know what you are deleting and why. Every deletion is backed up first and can be restored.

## What it does

- **Analysis and attribution**: orphan detection in the database and the file system, a static code scanner, a real-time runtime map and manual attribution rules, with a 7-level confidence system.
- **Cleanup**: orphan data and leftover extensions, revisions, drafts, spam, transients, oEmbed caches, duplicate metadata, Action Scheduler logs and table optimisation, always backup-first, with dry-run previews.
- **Performance**: persistent object cache (Redis, Memcached, APCu or SQLite), query deduplication, autoload management, CSS and JavaScript minification, front-end tweaks, Heartbeat control, browser cache and compression.
- **Coexistence**: detects and purges the cache of your host or of another cache plugin, cleans what an old cache plugin left behind, and imports the settings of WP Rocket, LiteSpeed Cache, W3 Total Cache and ten more.
- **Cloudflare purge** with your own API token.
- **Media**: orphaned files and thumbnails, unused attachments, and WebP/AVIF delivery.
- **Multisite**, contextual help on every screen and a design built for very large databases.

The full feature list, the external services the plugin can contact and the FAQ are in [readme.txt](readme.txt).

## Installation

1. Download the latest release ZIP, or clone this repository into `wp-content/plugins/wpoptimizing`.
2. Activate **WPOptimizing** in *Plugins*.
3. Open **WPOptimizing** in the admin menu; the welcome wizard helps you pick a profile.

## Documentation and support

- Documentation in English: [artprojectgroup.com/documentation/wpoptimizing](https://artprojectgroup.com/documentation/wpoptimizing)
- Documentation in Spanish: [artprojectgroup.es/documentacion/wpoptimizing](https://artprojectgroup.es/documentacion/wpoptimizing)
- Bugs and suggestions: [GitHub issues](https://github.com/artprojectgroup/WPOptimizing/issues)

## WPOptimizing Pro

[WPOptimizing Pro](https://wpoptimizing.com) adds Smart Assets, delayed JavaScript, critical CSS, image optimisation, the media library audit, scheduled cleanup, bulk deletion and tools for agencies. This plugin works fully on its own.

## About this repository

This is the free edition, published from the same source as Pro with the paid features removed at build time. Pull requests are welcome, but they are applied to the source and come back here with the next release.

Made by [Art Project Group](https://artprojectgroup.es).
