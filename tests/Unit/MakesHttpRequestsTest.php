<?php

use GuzzleHttp\Psr7\Response;
use WebmanTech\Testing\Http\TestResponse;
use WebmanTech\Testing\TestCase;

/*
 * MakesHttpRequests trait 的请求构造行为（headers 合并、JSON 选项等）；
 * 通过 stub webmanRequest 捕获参数验证，真实 HTTP 发送路径由 e2e 覆盖。
 */

/**
 * 捕获 webmanSend 最终请求参数的测试替身（组装逻辑仍走 trait 真实代码）
 */
class RequestCaptureTestCase extends TestCase
{
    public array $captured = [];

    protected function webmanSend(string $method, string $uri, array $options): TestResponse
    {
        $this->captured[] = ['method' => $method, 'uri' => $uri, 'options' => $options];

        return new TestResponse(new Response(200, [], '{"ok":true}'));
    }
}

function request_capture_new(): RequestCaptureTestCase
{
    // PHPUnit TestCase 构造需要 $name；pest extend 绑定场景由 pest 编译的测试子类实例化，不走这里
    return new RequestCaptureTestCase('capture');
}

test('get/getJson/post/put/patch/delete 映射到对应方法与 body 选项', function () {
    $case = request_capture_new();

    $case->get('/uri');
    $case->getJson('/uri');
    $case->post('/uri', ['a' => 1]);
    $case->postJson('/uri', ['a' => 1]);
    $case->put('/uri', ['a' => 1]);
    $case->putJson('/uri', ['a' => 1]);
    $case->patch('/uri', ['a' => 1]);
    $case->patchJson('/uri', ['a' => 1]);
    $case->delete('/uri');
    $case->deleteJson('/uri');

    [$get, $getJson, $post, $postJson, $put, $putJson, $patch, $patchJson, $delete, $deleteJson] = $case->captured;

    expect($get['method'])->toBe('GET')
        ->and($post['options'])->toHaveKey('form_params')
        ->and($post['options']['form_params'])->toBe(['a' => 1])
        ->and($postJson['options'])->toHaveKey('json')
        ->and($put['options'])->toHaveKey('form_params')
        ->and($putJson['options'])->toHaveKey('json')
        ->and($patch['options'])->toHaveKey('form_params')
        ->and($patchJson['options'])->toHaveKey('json')
        // json 变体与 form 变体的 body 选项不同
        ->and($postJson['options']['json'])->toBe(['a' => 1])
        // *Json 后缀带 Accept: application/json
        ->and($getJson['options']['headers'])->toBe(['Accept' => 'application/json'])
        ->and($deleteJson['options']['headers'])->toBe(['Accept' => 'application/json'])
        ->and($get['options']['headers'])->toBe([]);
});

test('withToken/withHeaders 写入默认 headers 并跨请求复用', function () {
    $case = request_capture_new();

    $case->withToken('token-1')->withHeaders(['X-Custom' => 'v'])->get('/uri');
    $case->get('/other');

    [$first, $second] = $case->captured;

    expect($first['options']['headers'])->toBe([
        'Authorization' => 'Bearer token-1',
        'X-Custom' => 'v',
    ])
        // 默认 headers 在同一实例的后续请求中仍然生效
        ->and($second['options']['headers'])->toBe([
            'Authorization' => 'Bearer token-1',
            'X-Custom' => 'v',
        ]);
});

test('withToken 支持自定义 type', function () {
    $case = request_capture_new();

    $case->withToken('key', 'ApiKey')->get('/uri');

    expect($case->captured[0]['options']['headers']['Authorization'])->toBe('ApiKey key');
});

test('请求级 headers 与默认 headers 合并（请求级优先）', function () {
    $case = request_capture_new();

    $case->withHeaders(['Accept' => 'text/html', 'X-Keep' => 'yes'])->getJson('/uri');

    expect($case->captured[0]['options']['headers'])->toBe([
        // 请求级 Accept 覆盖默认值
        'Accept' => 'application/json',
        'X-Keep' => 'yes',
    ]);
});

test('actingViaToken 为 withToken 的语义别名', function () {
    $case = request_capture_new();

    $case->actingViaToken('token-1')->get('/uri');

    expect($case->captured[0]['options']['headers']['Authorization'])->toBe('Bearer token-1');
});

test('head/options/optionsJson/json 映射到对应方法与 body 选项', function () {
    $case = request_capture_new();

    $case->head('/uri');
    $case->options('/uri', ['a' => 1]);
    $case->optionsJson('/uri', ['a' => 1]);
    $case->json('PATCH', '/uri', ['a' => 1]);

    [$head, $options, $optionsJson, $rawJson] = $case->captured;

    expect($head['method'])->toBe('HEAD')
        ->and($options['method'])->toBe('OPTIONS')
        ->and($options['options'])->toHaveKey('form_params')
        ->and($optionsJson['options'])->toHaveKey('json')
        ->and($rawJson['method'])->toBe('PATCH')
        ->and($rawJson['options']['json'])->toBe(['a' => 1]);
});

test('withHeader/withoutHeader/withoutHeaders/flushHeaders 管理默认 headers', function () {
    $case = request_capture_new();

    $case->withHeader('X-A', '1')->withHeader('X-B', '2')->get('/uri');
    expect($case->captured[0]['options']['headers'])->toBe(['X-A' => '1', 'X-B' => '2']);

    $case->withoutHeader('X-A')->get('/uri');
    expect($case->captured[1]['options']['headers'])->toBe(['X-B' => '2']);

    $case->withoutHeaders(['X-B'])->get('/uri');
    expect($case->captured[2]['options']['headers'])->toBe([]);

    $case->withHeader('X-C', '3')->flushHeaders()->get('/uri');
    expect($case->captured[3]['options']['headers'])->toBe([]);
});

test('withBasicAuth/withoutToken 管理 Authorization', function () {
    $case = request_capture_new();

    $case->withBasicAuth('user', 'pass')->get('/uri');
    expect($case->captured[0]['options']['headers']['Authorization'])->toBe('Basic ' . base64_encode('user:pass'));

    $case->withToken('t')->withoutToken()->get('/uri');
    expect($case->captured[1]['options']['headers'])->not->toHaveKey('Authorization');
});

test('withCookie(s) 合并为 Cookie header（URL 编码）', function () {
    $case = request_capture_new();

    $case->withCookie('name', 'value 1')->withCookies(['other' => 'v=2'])->get('/uri');

    expect($case->captured[0]['options']['headers']['Cookie'])->toBe('name=value%201; other=v%3D2');
});

test('withUnencryptedCookie 为 withCookie 别名（webman 无加密）', function () {
    $case = request_capture_new();

    $case->withUnencryptedCookie('name', 'v')->get('/uri');

    expect($case->captured[0]['options']['headers']['Cookie'])->toBe('name=v');
});

test('默认不跟随重定向，followingRedirects 开启 allow_redirects', function () {
    $case = request_capture_new();

    $case->get('/uri');
    expect($case->captured[0]['options']['allow_redirects'])->toBeFalse();

    $case->followingRedirects()->get('/uri');
    expect($case->captured[1]['options']['allow_redirects'])->toHaveKey('max');

    $case->followingRedirects(2)->get('/uri');
    expect($case->captured[2]['options']['allow_redirects']['max'])->toBe(2);
});

test('from 写入 Referer header', function () {
    $case = request_capture_new();

    $case->from('https://example.com')->get('/uri');

    expect($case->captured[0]['options']['headers']['Referer'])->toBe('https://example.com');
});
