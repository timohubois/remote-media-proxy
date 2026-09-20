# Remote Media Proxy

Use production media on local and staging WordPress sites without copying the uploads library.

The plugin extends **Settings > Media** with a remote site URL, optional HTTP Basic Auth credentials and an enable checkbox. Existing local files are served normally; missing media is fetched from the source without saving local copies. Compatible PHP attachment readers can also use read-only streams.

## Requirements

- WordPress >= 7.0.1 and PHP >= 8.1.
- An HTTPS source on a different hostname, with matching uploads paths relative to its site base.
- Missing media requests must reach WordPress. Apache routing is configured automatically where supported; other servers require manual configuration.
- PHP attachment streams require `allow_url_fopen`.

## Installation

1. Place this repository in `wp-content/plugins/remote-media-proxy/`, or symlink it there for local development.
2. Activate **Remote Media Proxy** through **Plugins** in WordPress.
3. Open **Settings > Media** and enter the remote site base URL and any required Basic Auth credentials.
4. Check **Enable Remote Media Proxy** and save. The plugin is disabled by default.

Composer is not required to run the plugin.

**Apache:** review existing rewrite rules before enabling. The plugin manages `wp-content/.htaccess`, whose rules can replace inherited parent rules, including security restrictions.

**Protected media:** source Basic Auth does not restrict visitors to the destination site. Protect local and staging sites separately. Credentials are stored unencrypted.

The source must already serve the requested files and image sizes. See [readme.txt](readme.txt) for server configuration, request limits, compatibility and security notes.

## Configuration in code

Override saved settings through `remote_media_proxy_options`:

```php
add_filter('remote_media_proxy_options', static function (array $options): array {
    $options['enabled'] = true;
    $options['url'] = 'https://production.example';
    return $options;
});
```

Use site-wide configuration and visit the site's admin after filter-only changes to update Apache routing.

## Development

Use PHP 8.3 for development and CI; keep plugin code compatible with PHP 8.1.

```sh
composer install
composer check-platform-reqs
composer php:lint
composer validate --strict
composer rector:run            # Optional dry-run; no changes applied
```

Development dependencies are excluded from installation ZIPs. Report issues and submit improvements through the [GitHub repository](https://github.com/timohubois/remote-media-proxy/).

## License

GPLv3 or later. See [LICENSE](LICENSE).
