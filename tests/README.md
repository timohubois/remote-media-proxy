# Integration checks

Run in a **disposable WordPress installation**, as a non-root user, with:

- Remote Media Proxy active and Timber loaded before `after_setup_theme` finishes;
- PHP 8.3+, GD, `allow_url_fopen`, and optionally Imagick for animated-GIF checks;
- no predefined `WP_TEMP_DIR` (the suite supplies an isolated directory outside web roots).

```sh
wp eval-file /absolute/path/to/remote-media-proxy/tests/integration.php
```

`fixtures.php` supplies shared helpers, isolated storage and intercepted HTTP responses. `integration.php` contains the checks and removes its temporary fixtures in `finally`. It does not update saved settings or attachments. It deliberately changes fixture permissions, substitutes a failing image editor, and installs a non-removable output buffer; run it in its own CLI process, not within another test runner or a web request.

Coverage includes signed URLs and tampering, local precedence, expiry and source isolation, 404-only native generation, crop failure, inferred/rounded dimensions and crop positions, binary response failures and route casing, private cache publication, reader independence, and cleanup. Animated-GIF assertions run only when Imagick is available. The suite is tested with Timber 1.22.0, 2.3.3 and 2.4.1.

CI uses Composer constraints `^1.0` and `^2.0` to resolve the latest compatible stable release of each major line. Each job uses a fresh dependency directory without an existing lockfile and a fresh WordPress/MySQL environment, and logs the resolved Timber version. These fixtures are excluded from release packages. They are not yet comprehensive Apache lifecycle, encryption/settings, concurrency, or Multisite/domain-mapping tests.
