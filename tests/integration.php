<?php

/** CLI-only integration checks; run in a disposable WordPress installation with Timber and this plugin active. */

namespace RemoteMediaProxy\Tests;

use FilesystemIterator;
use Imagick;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use Timber;
use WP_Error;
use WP_Image_Editor_GD;
use WP_REST_Request;
use WP_REST_Response;
use RemoteMediaProxy\Media\FileCache;
use RemoteMediaProxy\Media\TemporaryFile;
use RemoteMediaProxy\Media\MediaFile;
use RemoteMediaProxy\Compatibility\Timber as Adapter;
use RemoteMediaProxy\Compatibility\WordPress;

require __DIR__ . '/fixtures.php';

try {
    $proxy = freshRmp();
    $missing = false;
    $one = $proxy->openUpload('remote.png', $missing, $deadline);
    verifyRmp(
        $one !== null && !$missing && $deadline > time(),
        'first remote download returns fresh bytes and a deadline'
    );
    $cachePath = stream_get_meta_data($one->stream)['uri'];
    verifyRmp(
        str_ends_with($cachePath, '.png') && (fileperms($cachePath) & 0777) === 0600,
        'completed download is moved into private cache'
    );
    verifyRmp(
        glob($root . '/remote-media-proxy-*.tmp') === [],
        'successful publication leaves no loose download or staging copy'
    );
    $two = $proxy->openUpload('remote.png', $missing, $deadline2);
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Compare native stream offsets of independently opened readers.
    verifyRmp(fread($one->stream, 8) === fread($two->stream, 8), 'cached readers have independent offsets');
    $one->close();
    $two->close();
    verifyRmp(count($calls) === 1 && $deadline === $deadline2, 'same request reuses cache without renewing freshness');
    $proxy = freshRmp();
    verifyRmp(
        bytesRmp($proxy->openUpload('remote.png')) === $png && count($calls) === 1,
        'new request reuses cached bodies'
    );
    verifyRmp(
        $proxy->statUpload('remote.png')['size'] === strlen($png) && count($calls) === 1,
        'cached bytes satisfy metadata without HEAD'
    );
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Force exact fixture permissions to simulate host changes.
    chmod($root, 0500);
    clearstatcache();
    try {
        $probe = freshRmp()->openUpload('remote.png');
        verifyRmp(
            is_readable($cachePath) && $probe !== null,
            'readable cached bytes survive a read-only temporary parent'
        );
        $probe?->close();
    } finally {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Force exact fixture permissions to simulate host changes.
        chmod($root, 0700);
        clearstatcache();
        freshRmp();
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Age fixture entries directly to test expiration without sleeps.
    touch($cachePath, time() - 250);
    clearstatcache();
    $reader = freshRmp()->openUpload('remote.png', $missing, $remaining);
    $policy = (new ReflectionMethod(WordPress::class, 'cacheControl'))->invoke(null, $remaining);
    verifyRmp(
        $remaining - time() <= 50 && str_contains($policy, 'must-revalidate') && !str_contains($policy, 'stale-while'),
        'browser receives only remaining cache freshness'
    );
    $reader->close();
    foreach ([403, 500, 'error'] as $failure) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Age fixture entries directly to test expiration without sleeps.
        touch($cachePath, time() - 301);
        clearstatcache();
        $status = $failure;
        verifyRmp(
            freshRmp()->openUpload('remote.png', $missing) === null && !$missing,
            'expired bytes are not served after ' . $failure
        );
    }
    $status = 200;
    $body = $png . 'new';
    verifyRmp(
        bytesRmp(freshRmp()->openUpload('remote.png')) === $body,
        'expired download refreshes with changed source bytes'
    );
    $before = count($calls);
    $options['password'] = 'second';
    verifyRmp(
        bytesRmp(freshRmp()->openUpload('remote.png')) === $body && count($calls) === $before + 1,
        'credentials isolate persistent cache entries'
    );
    $options['password'] = 'first';
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Populate controlled fixtures or intercepted HTTP staging files.
    file_put_contents($root . '/uploads/remote.png', 'local');
    $before = count($calls);
    verifyRmp(
        bytesRmp(freshRmp()->openUpload('remote.png')) === 'local' && count($calls) === $before,
        'physical local media takes precedence over a fresh cache'
    );
    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only test-owned fixtures independently of WordPress deletion filters.
    unlink($root . '/uploads/remote.png');
    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only test-owned fixtures independently of WordPress deletion filters.
    unlink($cachePath);
    $before = count($calls);
    verifyRmp(
        bytesRmp(freshRmp()->openUpload('remote.png')) === $body && count($calls) === $before + 1,
        'host-deleted entry downloads again normally'
    );
    $directory = dirname($cachePath);
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Force exact fixture permissions to simulate host changes.
    chmod($directory, 0500);
    clearstatcache();
    $reader = freshRmp()->openUpload('fallback.png', $missing, $fallbackDeadline);
    verifyRmp(
        $reader !== null && str_ends_with(stream_get_meta_data($reader->stream)['uri'], '.tmp'),
        'unwritable cache still delivers validated request-local bytes'
    );
    $reader->close();
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Force exact fixture permissions to simulate host changes.
    chmod($directory, 0700);
    clearstatcache();
    $body = $png;
    freshRmp();
    $before = count($calls);
    $url = Timber\ImageHelper::resize(home_url('/rmp-unified-uploads/source.png'), 200, 100);
    verifyRmp(
        is_string($url) && str_contains($url, '/remote-media-proxy/v1/timber/resize/'),
        'rendering emits a signed REST image URL'
    );
    verifyRmp(count($calls) === $before, 'cold rendering makes no derivative or original HTTP requests');
    verifyRmp(
        Timber\ImageHelper::resize(home_url('/rmp-unified-uploads/source.png'), 200, 100) === $url,
        'image URLs are deterministic across operations'
    );
    $recipe = recipeRmp($url);
    $target = $recipe['target'];
    verifyRmp(
        !isset($recipe['password'], $recipe['username'], $recipe['url']),
        'recipe contains no source credentials or remote URL'
    );
    $generated = imageRmp($url, $generatedDeadline);
    verifyRmp($generated !== null, 'remote 404 generates and retains the exact derivative');
    $generatedPath = stream_get_meta_data($generated->stream)['uri'];
    $dimensions = getimagesize($generatedPath);
    verifyRmp($dimensions[0] === 200 && $dimensions[1] === 100, 'native operation produces the requested dimensions');
    $generated->close();
    verifyRmp(count($calls) === $before + 2, 'generation requests the derivative then downloads the original once');
    $unscoped = str_replace('/timber/resize/', '/resize/', routeRmp($url));
    verifyRmp(
        rest_do_request(new WP_REST_Request('GET', $unscoped))->get_status() === 404,
        'old unscoped route is not registered'
    );
    verifyRmp(
        rest_do_request(new WP_REST_Request(
            'GET',
            '/remote-media-proxy/v1/wordpress/media/test'
        ))->get_status() === 404,
        'unused integration endpoints are not exposed'
    );
    $sourceReader = freshRmp()->openUpload('source.png');
    $sourcePath = stream_get_meta_data($sourceReader->stream)['uri'];
    $sourceReader->close();
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Age fixture entries directly to test expiration without sleeps.
    touch($sourcePath, time() - 250);
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Age fixture entries directly to test expiration without sleeps.
    touch($generatedPath, time() - 301);
    clearstatcache();
    $before = count($calls);
    freshRmp();
    Timber\ImageHelper::resize(home_url('/rmp-unified-uploads/source.png'), 200, 100);
    verifyRmp(count($calls) === $before, 'expired cached derivatives still cause no render-time HTTP');
    $generated = imageRmp($url, $generatedDeadline);
    verifyRmp(
        $generated !== null && $generatedDeadline - time() <= 50,
        'generation does not stack another five minutes onto cached originals'
    );
    $generated->close();
    verifyRmp(count($calls) === $before + 1, 'regeneration reuses the cached original');
    $before = count($calls);
    freshRmp();
    Timber\ImageHelper::resize(home_url('/rmp-unified-uploads/source.png'), 200, 100);
    $warmReader = imageRmp($url);
    $warmReader->close();
    verifyRmp(count($calls) === $before, 'fresh generated derivative avoids all HTTP');
    $policy = (new ReflectionMethod(WordPress::class, 'cacheControl'))->invoke(null, $generatedDeadline, true);
    verifyRmp(
        str_contains($policy, 'stale-while-revalidate=60') && !str_contains($policy, 'must-revalidate'),
        'REST images enable browser-only stale-while-revalidate'
    );
    $before = count($calls);
    verifyRmp(
        str_ends_with(routeRmp($url), '/source.png') && strlen($recipe['sig']) === 43,
        'readable original filename and compact full-strength signature'
    );
    $tampering = ['sig' => str_repeat('0', 43), 'width' => '201', 'height' => '101',
        'crop' => 'top', 'target' => 'different.png'];
    foreach ($tampering as $key => $value) {
        $bad = add_query_arg($key, $value, $url);
        verifyRmp(
            rest_do_request(requestRmp($bad))->get_status() === 403 && count($calls) === $before,
            'modified ' . $key . ' fails before media access'
        );
    }
    $request = requestRmp(str_replace('/source.png?', '/other.png?', $url));
    verifyRmp(rest_do_request($request)->get_status() === 403 && count($calls) === $before, 'source path is signed');
    $request = requestRmp($url);
    $request->set_query_params(array_reverse($recipe, true));
    $data = rest_do_request($request)->get_data();
    verifyRmp(isset($data['file']), 'query order does not change signature verification');
    $data['file']->close();
    $request = requestRmp($url);
    $request->set_body_params(['width' => '999','target' => 'evil.png']);
    $data = rest_do_request($request)->get_data();
    verifyRmp(isset($data['file']), 'body cannot override signed query values');
    $data['file']->close();
    $request = requestRmp($url);
    $bad = $recipe;
    $bad['width'] = ['200'];
    $request->set_query_params($bad);
    verifyRmp(
        rest_do_request($request)->get_status() === 403 && count($calls) === $before,
        'array parameters rejected before media access'
    );
    $options['password'] = 'changed';
    verifyRmp(
        imageRmp($url) === null && $GLOBALS['last_rmp_status'] === 403 && count($calls) === $before,
        'source credential changes invalidate old signed URLs'
    );
    $options['password'] = 'first';
    $options['enabled'] = false;
    verifyRmp(imageRmp($url) === null && count($calls) === $before, 'disabled proxy cannot serve an old signed recipe');
    $options['enabled'] = true;
    $request = requestRmp($url);
    $request->set_query_params($recipe + ['_envelope' => '1']);
    verifyRmp(
        rest_do_request($request)->get_status() === 403 && count($calls) === $before,
        'JSON response envelopes cannot capture binary readers'
    );
    $request = requestRmp($url);
    $request->set_query_params($recipe + ['source' => 'wrong']);
    $response = rest_do_request($request);
    $data = $response->get_data();
    verifyRmp(
        $response->get_status() === 200 && count($calls) === $before,
        'query parameters cannot override signed route values'
    );
    $data['file']->close();
    $head = imageRmp($url, $unused, 'HEAD');
    verifyRmp($head !== null && count($calls) === $before, 'HEAD resolves the same fresh cached representation');
    $head->close();
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Populate controlled fixtures or intercepted HTTP staging files.
    file_put_contents($root . '/uploads/' . $target, $png);
    verifyRmp(
        bytesRmp(imageRmp($url)) === $png && count($calls) === $before,
        'new local derivative takes precedence through an existing REST URL'
    );
    verifyRmp(
        !str_contains(
            Timber\ImageHelper::resize(home_url('/rmp-unified-uploads/source.png'), 200, 100),
            '/remote-media-proxy/v1/'
        ),
        'existing local derivative keeps its native URL'
    );
    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only test-owned fixtures independently of WordPress deletion filters.
    unlink($root . '/uploads/' . $target);
    $plainFilter = static fn() => '';
    add_filter('pre_option_permalink_structure', $plainFilter);
    $plain = Timber\ImageHelper::resize(home_url('/rmp-unified-uploads/source.png'), '177', 0, false);
    remove_filter('pre_option_permalink_structure', $plainFilter);
    $reader = imageRmp($plain);
    verifyRmp(
        str_contains($plain, 'rest_route=') && $reader !== null,
        'plain permalinks, zero height, string width and false crop round-trip'
    );
    $reader->close();
    $derivativeStatus = 200;
    $unicode = Timber\ImageHelper::resize(home_url('/rmp-unified-uploads/source-é.png'), 179, 89);
    $reader = imageRmp($unicode);
    verifyRmp(
        str_contains(routeRmp($unicode), 'source-%C3%A9.png') && $reader !== null,
        'Unicode source and target filenames round-trip'
    );
    $reader->close();
    $derivativeStatus = 404;
    foreach ([403, 500, 'error'] as $failure) {
        $derivativeStatus = $failure;
        freshRmp();
        $before = count($calls);
        $failureUrl = Timber\ImageHelper::resize(home_url('/rmp-unified-uploads/source.png'), 201, 101);
        verifyRmp(count($calls) === $before, 'error-case render does not probe upstream');
        verifyRmp(
            imageRmp($failureUrl) === null && $GLOBALS['last_rmp_status'] === 502 && count($calls) === $before + 1,
            'no fallback generation after derivative ' . $failure
        );
    }
    $derivativeStatus = 404;
    verifyRmp(glob($root . '/uploads/*') === [], 'generation never writes into uploads');
    require_once ABSPATH . WPINC . '/class-wp-image-editor.php';
    require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';
    /** Reproduce native Timber saving an unchanged image after an editor crop failure. */
    class RmpReviewFailedCrop extends WP_Image_Editor_GD
    {
        public function crop($src_x, $src_y, $src_w, $src_h, $dst_w = null, $dst_h = null, $src_abs = false)
        {
            return new WP_Error('rmp_review_crop_failure', 'Deliberate crop failure');
        }
    }
    $editorFilter = static fn() => [RmpReviewFailedCrop::class];
    add_filter('wp_image_editors', $editorFilter, PHP_INT_MAX);
    try {
        freshRmp();
        $cropUrl = Timber\ImageHelper::resize(home_url('/rmp-unified-uploads/source.png'), 155, 77);
        $probe = imageRmp($cropUrl);
        verifyRmp(
            $probe === null && $GLOBALS['last_rmp_status'] === 502,
            'failed native cropping cannot publish the original as a derivative'
        );
        $probe?->close();
    } finally {
        remove_filter('wp_image_editors', $editorFilter, PHP_INT_MAX);
    }
    verifyRmp(
        imageSizeRmp($cropUrl) === [155, 77],
        'failed cropping does not poison the cache and a later attempt can recover'
    );
    $dimensionCases = [[0, 81, 162, 81], [161, 0, 161, 81], [320, 160, 320, 160],
        [401, 201, 401, 201], [101.6, 50.6, 102, 51]];
    foreach ($dimensionCases as [$w, $h, $ew, $eh]) {
        freshRmp();
        $dimensionUrl = Timber\ImageHelper::resize(home_url('/rmp-unified-uploads/source.png'), $w, $h, false);
        verifyRmp(
            imageSizeRmp($dimensionUrl) === [$ew, $eh],
            'native PNG dimensions survive inference, no-op, upscaling and rounding'
        );
    }
    foreach (['default','center','top','bottom','left','right','top-center','bottom-center'] as $crop) {
        freshRmp();
        $reader = imageRmp(Timber\ImageHelper::resize(home_url('/rmp-unified-uploads/source.png'), 101, 51, $crop));
        verifyRmp($reader !== null, 'native crop position remains supported: ' . $crop);
        $reader?->close();
    }
    $probeFile = TemporaryFile::create();
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Populate controlled fixtures or intercepted HTTP staging files.
    file_put_contents($probeFile, 'review');
    $probeReader = MediaFile::open($probeFile, 'image/png');
    $adapter = (new ReflectionClass(Adapter::class))->newInstanceWithoutConstructor();
    // This deliberately non-removable buffer lasts until this isolated CLI process exits.
    ob_start(null, 0, PHP_OUTPUT_HANDLER_CLEANABLE | PHP_OUTPUT_HANDLER_FLUSHABLE);
    $upperRequest = requestRmp(str_replace('/timber/resize/', '/TIMBER/RESIZE/', $url));
    $upperResponse = rest_do_request($upperRequest);
    $upperData = $upperResponse->get_data();
    $upperServed = $adapter->serveImage(false, $upperResponse, $upperRequest);
    verifyRmp(
        !$upperServed && $upperResponse->get_status() === 500 && wp_json_encode($upperResponse->get_data()) !== false,
        'uppercase routes reach binary handling and produce serializable delivery errors'
    );
    ($upperData['file'] ?? null)?->close();
    $response = new WP_REST_Response(['file' => $probeReader,'expires' => time() + 300]);
    $request = new WP_REST_Request('GET', '/remote-media-proxy/v1/timber/resize/source.png');
    $served = $adapter->serveImage(false, $response, $request);
    verifyRmp(
        !$served
        && $response->get_status() === 500
        && !is_resource($probeReader->stream)
        && !isset($response->get_data()['file']),
        'failed binary delivery closes the reader and reports failure without serializing it'
    );
    TemporaryFile::remove($probeFile);
    $jpeg = imagecreatetruecolor(320, 160);
    ob_start();
    imagejpeg($jpeg);
    $body = ob_get_clean();
    imagedestroy($jpeg);
    freshRmp();
    $jpegUrl = Timber\ImageHelper::resize(home_url('/rmp-unified-uploads/source.jpg'), 200, 100);
    $jpegReader = imageRmp($jpegUrl);
    verifyRmp(
        $jpegReader !== null
        && wp_getimagesize(stream_get_meta_data($jpegReader->stream)['uri'])['mime'] === 'image/jpeg',
        'native JPEG generation works with direct staging files'
    );
    $jpegReader->close();
    $convert = static fn($formats) => ['image/jpeg' => 'image/webp'];
    add_filter('image_editor_output_format', $convert, PHP_INT_MAX);
    freshRmp();
    $converted = Timber\ImageHelper::resize(home_url('/rmp-unified-uploads/source.jpg'), 123, 61);
    $convertedReader = imageRmp($converted);
    remove_filter('image_editor_output_format', $convert, PHP_INT_MAX);
    verifyRmp($convertedReader === null, 'unexpected editor format cannot masquerade as the requested JPEG');
    verifyRmp(
        glob($directory . '/remote-media-proxy-*') === [],
        'unexpected editor output leaves no staging siblings behind'
    );
    if (class_exists(Imagick::class)) {
        $animation = new Imagick();
        foreach (['red', 'blue'] as $color) {
            $frame = new Imagick();
            $frame->newImage(320, 160, $color);
            $frame->setImageFormat('gif');
            $frame->setImageDelay(20);
            $animation->addImage($frame);
        }
        $animation->setImageFormat('gif');
        $body = $animation->getImagesBlob();
        freshRmp();
        $gifUrl = Timber\ImageHelper::resize(home_url('/rmp-unified-uploads/source.gif'), 200, 100);
        $gifReader = imageRmp($gifUrl);
        verifyRmp($gifReader !== null, 'native GIF generation has a correctly suffixed cached source');
        $gif = new Imagick(stream_get_meta_data($gifReader->stream)['uri']);
        verifyRmp(
            $gif->getNumberImages() === 2 && $gif->getImageWidth() === 200 && $gif->getImageHeight() === 100,
            'native animated GIF retains both resized frames'
        );
        $gifReader->close();
        foreach ([[0,81,162,81],[161,0,161,81],[101.6,50.6,101,50]] as [$w,$h,$ew,$eh]) {
            freshRmp();
            $dimensionUrl = Timber\ImageHelper::resize(home_url('/rmp-unified-uploads/source.gif'), $w, $h);
            verifyRmp(
                imageSizeRmp($dimensionUrl) === [$ew, $eh],
                'native animated GIF preserves inference and integer coercion'
            );
        }
    }
    $owned = TemporaryFile::create();
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Populate controlled fixtures or intercepted HTTP staging files.
    file_put_contents($owned, 'opaque');
    $inode = fileinode($owned);
    $opaque = new FileCache('ownership-test');
    verifyRmp(
        is_int($opaque->publish('owned', $owned)) && !file_exists($owned) && !TemporaryFile::owns($owned),
        'publication relinquishes request ownership'
    );
    $retained = $opaque->open('owned', 'text/plain', 300);
    $retainedPath = stream_get_meta_data($retained->stream)['uri'];
    verifyRmp(fileinode($retainedPath) === $inode, 'publication moves the existing inode without copying bytes');
    $retained->close();
    $foreign = $root . '/foreign';
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Populate controlled fixtures or intercepted HTTP staging files.
    file_put_contents($foreign, 'untouched');
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Force exact fixture permissions to simulate host changes.
    chmod($foreign, 0600);
    verifyRmp(
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Read only this local fixture, never a remote URL.
        $opaque->publish('foreign', $foreign) === null && file_get_contents($foreign) === 'untouched',
        'cache cannot consume unowned files'
    );
    $variant = TemporaryFile::create('jpg', $directory);
    $alternative = substr($variant, 0, -3) . 'webp';
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Populate controlled fixtures or intercepted HTTP staging files.
    file_put_contents($alternative, 'alternate');
    TemporaryFile::remove($variant);
    verifyRmp(
        !file_exists($variant) && !file_exists($alternative),
        'native format variants are cleaned without per-operation directories'
    );
    verifyRmp(glob($directory . '/.*.lock') === [], 'no lock files are created');
    $odd = $root . '/[working]*?';
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Create only isolated test-owned fixture directories.
    mkdir($odd, 0700);
    $variant = TemporaryFile::create('jpg', $odd);
    $alternative = substr($variant, 0, -3) . 'webp';
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Populate controlled fixtures or intercepted HTTP staging files.
    file_put_contents($alternative, 'alternate');
    TemporaryFile::remove($variant);
    verifyRmp(
        !file_exists($variant) && !file_exists($alternative),
        'staging cleanup handles directory glob metacharacters safely'
    );
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only test-owned fixtures independently of WordPress deletion filters.
    rmdir($odd);
    TemporaryFile::cleanup();
    verifyRmp(
        is_file($retainedPath)
        && glob($root . '/remote-media-proxy-*.tmp') === []
        && glob($root . '/remote-media-proxy-*.tmp.d') === [],
        'shutdown cleanup removes only remaining working files and workspaces'
    );
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only test diagnostics, not HTML output.
    echo 'PASSED ' . $GLOBALS['rmp_test_count'] . ' checks; Timber ' . Timber\Timber::$version . "\n";
} finally {
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Force exact fixture permissions to simulate host changes.
    chmod($root, 0700);
    if (isset($directory) && is_dir($directory)) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Force exact fixture permissions to simulate host changes.
        chmod($directory, 0700);
    }
    TemporaryFile::cleanup();
    remove_filter('remote_media_proxy_options', $optFilter, PHP_INT_MAX);
    remove_filter('upload_dir', $uploadFilter, PHP_INT_MIN);
    remove_filter('pre_http_request', $httpFilter, PHP_INT_MIN);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $path) {
        if ($path->isDir()) {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only test-owned fixtures independently of WordPress deletion filters.
            rmdir($path->getPathname());
        } else {
            // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Remove only test-owned fixtures independently of WordPress deletion filters.
            unlink($path->getPathname());
        }
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Remove only test-owned fixtures independently of WordPress deletion filters.
    rmdir($root);
}
