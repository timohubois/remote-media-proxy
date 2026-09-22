# Remote Media Proxy

Use production media on local and staging WordPress sites without copying the uploads library.

Existing local files take precedence. Missing media is fetched from a configured source into private temporary storage, never into uploads. Compatible PHP attachment readers use read-only streams; supported Timber resizes use signed image URLs so retrieval and generation happen outside template rendering.

## Requirements and setup

- WordPress 7.0+, PHP 8.3+, and a source on a different hostname with matching uploads paths.
- Missing upload requests must reach WordPress. Supported Apache layouts are configured automatically; other servers need manual routing.
- PHP streams and Timber compatibility require `allow_url_fopen`.
- Downloads and generation need writable private temporary storage outside known web roots. WordPress selects it; `WP_TEMP_DIR` can override it. Fresh readable cache entries still work when their temporary parent becomes read-only.

Install in `wp-content/plugins/remote-media-proxy/`, activate, and enable it under **Settings > Media**. Enter the source site URL and optional Basic Auth credentials. Composer is not required at runtime.

**Apache:** review existing rules before enabling. The plugin manages `WP_CONTENT_DIR/.htaccess`; child rules can replace inherited security restrictions. Safe updates require a writable content directory and do not replace symlinked rules files.

**Security:** prefer HTTPS and protect the destination site separately. Source Basic Auth does not restrict destination visitors. Saved passwords are encrypted using WordPress authentication keys; re-enter them after changing those keys.

## Behavior and limits

- **Lookup:** local file → fresh shared cache → validated remote download. Ordinary upload URLs do not generate missing sizes.
- **Cache:** five-minute server freshness; reads do not extend it. Generated derivatives inherit their cached original's deadline. Expiration is a miss, not deletion; there is no automatic eviction or cleanup of retained files.
- **Browser:** private caching uses the remaining server lifetime. Ordinary responses require revalidation after expiry; signed Timber responses allow an additional 60 seconds of browser stale-while-revalidate. The server never serves expired entries or schedules background refresh.
- **Timber:** eligible non-forced resizes return deterministic URLs under `remote-media-proxy/v1/timber/resize/<original-path>`, with signed width, height, crop and target query parameters. The endpoint supports GET/HEAD and generates through native Timber only after a confirmed remote derivative 404.
- **Compatibility:** native PHP, Twig `resize`, and Flynt's normal `resizeDynamic` delegation are covered. Existing local originals retain native behavior. Forced resizing, SVG transformations, custom Resize subclasses, arbitrary transformation chains, and Flynt's separate on-demand generation mode are not covered. Template-only processing filters are not transferred into image requests.
- **Availability:** cold images still wait for processing. PHP body reads and uncached metadata checks can still delay HTML. REST restrictions can block image URLs; changing source configuration invalidates old signatures and may require refreshing cached HTML.

See [readme.txt](readme.txt) for the user-facing configuration, security, storage and server details. Timber compatibility has been tested with 1.22.0, 2.3.3 and 2.4.1; other releases are not guaranteed.

## Configuration in code

Settings are optional when configuration is supplied in `wp-config.php`:

```php
define('REMOTE_MEDIA_PROXY_ENABLED', true);
define('REMOTE_MEDIA_PROXY_URL', 'https://production.example');
define('REMOTE_MEDIA_PROXY_USERNAME', 'example-user');
define('REMOTE_MEDIA_PROXY_PASSWORD', 'example-password');
```

Precedence is **saved settings → defined constants → `remote_media_proxy_options` filter**. Defined `false` and empty strings are intentional overrides. Constant-controlled fields are disabled; code-supplied passwords are not saved or displayed. Saved passwords can be replaced or cleared in Media settings.

```php
add_filter('remote_media_proxy_options', static function (array $options): array {
    $options['enabled'] = true;
    $options['url'] = 'https://production.example';
    return $options;
});
```

Use site-wide configuration and visit the site's admin after code changes to reconcile Apache routing.

## Architecture and extension points

- `Features/` and `Compatibility/` contain auto-started integrations. `Plugin` discovers only their immediate PHP files, using zero-argument singleton factories or public constructors. Timber initializes late in `after_setup_theme`.
- `Media/` contains lazy services: retrieval, independent readers, request-owned staging files, retained cache files, and the shared stream wrapper. Completed files transfer atomically into the cache; request cleanup never deletes retained entries.
- `Helpers/` contains supporting utilities, including password encryption.
- The scoped, stat-only Timber adapter accommodates its private source-path resolution without granting synthetic file reads or writes. This depends on Timber internals.

The `remotemediaproxy://` wrapper has an `attachment/<site>/<attachment>/<filename>` namespace for actual reads and a scoped `timber/<operation>/<path>` namespace for synthetic metadata. Obtain attachment URIs through `get_attached_file()` each request; do not persist them.

`remote_media_proxy_stream_handlers` filters the namespace-to-handler map. Each namespace can supply `open` and `stat` callbacks receiving the full URI. `open` returns `MediaFile|null`; `stat` returns `['size' => int, 'mtime' => int]|null`, with optional `mtime` defaulting to zero. Values must be nonnegative. Handlers validate their own paths and scope; missing operations, writes and PHP inclusion are denied. Call `StreamWrapper::register()` successfully before exposing a URI; foreign wrappers are never replaced.

## Development

```sh
composer install
composer check-platform-reqs
composer validate --strict
composer php:lint
composer rector:run            # Advisory dry-run
```

See [tests/README.md](tests/README.md) for integration checks. CI resolves the latest compatible stable Timber **1.x and 2.x** releases with `^1.0` and `^2.0`, logs the versions, and runs the suite. A separate strict WordPress Plugin Check job checks the release package. Tests and development dependencies are excluded from that package.

Keep meaningful PHPDoc throughout `classes/`: document responsibilities, parameters, return values, ownership, failure behavior, properties and constants. PHPCS enforces documentation structure; two legacy Squiz type checks are excluded because they cannot represent all native/refined types. Behavioral accuracy and constant/promoted-property documentation still need review.

PHPCS exceptions must target a specific rule on one line and explain why; do not disable whole files. Rector has no rule exclusions, but its suggestions remain advisory. Preserve WordPress array callbacks where `has_filter()`/`remove_filter()` depend on their identity.

## License

GPLv3 or later. See [LICENSE](LICENSE). Contributions and issues: [GitHub](https://github.com/timohubois/remote-media-proxy/).
