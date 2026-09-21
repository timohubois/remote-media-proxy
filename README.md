# Remote Media Proxy

Use production media on local and staging WordPress sites without copying the uploads library.

The plugin extends **Settings > Media** with a remote site URL, optional HTTP Basic Auth credentials and an enable checkbox. Existing local files are served normally; missing media is fetched from the source without saving local copies. Compatible PHP attachment readers can also use read-only streams.

## Requirements

- WordPress >= 7.0.1 and PHP >= 8.1.
- A source site on a different hostname, with matching uploads paths relative to its site base.
- Missing media requests must reach WordPress. Apache routing is configured automatically where supported; other servers require manual configuration.
- PHP attachment streams require `allow_url_fopen`.

## Installation

1. Place this repository in `wp-content/plugins/remote-media-proxy/`, or symlink it there for local development.
2. Activate **Remote Media Proxy** through **Plugins** in WordPress.
3. Open **Settings > Media** and enter the remote site base URL and any required Basic Auth credentials.
4. Check **Enable Remote Media Proxy** and save. The plugin is disabled by default.

Composer is not required to run the plugin.

**Apache:** review existing rewrite rules before enabling. The plugin manages `wp-content/.htaccess`, whose rules can replace inherited parent rules, including security restrictions.

**Protected media:** HTTP sends Basic Auth credentials without transport encryption; prefer HTTPS. Source Basic Auth does not restrict visitors to the destination site. Protect local and staging sites separately. Saved passwords are encrypted using the existing WordPress authentication keys; keep `wp-config.php` private. After changing those keys or cloning with new keys, re-enter the password.

The source must already serve the requested files and image sizes. See [readme.txt](readme.txt) for server configuration, request limits, compatibility and security notes.

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
