# Remote Media Proxy

Use production media on local and staging WordPress sites without copying the uploads library.

The plugin extends **Settings > Media** with a remote site URL, optional HTTP Basic Auth credentials and an enable checkbox. Existing local files are served normally; missing media is fetched from the source without adding files to uploads. Compatible PHP attachment readers can also use read-only streams.

## Requirements

- WordPress >= 7.0 and PHP >= 8.3.
- A source site on a different hostname, with matching uploads paths relative to its site base.
- Missing media requests must reach WordPress. Apache routing is configured automatically where supported; other servers require manual configuration.
- PHP attachment streams require `allow_url_fopen`.
- Remote file reads require a writable private temporary directory outside the web roots. WordPress selects it; `WP_TEMP_DIR` can override it.

## Installation

1. Place this repository in `wp-content/plugins/remote-media-proxy/`, or symlink it there for local development.
2. Activate **Remote Media Proxy** through **Plugins** in WordPress.
3. Open **Settings > Media** and enter the remote site base URL and any required Basic Auth credentials.
4. Check **Enable Remote Media Proxy** and save. The plugin is disabled by default.

Composer is not required to run the plugin.

**Apache:** review existing rewrite rules before enabling. The plugin manages `wp-content/.htaccess`, whose rules can replace inherited parent rules, including security restrictions. Updates stage and verify the complete file in the content directory before atomic publication; this requires a writable directory and a regular, non-symlinked rules file. Concurrent detected edits and incomplete writes fail without replacing the live rules. Coordinate other tools that modify the same file.

Network-wide failures retain per-site diagnostics. A user-specific routing notice survives redirects for up to one minute, then is consumed in Network Admin; CLI network operations also print warnings. No media metadata is stored by this notice mechanism.

**Protected media:** HTTP sends Basic Auth credentials without transport encryption; prefer HTTPS. Source Basic Auth does not restrict visitors to the destination site. Protect local and staging sites separately. Saved passwords are encrypted using the existing WordPress authentication keys; keep `wp-config.php` private. After changing those keys or cloning with new keys, re-enter the password.

The source must already serve the requested files and image sizes. See [readme.txt](readme.txt) for server configuration, request limits, compatibility and security notes.

## Remote file access

Browser delivery and compatible PHP readers share one read-only file layer: existing local files first, otherwise a validated temporary download. All remote reads use the same authentication and response validation, without plugin-imposed file-size limits or dependence on the site's upload allowance. Metadata checks use local file information or remote `HEAD` requests without downloading the body.

Within one PHP request, repeated remote reads reuse one validated download with independent reader handles. Metadata and failed lookups are also reused within that request, isolated by site, source and credentials. Local files always take precedence.

Closing a reader releases its handle, not the shared file. At PHP shutdown, remaining handles are closed before temporary files are deleted; failed downloads are submitted to `wp_delete_file()` immediately. Failed or filtered deletions remain tracked for a shutdown retry. Local originals are never deleted. Neither media bodies nor metadata are cached server-side across requests. A process killed by the host can leave an orphan; host-managed cleanup remains necessary. The plugin never sweeps a shared temporary directory, and concurrent requests still count toward hosting quotas.

Successful proxied browser responses use `Cache-Control: private, max-age=300, stale-while-revalidate=60`: five minutes of browser caching, then up to one minute of stale reuse while supporting browsers refresh in the background. Cached media can remain visible briefly after source settings change or the proxy is disabled. Refreshes download the full file; no conditional revalidation is implemented. Local files and error responses retain their existing caching behavior.

The WordPress adapter connects browser requests and compatible attachment readers to this layer. It does not replace PHP's native filesystem or intercept arbitrary absolute paths.

For Timber, a scoped, metadata-only filesystem view lets non-forced `ImageHelper::resize()` calls return Timber's own derivative URLs when local raster originals are missing. This also covers Twig `resize` and Flynt's `resizeDynamic` when it delegates to Timber; Flynt's `/resized/` path filters remain intact. No Twig filters are replaced. The view reports a synthetic cache hit, not actual remote existence or file metadata, and denies file reads and writes. Rendering performs no remote probes; the browser receives the exact requested derivative or a 404.

For Timber 1 attachment images with missing local raster files, valid WordPress attachment dimensions also seed the image object's in-memory dimension cache. This supports `width()`, `height()` and `aspect()` without remote reads or changes to stored metadata. Images without valid attachment dimensions retain native behavior.

This compatibility workaround relies on Timber's private path-resolution flow. It has been tested with versions 1.22.0, 2.3.3 and 2.4.1, but is not version-gated; compatibility with other releases is not guaranteed. It requires `allow_url_fopen`; forced operations, SVGs and existing local originals retain native behavior. It does not enable Flynt's separate dynamic-generation mode, discover available derivatives, generate sizes or add persistent metadata caching.

### Stream namespaces

One `Media/StreamWrapper` owns the `remotemediaproxy://` protocol:

- `attachment/<site>/<attachment>/<filename>` delegates real media access to `VirtualUploads`.
- `timber/<operation>/<path>` delegates synthetic metadata to the Timber compatibility layer. It has no open handler.

The `remote_media_proxy_stream_handlers` filter extends the namespace-to-handler array. Each namespace may provide `open` and `stat` callbacks, receiving the complete URI. `open` returns a `MediaFile` or `null`; `stat` returns `['size' => int, 'mtime' => int]` or `null`, with `mtime` optional (default `0`). Sizes and timestamps must be non-negative integers. Missing operations and unknown namespaces fail; the wrapper normalizes metadata to a read-only regular file and centrally denies writes and PHP inclusion.

Handlers own path validation, identity and scope checks. Call `StreamWrapper::register()` successfully before exposing a URI; it respects `allow_url_fopen` and never replaces an existing foreign wrapper. Resolve attachment paths afresh rather than storing virtual URIs.

## Configuration in code

Set any of these constants in `wp-config.php`, before WordPress loads:

```php
define('REMOTE_MEDIA_PROXY_ENABLED', true);
define('REMOTE_MEDIA_PROXY_URL', 'https://production.example');
define('REMOTE_MEDIA_PROXY_USERNAME', 'example-user');
define('REMOTE_MEDIA_PROXY_PASSWORD', 'example-password');
```

Define only the values you want to control in code. Their Media settings fields are disabled, and code-supplied passwords are not copied into saved settings. Database-saved passwords are prefilled in a masked field; edit to replace or clear to remove. Constants and filters never supply the form's password value. Constants and filters remain optional; Media settings work without plugin-specific constants. Use empty strings for credentials when Basic Auth is not needed.

Precedence: **saved settings → defined constants → filter**. Defined `false` and empty strings override saved values too. The filter can override all four options:

```php
add_filter('remote_media_proxy_options', static function (array $options): array {
    $options['enabled'] = true;
    $options['url'] = 'https://production.example';
    $options['username'] = 'example-user';
    $options['password'] = 'example-password';
    return $options;
});
```

Use site-wide configuration and visit the site's admin after code configuration changes to update Apache routing.

## Development

`classes/Features/` contains hook-registering features; `classes/Compatibility/` contains auto-started integrations. `classes/Media/` owns retrieval, readers, temporary files and streams. `classes/Helpers/` contains supporting utilities such as password encryption. Media classes and helpers are used on demand, never auto-started.

PHP 8.3 is the minimum supported version and is used for development and CI linting.

`classes/Plugin.php` scans only immediate PHP files in `Features/` and `Compatibility/`, calling zero-argument singleton factories or public constructors to register hooks. It does not recurse into subdirectories or inspect constructor signatures. Timber defers its dependency check and integration until late `after_setup_theme`.

```sh
composer install
composer check-platform-reqs
composer php:lint
composer validate --strict
composer rector:run            # Optional dry-run; no changes applied
```

Rector runs without rule exclusions. Its dry run is advisory: review suggestions rather than requiring an empty report. In particular, converting WordPress array callbacks to first-class callables changes their identity for `has_filter()`/`remove_filter()` and the action equivalents. Preserve array callbacks where that identity is part of the integration contract.

### Documentation conventions

Documentation is mandatory throughout `classes/`, including private members. Built-in `Squiz.Commenting` rules require class, method and property docblocks, complete parameter and return tags, parameter descriptions, and consistent tag alignment. Only two legacy type-matching checks are excluded: Squiz cannot reconcile native `mixed` with a documented resource or native arrays/objects with refined PHPDoc types. Constants and constructor-promoted properties follow the same documentation policy, but their docblock presence requires review because Squiz does not enforce it. Lint also cannot verify whether documented array shapes or behavior are accurate.

- Describe each class's responsibility and each method's purpose, including constructors and destructors.
- Document every parameter in declaration order and every return value, including `void`. Constructors and destructors need no `@return`. Repeat native types where required; explain meanings, ownership, lifecycle and failure conditions rather than merely restating member names.
- Give every property and constant a purpose and an `@var` type. A concise one-line docblock is sufficient.
- Keep native PHP types. Refine PHPDoc with array shapes, collection element types and resources where useful. Follow Squiz's canonical scalar spelling (`integer` and `boolean`) for standalone tags; compact array shapes may use `int` and `bool`.
- Add `@throws` for exceptions that can escape the method, not failures caught internally. Separate summaries from tags with a blank line and align parameter tags using PHPCBF.
- Inline comments explain why a decision or safeguard is necessary. Keep translator comments and WordPress hook documentation where required.
- Every PHPCS exception must target one specific rule on one line and include a reason: `// phpcs:ignore Specific.Rule -- Concrete reason.` Do not disable checks for an entire file. PHP stream callbacks retain PHP's required names; their exceptions sit on declaration lines so Squiz can associate the preceding docblocks correctly.

Development dependencies are excluded from installation ZIPs. Report issues and submit improvements through the [GitHub repository](https://github.com/timohubois/remote-media-proxy/).

## License

GPLv3 or later. See [LICENSE](LICENSE).
