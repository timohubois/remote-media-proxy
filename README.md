# Remote Media Proxy

Use production media on local and staging WordPress sites without copying the uploads library.

The plugin extends **Settings > Media** with an enable checkbox, a remote site URL and optional HTTP Basic Auth credentials. Existing local files are served normally; missing media is retrieved from the remote site without saving local media files. Compatible PHP attachment readers can also access remote files through read-only streams.

## Requirements

- WordPress >= 7.0.1 and PHP >= 8.1. Single-site and Multisite are supported.
- An HTTPS source on a different hostname, with the same uploads paths relative to its site base. Different upload layouts are not mapped automatically.
- Missing media requests must reach WordPress rather than a web-server static 404.
- PHP attachment streams require `allow_url_fopen`. Do not enable `allow_url_include`.

No Flynt, Timber, ACF, MU plugin or source-side helper is required.

## Installation

1. Make sure you meet the [requirements](#requirements).
2. Place this repository in `wp-content/plugins/remote-media-proxy/`, or symlink it there for local development.
3. Activate **Remote Media Proxy** through **Plugins** in WordPress.
4. Open **Settings > Media** and enter the remote **site base URL**, not its uploads URL. Add Basic Auth credentials if required.
5. Check **Enable Remote Media Proxy** and save. The plugin is disabled by default, regardless of the WordPress environment type.

Composer is not required to run the plugin.

### Web-server configuration

On supported Apache layouts, the plugin manages a marked block in `WP_CONTENT_DIR/.htaccess` (normally `wp-content/.htaccess`). Uploads must be inside the content directory and its public URL path. Resolve any routing warning in the site admin; custom/CDN layouts may require manual configuration.

**Review your existing rewrite rules before enabling:** child rules in `wp-content/.htaccess` can replace inherited parent rules, including rewrite-based security restrictions. The plugin does not preserve that inheritance automatically or bypass server-level restrictions.

For nginx or Caddy, configure missing uploads to reach WordPress's normal front controller. Check more-specific static-file handlers, and preserve script-execution restrictions in uploads.

### Important limitations

- The source must already serve the requested file or image size. The plugin does not generate missing sizes, substitute originals, or guarantee compatibility with image editing and every theme/plugin filesystem operation.
- Browser proxying supports GET only. Each remote fetch uses PHP's positive `max_execution_time` value as its HTTP timeout. When PHP specifies no limit (`0`), the plugin leaves WordPress's timeout default in place (normally **5 seconds**, controlled by `http_request_timeout`). This is a per-fetch policy, not the PHP request's remaining execution time; PHP limits are not changed, and server/FPM deadlines can still interrupt the request.
- The response-size cap comes from `wp_max_upload_size()`, following PHP's upload/post limits and WordPress's `upload_size_limit` filter. There are no extra plugin settings for either limit. A nonpositive or invalid size limit prevents retrieval. Redirects are refused and TLS verification stays enabled; failures retain normal local missing-file handling.
- Each missing browser image has its own request; PHP attachment reads during page rendering share the page request and their delays can accumulate. Fetches are synchronous and responses are buffered in memory, without a shared media cache; PHP opens and stat operations can fetch independently. The upload-size policy is **not a memory-safety guarantee**: WordPress and other buffers share PHP's memory allowance. This design suits images and modest-sized files, not large video delivery. Other plugins or themes may still write their own files.
- Basic Auth credentials are stored unencrypted. Authorized settings users can inspect the masked password value. Clear the field and save to remove it. **Protect the destination separately:** source authentication does not restrict visitors to your local or staging site.
- SVG content is not sanitized. Consumers that inline remote SVG must trust or sanitize it; browser-response security headers do not protect inline content.
- Avoid overlapping upload proxies. Disable routing before moving WordPress or its content directory. Deactivation keeps settings; uninstall removes them. Both clear owned routing directives but leave harmless marker comments.

When replacing an earlier development build with a different name, deactivate it before removing or renaming its directory. Then activate this plugin, re-enter settings and update custom filters. Old settings and routing markers are not migrated; leftover old blocks require manual cleanup.

## Configuration in code

Override the saved settings through `remote_media_proxy_options`:

```php
add_filter('remote_media_proxy_options', static function (array $options): array {
    $options['enabled'] = true;
    $options['url'] = 'https://production.example';
    return $options;
});
```

Use site-wide configuration for this filter. Visit the site's admin after filter-only changes to reconcile Apache routing.

## Development

Use PHP 8.3 for development and CI. Plugin code must remain compatible with PHP 8.1.

1. Perform [Installation](#installation) in your own WordPress development environment.
2. Ensure `php --version` and Composer use PHP 8.3.
3. Run `composer install` to install development dependencies.
4. Run `composer check-platform-reqs`, `composer php:lint` and `composer validate --strict`.
5. Optionally run `composer rector:run` for a dry-run. Review suggested changes before applying them.

Development dependencies and this README are excluded from installation ZIPs by `.distignore`. WordPress and local-server configuration are not part of this repository. No release or publishing commands are configured.

## License

GPLv3 or later. See [LICENSE](LICENSE).
