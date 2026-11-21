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
$response = new Response(400, ['x-request-id' => 'req-test-123'], json_encode(['error' => ['message' => 'Unsupported size 1272x1608 for this model.', 'type' => 'invalid_request_error', 'code' => 'invalid_size', 'param' => 'size']]));
$response->getBody()->seek(9);
$e = new RequestException('Never show exception SECRET', $request, $response);
$text = $method->invoke($service, new RuntimeException('wrapper SECRET', 0, $e), 'Fallback');
foreach (['Unsupported size 1272x1608', 'invalid_request_error', 'invalid_size', 'Parameter: size', 'req-test-123'] as $detail) {
    $check('useful provider detail: ' . $detail, str_contains($text, $detail));
}
$check('error stream cursor preserved', $response->getBody()->tell() === 9);
$check('repeated diagnostics remain stable', $text === $method->invoke($service, $e, 'Fallback'));
foreach ([
    ['error' => ['message' => 'Invalid image: SECRET https://private.test/image?token=secret sk-exampleSecret /private/path/image.png', 'param' => 'image']],
    ['error' => 'SECRET invalid image'],
    ['message' => 'Invalid input SECRET', 'code' => 'bad_input'],
] as $payload) {
    $e = new RequestException('SECRET', $request, new Response(400, [], json_encode($payload)));
    $text = $method->invoke($service, $e, 'Fallback');
    $check('redact sensitive content while preserving reason', str_contains($text, 'Reason:') && !str_contains($text, 'SECRET') && !str_contains($text, 'https://') && !str_contains($text, 'exampleSecret') && !str_contains($text, '/private/'));
}
$e = new RequestException('SECRET', $request, new Response(400, [], json_encode(['error' => ['message' => 'Refused: private editorial instructions']])));
$text = $method->invoke($service, $e, 'Fallback', ['private editorial instructions']);
$check('explicit instructions are removed', !str_contains($text, 'private editorial instructions'));
foreach (['<html>SECRET</html>', '{invalid JSON', json_encode(['error'=>['message'=>['SECRET']]]), str_repeat('x', 70000)] as $body) {
    $text = $method->invoke($service, new RequestException('SECRET', $request, new Response(400, [], $body)), 'Fallback');
    $check('unstructured response safely falls back', str_contains($text, 'no usable structured error') && !str_contains($text, 'SECRET'));
}
$body = json_encode(['error'=>['message'=>str_repeat('Invalid image. ', 1000)], 'request'=>['prompt'=>'SECRET']]);
$text = $method->invoke($service, new RequestException('SECRET', $request, new Response(400, [], $body)), 'Fallback');
$check('long reasons are bounded and unrelated fields excluded', strlen($text)<1200 && !str_contains($text, 'SECRET'));
$google = new Request('POST', 'https://example.test/?key=GOOGLE_SECRET', ['X-Goog-Api-Key'=>'GOOGLE_SECRET']);
$text = $method->invoke($service, new RequestException('SECRET', $google, new Response(400, ['request-id'=>'req-google'], json_encode(['error'=>['code'=>400,'status'=>'INVALID_ARGUMENT','message'=>'Invalid image GOOGLE_SECRET']]))), 'Fallback');
$check('Google error shape and key redaction', str_contains($text,'INVALID_ARGUMENT') && str_contains($text,'req-google') && !str_contains($text,'GOOGLE_SECRET'));
exit($failures ? 1 : 0);
