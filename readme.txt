=== Remote Media Proxy ===
Contributors: timohubois
Tags: uploads, development, media, proxy
Requires at least: 7.0
Tested up to: 7.1
Stable tag: 0.1.0
Requires PHP: 8.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Use production media on local and staging WordPress sites without copying the uploads library.

== Description ==

Remote Media Proxy lets developers use a cloned WordPress site without copying its production uploads library. Existing local files are served normally. Missing media is retrieved from the configured remote site without saving local media files.

The plugin extends **Settings > Media** with an enable checkbox, a remote site URL and optional HTTP Basic Auth credentials. It is disabled by default and does not depend on the WordPress environment type.

== Key Features ==

* Use production media on local and staging sites without copying or syncing uploads.
* Serve existing local files first.
* Support compatible PHP attachment readers through read-only streams.
* Configure the source and optional Basic Auth in native WordPress Media settings or wp-config.php.
* Automatically configure missing-upload routing on supported Apache layouts.
* Support single-site and Multisite without requiring a particular theme or source-side helper.

== Want to contribute? ==

Check out the plugin [GitHub Repository](https://github.com/timohubois/remote-media-proxy/).

== Installation ==

= INSTALL MANUALLY =

1. Upload the 'remote-media-proxy' folder to the /wp-content/plugins/ directory.
2. Activate **Remote Media Proxy** through **Plugins** in WordPress.
3. Open **Settings > Media** and enter the remote site base URL, not its uploads URL.
4. Enter Basic Auth credentials if the source requires them.
5. Check **Enable Remote Media Proxy** and save.
6. Review your server's rewrite rules and resolve any routing warning shown in the site admin.

The source must use a different hostname and matching uploads paths relative to its site base. Different single-site/Multisite upload layouts are not translated automatically. Composer is not required at runtime.

**Apache warning:** rules in wp-content/.htaccess can replace inherited parent rewrite rules, including security restrictions. Review the root, content-directory and server configuration before enabling. The plugin does not preserve rewrite inheritance automatically.

On nginx, Caddy or unsupported Apache layouts, configure missing media requests to reach WordPress's normal front controller rather than a static 404. Preserve script-execution restrictions in uploads.

== Frequently Asked Questions ==

= Can I configure the plugin in wp-config.php? =

Yes. Define any of REMOTE_MEDIA_PROXY_ENABLED, REMOTE_MEDIA_PROXY_URL, REMOTE_MEDIA_PROXY_USERNAME and REMOTE_MEDIA_PROXY_PASSWORD before WordPress loads. Use a boolean for ENABLED and strings for the other values. Only defined constants override saved settings; false and empty strings are intentional overrides.

Constant-controlled fields are disabled in Media settings and cannot be changed through form submissions. URL, username and enablement are shown; constant-controlled password fields stay blank. Code-supplied passwords are not copied into saved settings. Existing saved credentials are retained and take effect again if their constants are removed, provided the WordPress encryption keys have not changed.

The remote_media_proxy_options filter runs last: saved settings, then defined constants, then the filter. Use site-wide configuration and visit the site's admin after code configuration changes to update Apache routing. Examples are in the GitHub repository.

= Does the plugin download or cache media locally? =

It retrieves media synchronously within the current PHP request and buffers responses in memory, but saves no media files and provides no shared media cache. PHP opens and stat operations can fetch independently. Other plugins or themes may still write their own files.

= Does it generate missing image sizes or support image editing? =

No. The source must already serve the requested file or image size. Missing sizes are not generated or replaced with originals. Image editing, resizing and code requiring physical local files are not guaranteed compatible.

= Can PHP read a missing attachment? =

Compatible readers can use the read-only remotemediaproxy:// path returned by get_attached_file(). This requires allow_url_fopen. Obtain the path afresh for each request; do not store it. Existing local files and unfiltered attachment paths remain unchanged. Writes and PHP inclusion are denied; do not enable allow_url_include.

= What happens when the source is unavailable? =

Browser requests retain normal local missing-file handling, and virtual attachment reads become unavailable. Redirects, unsafe requests and invalid media responses are rejected; TLS verification remains enabled. Browser proxying handles GET only. HEAD retains native handling; range requests are not forwarded.

= What limits apply to remote requests? =

Each remote fetch uses PHP's max_execution_time as its HTTP timeout when that value is positive. When PHP specifies no limit (0), WordPress's normal timeout applies instead: usually 5 seconds, adjustable through http_request_timeout. The plugin does not change PHP's execution limit or calculate remaining execution time; server/FPM deadlines still apply.

The response-size cap comes from wp_max_upload_size(), which follows PHP's upload/post limits and WordPress's upload_size_limit filter. Nonpositive or invalid size limits prevent retrieval. There are no additional plugin settings for these limits.

Missing browser images have separate requests. PHP attachment reads during rendering share the page request, so their delays and memory use can accumulate. The upload-size policy does not measure available PHP memory; WordPress and buffered files share each request's memory allowance, so large responses can still exhaust it. The plugin is intended for images and modest-sized files, not large video delivery.

= How are protected media and passwords handled? =

Passwords entered in Media settings are stored using Sodium authenticated encryption. The key is derived from the existing AUTH_KEY and AUTH_SALT in wp-config.php and the current site ID. These WordPress keys must be strong strings of at least 32 bytes; no plugin-specific encryption key or setting is required. The plugin uses WordPress's bundled Sodium compatibility layer when needed and never falls back to a database-stored encryption key. Usernames and URLs are not encrypted.

The database-saved password is decrypted and prefilled in a masked password field. Authorized settings users can inspect its value with browser tools. Edit it to replace the saved password, or clear it and save to remove it. Constant-controlled password fields remain blank and disabled. Passwords supplied through constants or filters are used directly, without copying them into database storage or the form.

Basic Auth is optional. A nonempty username enables the Authorization header; an empty password is allowed if the source accepts it. HTTP sends credentials without transport encryption; prefer HTTPS. A password without a username does not enable Basic Auth.

Changing AUTH_KEY, AUTH_SALT or the site ID makes saved passwords unreadable; re-enter or remove the password afterward. Unreadable passwords are not sent to the source, and credential-based retrieval fails safely unless code configuration supplies a usable password. Encryption protects against a database-only leak, not access to wp-config.php or PHP execution.

If encryption fails, the plugin's settings are left unchanged and an error is shown. Client cookies, authorization and query strings are not forwarded to the source.

**Protect your destination site separately.** Source authentication does not restrict who can view proxied media on your local or staging site.

= Is remote SVG content sanitized? =

No. Use trusted source content and sanitize SVG before inlining it into HTML. The security headers on browser media responses do not protect content inlined by PHP consumers.

= How is Apache routing managed? =

The plugin manages its own marked block in WP_CONTENT_DIR/.htaccess, normally wp-content/.htaccess. Uploads must be inside the content directory and its public URL path; custom/CDN layouts may not be supported. On Multisite, the uploads URL must resolve to the correct site.

Routing is reconciled on activation, settings changes and authorized admin visits, not ordinary frontend requests. The plugin does not edit root rules or override server-level static-file handling. Existing files and directories take precedence, but child rules can replace inherited parent rewrites.

= What happens when I disable or remove the plugin? =

Disabling or deactivating clears owned routing directives and keeps settings. Uninstall also removes the plugin's settings, never media. Harmless marker comments and the shared .htaccess file remain. Cleanup failures are reported so they can be resolved and retried.

Disable routing before moving WordPress or its content directory. When switching from an earlier development build with a different name, deactivate it before removing or renaming its directory, then activate this plugin and re-enter settings. Old settings, custom filter names and routing markers are not migrated; leftover old blocks require manual cleanup. Avoid running overlapping upload proxies.

== Changelog ==

= 0.1.0 =
* Add opt-in remote media proxying and read-only PHP attachment streams.
* Add automatic Apache routing and lifecycle cleanup.
