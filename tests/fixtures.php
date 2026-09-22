<?php

/** CLI fixtures: isolated storage, intercepted upstream responses, and assertion/request helpers. */

namespace RemoteMediaProxy\Tests;

use ReflectionProperty;
use RuntimeException;
use Timber;
use WP_Error;
use WP_REST_Request;
use RemoteMediaProxy\Media\RemoteMediaProxy;

if (!defined('WP_CLI') || !WP_CLI) {
    exit;
}
if (defined('WP_TEMP_DIR')) {
    throw new RuntimeException('Needs an isolated WP_TEMP_DIR');
}
if (
    !class_exists(Timber\ImageHelper::class) || !class_exists(RemoteMediaProxy::class)
    || !function_exists('imagecreatetruecolor')
) {
    throw new RuntimeException('Activate the plugin, load Timber and enable GD before running these checks.');
}
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
    throw new RuntimeException('Run as a non-root user so permission failures can be tested.');
}
$root = sys_get_temp_dir() . '/rmp-rest-' . bin2hex(random_bytes(8));
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create only isolated test-owned fixture directories.
mkdir($root, 0700);
// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create only isolated test-owned fixture directories.
mkdir($root . '/uploads', 0700);
define('WP_TEMP_DIR', $root);
$GLOBALS['rmp_test_count'] = 0;

/** Fail immediately with the violated contract, or count the successful assertion. */
function verifyRmp($condition, $message)
{
    if (!$condition) {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI-only test diagnostics, not HTML output.
        throw new RuntimeException($message);
    }
    ++$GLOBALS['rmp_test_count'];
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only test diagnostics, not HTML output.
    echo "PASS: $message\n";
}
/** Simulate a new backend request without changing installation configuration. */
function freshRmp()
{
    (new ReflectionProperty(RemoteMediaProxy::class, 'instance'))->setValue(null, null);
    return RemoteMediaProxy::getInstance();
}
/** Consume an independent reader and release its handle. */
function bytesRmp($reader)
{
    if ($reader === null) {
        return null;
    }
    $data = stream_get_contents($reader->stream);
    $reader->close();
    return $data;
}
/** Extract the REST route for both pretty and plain permalink URLs. */
function routeRmp($url)
{
    $parts = wp_parse_url($url);
    parse_str($parts['query'] ?? '', $query);
    $path = $query['rest_route'] ?? $parts['path'];
    $offset = strpos($path, '/remote-media-proxy/v1/');
    if ($offset === false) {
        // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI-only test diagnostics, not HTML output.
        throw new RuntimeException('Expected REST image URL: ' . $url);
    }
    return substr($path, $offset);
}
/** Decode the URL query exactly as PHP would on an incoming request. */
function recipeRmp($url)
{
    parse_str(wp_parse_url($url, PHP_URL_QUERY) ?? '', $query);
    return $query;
}
/** Build an isolated request with separate route and query parameter sources. */
function requestRmp($url, $method = 'GET')
{
    $request = new WP_REST_Request($method, routeRmp($url));
    $request->set_query_params(recipeRmp($url));
    return $request;
}
/** Dispatch permissions and the route callback without emitting an HTTP response. */
function imageRmp($url, &$expires = null, $method = 'GET')
{
    $response = rest_do_request(requestRmp($url, $method));
    $GLOBALS['last_rmp_status'] = $response->get_status();
    $data = $response->get_data();
    $expires = $data['expires'] ?? null;
    return $data['file'] ?? null;
}
/** Inspect a generated image and release its reader, returning only its dimensions. */
function imageSizeRmp(string $url): ?array
{
    $reader = imageRmp($url);
    if ($reader === null) {
        return null;
    }
    try {
        $size = wp_getimagesize(stream_get_meta_data($reader->stream)['uri']);
        return is_array($size) ? [$size[0], $size[1]] : null;
    } finally {
        $reader->close();
    }
}

$options = ['enabled' => true,'url' => 'https://rmp-source.example','username' => 'test','password' => 'first'];
$optFilter = static function () use (&$options) {
    return $options;
};
$uploadFilter = static function ($uploads) use ($root) {
    $uploads['basedir'] = $root . '/uploads';
    $uploads['baseurl'] = home_url('/rmp-unified-uploads');
    $uploads['path'] = $uploads['basedir'];
    $uploads['url'] = $uploads['baseurl'];
    $uploads['subdir'] = '';
    $uploads['error'] = false;
    return $uploads;
};
$image = imagecreatetruecolor(320, 160);
imagefill($image, 0, 0, imagecolorallocate($image, 180, 20, 30));
ob_start();
imagepng($image);
$png = ob_get_clean();
imagedestroy($image);
$calls = [];
$status = 200;
$derivativeStatus = 404;
$body = $png;
$httpFilter = static function ($pre, $args, $url) use (&$calls, &$status, &$derivativeStatus, &$body) {
    $name = basename((string) wp_parse_url($url, PHP_URL_PATH));
    $originals = ['source.png', 'source.jpg', 'source.gif', 'remote.png', 'fallback.png'];
    $code = in_array($name, $originals, true) ? $status : $derivativeStatus;
    $calls[] = [$args['method'], $name, $code];
    if ($code === 'error') {
        return new WP_Error('fixture_transport', 'Intercepted transport failure');
    }
    if ($code === 200 && !empty($args['stream'])) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Populate controlled fixtures or intercepted HTTP staging files.
        file_put_contents($args['filename'], $body);
    }
    $mime = match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
        'jpg' => 'image/jpeg', 'gif' => 'image/gif', default => 'image/png'
    };
    return ['headers' => ['content-type' => $mime,'content-length' => (string) strlen($body)],'body' => '',
        'response' => ['code' => $code,'message' => 'Fixture'],'cookies' => []];
};
add_filter('remote_media_proxy_options', $optFilter, PHP_INT_MAX);
add_filter('upload_dir', $uploadFilter, PHP_INT_MIN);
add_filter('pre_http_request', $httpFilter, PHP_INT_MIN, 3);
$cachePath = null;
