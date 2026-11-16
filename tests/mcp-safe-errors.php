<?php
/** Safe durable and queue diagnostics, without a Craft application or provider requests. */
require __DIR__ . '/../vendor/autoload.php';
use arjanbrinkman\craftimageenhancer\services\McpEnhancements;
use arjanbrinkman\craftimageenhancer\exceptions\EnhancementException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
$service = (new ReflectionClass(McpEnhancements::class))->newInstanceWithoutConstructor();
$method = new ReflectionMethod($service, 'safeError');
$request = new Request('POST', 'https://example.test/?token=SECRET', ['Authorization' => 'Bearer SECRET']);
$failures = 0;
$check = function ($name, $ok) use (&$failures) {echo ($ok ? 'ok ' : 'FAIL ') . $name . PHP_EOL; $failures += !$ok;};
foreach ([400, 401, 403, 404, 422, 429, 500, 503] as $code) {
    $e = new RequestException('SECRET', $request, new Response($code, [], '{"error":{"message":"SECRET"}}'));
    $text = $method->invoke($service, new RuntimeException('SECRET', 0, $e), 'Fallback');
    $check('HTTP ' . $code . ' survives wrapping without raw details', str_contains($text, 'HTTP ' . $code) && !str_contains($text, 'SECRET') && str_contains($text, 'No automatic paid retry'));
}
$text = $method->invoke($service, new ConnectException('SECRET', $request), 'Fallback');
$check('connection failures retain uncertainty', str_contains($text, 'outcome is unknown') && !str_contains($text, 'SECRET'));
$text = $method->invoke($service, new RuntimeException('/private/SECRET'), 'Storage failed.');
$check('unexpected errors retain category without sensitive messages', str_contains($text, 'RuntimeException') && !str_contains($text, 'SECRET'));
$check('trusted validation errors remain readable', $method->invoke($service, new EnhancementException('Image dimensions too small.'), 'Fallback') === 'Image dimensions too small.');
$text = $method->invoke($service, new class('SECRET') extends RuntimeException {}, 'Fallback');
$check('anonymous exception types do not leak their source path', !str_contains($text, __FILE__) && !str_contains($text, 'SECRET') && str_contains($text, 'anonymous exception'));
exit($failures ? 1 : 0);
