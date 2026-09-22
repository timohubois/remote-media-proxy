=== Remote Media Proxy ===
Contributors: timohubois
Tags: uploads, development, media, proxy
Requires at least: 7.0
Tested up to: 7.1
Stable tag: 1.0.0
Requires PHP: 8.3
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Use production media on local and staging WordPress sites without copying the uploads library.

== Description ==

Remote Media Proxy lets developers use a cloned WordPress site without copying its production uploads library. Existing local files are served normally. Missing media is retrieved from the configured remote site without adding files to the uploads library.

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

= Which file types are supported? =

The plugin uses WordPress's allowed MIME types through get_allowed_mime_types(), including the upload_mimes filter and applicable user or Multisite restrictions. It does not maintain a separate file-type allowlist or force SVG support. Additional types such as JSON or fonts must be allowed by WordPress. The remote response must match the detected MIME type or use application/octet-stream.

= Does the plugin download or cache media locally? =

Browser requests and compatible PHP readers share one cache-first backend: existing local files take precedence, then fresh cached files, then remote downloads. Complete validated downloads and locally generated derivatives are retained in private temporary storage for reuse across requests. Each reader has its own file handle. Cache hits create no working files or directories and make no remote requests. Media files are never added to uploads. Metadata checks use local or cached bytes when available, otherwise HEAD without downloading a body; HEAD-only results and failed lookups remain request-local. Other plugins or themes may write their own files.

Proxied media is reusable for up to five minutes. Browser responses receive only the server entry's remaining lifetime, with private caching and must-revalidate. There is no stale-while-revalidate or background refresh; expired bytes are not served after refresh failure. Cached media may remain visible briefly after source settings change or the proxy is disabled. Refreshes transfer the full file. Native local-file responses and errors keep their existing caching behavior.

WordPress chooses the temporary directory, including any WP_TEMP_DIR configuration. It must be writable and outside known web roots, including WordPress and uploads directories. If no suitable directory is available, remote file reads are unavailable and browser requests keep normal missing-file handling. Failed downloads are submitted for deletion immediately. Cleanup uses wp_delete_file(); failed or filtered deletions are retained for another attempt at shutdown. At PHP shutdown, remaining reader handles close before unpublished working files are deleted. Retained cache entries survive request cleanup. Interrupted shutdown or a killed worker can leave staging files for the host or administrator to clean up; the plugin never sweeps shared temporary storage.

= Does it generate missing image sizes or support image editing? =

Ordinary media requests require the exact remote file. For supported Timber resize calls, a missing remote derivative can be generated temporarily during page rendering. Failed derivatives are never replaced with originals. General image editing, other transformations and code requiring physical files in uploads are not guaranteed compatible.

= Does it work with Timber and Flynt resizing? =

With Timber and allow_url_fopen enabled, ordinary resize calls retain their native URLs even when the local original is missing. This includes native PHP calls, Twig's resize filter and Flynt's resizeDynamic when it wraps normal Timber resizing. The plugin reuses a fresh cached derivative or requests the exact remote derivative. On a confirmed remote 404, it retrieves the original temporarily and runs the native Timber resize operation. This can delay page rendering on cache misses. Authentication failures, network failures and other remote errors do not trigger generation. Existing local files keep precedence.

For Timber 1 attachment images whose local raster files are missing, valid WordPress attachment dimensions provide width, height and aspect ratio without downloading the image. Stored metadata is not changed. Images without valid attachment dimensions retain native behavior.

The compatibility adapter uses a read-only, metadata-only filesystem view and depends on Timber internals. It has been tested with Timber 1.22.0, 2.3.3 and 2.4.1. There is no version restriction, but compatibility with other releases is not guaranteed. Forced resizing and SVGs retain native behavior. Flynt's separate on-demand generation route and arbitrary transformation chains are not covered.

= How are cached files stored? =

All validated remote downloads and generated derivatives share one private, installation/site-specific directory below WordPress's temporary directory. Each media identity has a stable filename, isolated by source and credentials. On a miss, a private staging file is downloaded or generated, validated, and atomically moved into place without copying its contents. Readers cannot observe a partially published file. No lock files or per-operation working directories are created. Concurrent cold requests may duplicate work.

There are no new settings, size limits or scheduled cleanup jobs. Expiration means a cache miss, not deletion. Successful regeneration atomically replaces the expired entry. Unused files may remain until the host or administrator removes them; temporary directories do not guarantee automatic cleanup. Only unpublished or failed working files remain request-owned and are cleaned up. Generating a derivative from a cached original does not extend that original's freshness deadline.

The host may remove cached files at any time. The next page render can regenerate them. An image-only request does not perform generation: it retries the exact remote file and may return 404 if that file is also missing. Cached pages may therefore need refreshing. Unavailable cache storage leaves newly generated derivatives unavailable rather than breaking the page or writing into uploads. A validated remote download can still be served request-locally if it cannot be retained.

= Can PHP read a missing attachment? =

Compatible readers can use the read-only remotemediaproxy://attachment/ path returned by get_attached_file(). This requires allow_url_fopen. Obtain the path afresh for each request; do not store it. Existing local files and unfiltered attachment paths remain unchanged. Writes and PHP inclusion are denied; do not enable allow_url_include.

= What happens when the source is unavailable? =

Browser requests retain normal local missing-file handling, and virtual attachment reads become unavailable. Redirects, unsafe requests and invalid media responses are rejected; TLS verification remains enabled. Browser proxying handles GET only. HEAD retains native handling; range requests are not forwarded.

= What limits apply to remote requests? =

Each remote fetch uses PHP's max_execution_time as its HTTP timeout when that value is positive. When PHP specifies no limit (0), WordPress's normal timeout applies instead: usually 5 seconds, adjustable through http_request_timeout. The plugin does not change PHP's execution limit or calculate remaining execution time; server/FPM deadlines still apply.

The plugin imposes no file-size or per-request download-size cap and does not use the site's upload allowance. Downloads stream to temporary storage rather than being buffered in plugin memory. Large files and concurrent requests can exhaust the hosting account's disk quota; PHP and server execution limits still apply.

Missing browser images have separate requests. PHP attachment reads during rendering share the page request, so their delays and temporary storage use can accumulate. Consumers that read entire files into strings can still exhaust PHP memory. The plugin is intended for images and modest-sized files, not large video delivery.

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

Routing is reconciled on activation, settings changes and authorized admin visits, not ordinary frontend requests. Updates are staged and verified before replacing the rules file. The content directory must be writable and support safe file publication; symlinked .htaccess files are not replaced. Failed staging or publication leaves existing rules intact. The plugin does not edit root rules or override server-level static-file handling. Existing files and directories take precedence, but child rules can replace inherited parent rewrites.

Network-wide routing failures are reported per site. A user-specific notice is retained for up to one minute to survive the activation redirect and removed when shown in Network Admin. These notices are not media or metadata caches.

= What happens when I disable or remove the plugin? =

Disabling or deactivating clears owned routing directives and keeps settings. Uninstall also removes the plugin's settings, never uploads or attachment data. Disposable cached files are left to host or administrator cleanup. Harmless marker comments and the shared .htaccess file remain. Cleanup failures are reported so they can be resolved and retried.

Disable routing before moving WordPress or its content directory. Avoid running overlapping upload proxies.

== Changelog ==

= 1.0.0 =
* Initial release with opt-in remote media proxying and read-only PHP attachment streams.
* Automatic Apache routing and lifecycle cleanup.
* Configuration through Media settings, wp-config.php constants and the options filter.
* Encrypted storage for saved passwords and masked password controls.
