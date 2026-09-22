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

Remote Media Proxy lets developers use cloned WordPress sites without copying production uploads. Existing local files are served first. Missing media is retrieved from a configured source and cached privately, never added to uploads.

Configure the source and optional HTTP Basic Auth under **Settings > Media**. The plugin is disabled by default and does not depend on the WordPress environment type.

* Local-first browser delivery and compatible read-only PHP attachment streams.
* Shared five-minute media caching outside known web roots.
* Signed, on-demand image requests for supported Timber resizes.
* Automatic missing-upload routing on supported Apache layouts.
* Per-site settings, Multisite support, and optional code configuration.
* No source-side plugin required.

== Installation ==

1. Upload the plugin to /wp-content/plugins/remote-media-proxy/ and activate it.
2. Open **Settings > Media**, enter the source site base URL and any Basic Auth credentials, then enable the proxy.
3. Review server routing and resolve any warning shown in the site admin.

The source must use a different hostname and matching uploads paths relative to its site base. Single-site/Multisite layout differences are not translated automatically. Composer is not required at runtime.

**Apache:** the plugin manages a marked block in WP_CONTENT_DIR/.htaccess. Uploads must be inside the content directory and its public URL path; custom/CDN layouts may not be supported. On Multisite, the upload URL must resolve to the correct site. Child rewrite rules can replace inherited security restrictions: review your server configuration before enabling.

On nginx, Caddy or unsupported Apache layouts, route missing upload requests to WordPress's front controller instead of a static 404. Preserve restrictions on script execution in uploads.

== Frequently Asked Questions ==

= How does caching work? =

Browser requests and compatible PHP reads use local files first, then fresh cached bytes, then remote downloads. Metadata checks use local or cached information where available, otherwise HEAD without downloading the body. Failed lookups and HEAD-only results are reused only within the current PHP request.

Server entries are fresh for five minutes. Reads do not extend freshness; generated derivatives inherit their cached original's remaining deadline. Expired entries are not served, even if refresh fails. Refreshes download the full file; conditional HTTP revalidation is not implemented.

Browsers receive private caching for the remaining server lifetime. Ordinary responses require revalidation after expiry. Signed Timber responses allow another sixty seconds of browser stale-while-revalidate, so supporting browsers can display a previous image during revalidation. The plugin schedules no background refresh. Browser-cached media may remain visible briefly after settings change or the proxy is disabled. Direct local-file responses retain the web server's caching policy.

= Where are files stored and cleaned up? =

WordPress selects temporary storage, respecting WP_TEMP_DIR. Storage must be outside known web roots. Downloads and generation require write access; fresh readable cache entries remain usable when their temporary parent becomes read-only.

Complete downloads and generated files are retained in a private installation/site-specific cache, isolated by source and credentials. Publication is atomic, so readers cannot see partially written entries. Concurrent misses may duplicate work. A validated download can still be served request-locally if it cannot be retained; generated derivatives require usable cache storage.

There are no cache settings, size limits, eviction or scheduled cleanup jobs. Expiration does not delete files: unused entries may accumulate until the host or administrator removes them. Failed or unpublished working files are cleaned up during the request or at shutdown; interrupted workers or failed/filtered deletions can leave files behind. The plugin never sweeps shared temporary storage or writes media into uploads. Other plugins and themes may do so.

= Does it work with Timber and Flynt resizing? =

Supported non-forced resizes return signed REST image URLs when the local original and derivative are missing. Rendering creates the URL without retrieving or generating that derivative. The image request tries local or cached bytes, then the exact remote derivative. Only a confirmed remote 404 allows fetching the original and running native Timber resizing. Failed derivatives are not replaced with originals.

This covers native PHP calls, Twig's resize filter, and Flynt's resizeDynamic when it delegates to normal Timber resizing. It requires allow_url_fopen and REST access for intended image viewers. Existing local files retain precedence and native behavior. For Timber 1, valid attachment metadata also supplies missing images' dimensions without a remote read or database update.

Tested with Timber 1.22.0, 2.3.3 and 2.4.1. Compatibility relies on Timber internals; other releases are not guaranteed. Forced resizing, SVG transformations, custom Resize subclasses, arbitrary transformation chains, and Flynt's separate on-demand generation mode are not covered. Filters active only during template rendering are not transferred to the image request.

= What are the signed image URLs? =

The endpoint is under remote-media-proxy/v1/timber/resize/. It supports GET and HEAD. The path retains the original upload filename; query parameters specify width, height, crop, target and signature. Identical operations produce identical cacheable URLs.

Signatures prevent visitors from inventing resize operations; they do not make published URLs confidential. URLs contain no source credentials or serialized PHP objects. Changing source configuration or authentication keys invalidates old signatures, so cached HTML may need refreshing. Other REST authentication policies still apply.

A valid signed request can regenerate a derivative after cache expiry or host cleanup, without rerendering the page. Ordinary upload URLs carry no resize instructions and require an exact local, cached or remote file.

= Can PHP read missing attachments? =

Compatible readers can use the read-only remotemediaproxy://attachment/ URI returned by get_attached_file(). This requires allow_url_fopen. Obtain the URI afresh each request; do not store it. Existing local files and unfiltered attachment paths remain unchanged. Writes and PHP inclusion are denied; do not enable allow_url_include.

Code requiring physical files in uploads, arbitrary filesystem reads and general image editing are not guaranteed compatible.

= Which file types and request limits apply? =

The plugin follows WordPress's get_allowed_mime_types() policy, including upload_mimes and user/Multisite restrictions. It does not force SVG support or maintain a separate allowlist. Remote responses must match the allowed extension's MIME type or use application/octet-stream. SVG content is not sanitized: trust the source and sanitize SVG before inlining it; browser response headers do not protect PHP-inlined content.

Fetches use PHP's positive max_execution_time as the HTTP timeout. With no PHP limit, WordPress's normal timeout applies, usually five seconds and filterable through http_request_timeout. The plugin does not change execution limits or calculate remaining time.

There are no plugin-imposed file-size caps or upload-quota checks. Downloads stream to disk, but storage can fill and image processing still consumes memory. PHP attachment reads can delay rendering; consumers that load whole files into strings can exhaust memory. This is intended for images and modest-sized files, not large video delivery.

= What happens if the source is unavailable? =

Local files and fresh cached bytes remain available. On a cache miss, failed retrieval leaves ordinary upload requests with normal missing-file handling, PHP attachment reads unavailable, and signed Timber requests with an image error. Authentication failures, transport errors and server errors do not trigger resize generation.

Redirects, unsafe requests and invalid responses are rejected; TLS verification remains enabled. Ordinary upload proxying handles GET only, leaving HEAD to native handling. Signed Timber requests support GET and HEAD. Range requests are not forwarded.

= Can I configure the plugin in wp-config.php? =

Define REMOTE_MEDIA_PROXY_ENABLED, REMOTE_MEDIA_PROXY_URL, REMOTE_MEDIA_PROXY_USERNAME and/or REMOTE_MEDIA_PROXY_PASSWORD before WordPress loads. Use a boolean for ENABLED and strings for the other fields. Defined false and empty strings intentionally override saved settings.

Precedence is saved settings, then defined constants, then the remote_media_proxy_options filter. Constant-controlled fields are disabled, crafted submissions cannot change them, and previously saved values remain available if the constants are removed. Use site-wide configuration and visit the site's admin after code changes to reconcile Apache routing. Examples are in the GitHub README.

= How are passwords and protected media handled? =

Saved passwords use Sodium authenticated encryption, derived from AUTH_KEY, AUTH_SALT and the site ID. Both WordPress keys must be strings of at least 32 bytes; no database-backed encryption key is used. Changing the keys or site ID makes saved passwords unreadable: re-enter or remove them. Unreadable credentials fail safely unless code supplies a usable password. Encryption failure leaves settings unchanged and shows an error. URLs and usernames are not encrypted.

The saved password is prefilled in a masked field; authorized settings users can inspect it using browser tools. Edit it to replace the password, or clear and save to remove it. Code-supplied passwords are not copied into storage or the form. Constant-controlled password fields stay blank and disabled.

A nonempty username enables Basic Auth; an empty password is allowed. Prefer HTTPS because HTTP transmits credentials without transport encryption. Client cookies, authorization and query strings are not forwarded to the source.

**Protect the destination site separately.** Source authentication does not restrict destination visitors. Encryption protects against a database-only leak, not access to wp-config.php or PHP execution.

= How is routing maintained and removed? =

Apache routing is reconciled on activation, settings changes and authorized admin visits, not frontend requests. Updates preserve unrelated file content, stage and verify replacement bytes, and fail without replacing live rules when publication is unsafe. The content directory must be writable; symlinked rules files are not replaced. The plugin does not edit root rules or override server-level static-file handling. Existing files and directories take precedence.

Routing failures are reported to administrators, including per-site network failures. Disabling or deactivating clears owned directives and retains settings. Uninstall also removes settings, never uploads or attachment data. Cached files are left to host or administrator cleanup; harmless marker comments and the shared rules file remain. Cleanup failures are reported for retry.

Disable routing before moving WordPress or its content directory, and avoid overlapping upload proxies.

== Changelog ==

= 1.0.0 =
* Initial release: local-first remote media, read-only attachment streams, private caching and signed Timber resizes.
* Media settings and code configuration, encrypted passwords, and automatic Apache routing with lifecycle cleanup.

== Development ==

Documentation, contributions and issues: [GitHub](https://github.com/timohubois/remote-media-proxy/).
