<?php

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\AssertionFailedError;
use WebmanTech\Testing\Http\TestResponse;

/*
 * TestResponse 的断言与取值（直接构造 PSR-7 Response；真实 HTTP 路径由 e2e 覆盖）
 */

function testing_response(int $status = 200, array $headers = [], string $body = ''): TestResponse
{
    return new TestResponse(new Response($status, $headers, $body));
}

test('status 与基础断言', function () {
    expect(testing_response()->assertStatus(200)->assertOk()->assertSuccessful()->status())->toBe(200)
        ->and(testing_response(201)->assertSuccessful()->status())->toBe(201)
        ->and(testing_response(404)->assertNotFound()->status())->toBe(404)
        ->and(testing_response(401)->assertUnauthorized()->status())->toBe(401)
        ->and(testing_response(403)->assertForbidden()->status())->toBe(403)
        ->and(testing_response()->content())->toBe('')
        ->and(testing_response(200, ['X-Foo' => 'bar'])->header('X-Foo'))->toBe('bar')
        ->and(testing_response(200, ['X-Foo' => 'bar'])->headers())->toBe(['X-Foo' => ['bar']])
        // 断言失败抛 phpunit 断言失败（pest/phpunit 统一行为）
        ->and(fn() => testing_response(500)->assertStatus(200))->toThrow(AssertionFailedError::class)
        ->and(fn() => testing_response(500)->assertSuccessful())->toThrow(AssertionFailedError::class);
});

test('json dot 路径取值', function () {
    $response = testing_response(200, ['Content-Type' => 'application/json'], (string)json_encode([
        'status' => 0,
        'data' => [
            'total' => 2,
            'list' => [
                ['id' => 1, 'username' => 'u1'],
                ['id' => 2, 'username' => 'u2'],
            ],
        ],
    ]));

    expect($response->json('status'))->toBe(0)
        ->and($response->json('data.total'))->toBe(2)
        ->and($response->json('data.list.0.id'))->toBe(1)
        ->and($response->json('data.list.1.username'))->toBe('u2')
        ->and($response->json()['data']['list'][0]['id'])->toBe(1) // 不传 key 返回全量
        // 路径不存在抛可读异常
        ->and(fn() => $response->json('data.not-exists'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $response->json('data.list.5'))->toThrow(InvalidArgumentException::class)
        ->and(fn() => $response->json('status.nested'))->toThrow(InvalidArgumentException::class);
});

test('非 JSON 响应 json() 抛可读异常', function () {
    $response = testing_response(200, ['Content-Type' => 'text/html'], '<html><body>ok</body></html>');

    expect(fn() => $response->json())->toThrow(InvalidArgumentException::class)
        ->and(fn() => $response->json('any'))->toThrow(InvalidArgumentException::class);
});

test('assertJson 递归子集匹配', function () {
    $response = testing_response(200, [], (string)json_encode([
        'status' => 0,
        'msg' => 'ok',
        'data' => ['id' => 1, 'name' => 'x', 'extra' => ['a' => 1, 'b' => 2]],
    ]));

    expect($response->assertJson(['status' => 0])->assertJson(['data' => ['id' => 1]]))
        ->toBeInstanceOf(TestResponse::class)
        // 嵌套子集
        ->and($response->assertJson(['data' => ['extra' => ['a' => 1]]]))->toBeInstanceOf(TestResponse::class)
        // 值不匹配 / key 缺失失败
        ->and(fn() => $response->assertJson(['status' => 1]))->toThrow(AssertionFailedError::class)
        ->and(fn() => $response->assertJson(['data' => ['id' => 2]]))->toThrow(AssertionFailedError::class)
        ->and(fn() => $response->assertJson(['missing' => 1]))->toThrow(AssertionFailedError::class);
});

test('assertJsonPath 与 assertJsonCount', function () {
    $response = testing_response(200, [], (string)json_encode([
        'status' => 0,
        'data' => ['list' => [['id' => 1], ['id' => 2], ['id' => 3]]],
    ]));

    expect($response->assertJsonPath('status', 0)->assertJsonPath('data.list.0.id', 1))
        ->toBeInstanceOf(TestResponse::class)
        // Closure：以实际值为参数，返回真值即通过
        ->and($response->assertJsonPath('status', fn($status) => $status === 0))
        ->toBeInstanceOf(TestResponse::class)
        ->and($response->assertJsonCount(3, 'data.list')->assertJsonCount(2))->toBeInstanceOf(TestResponse::class)
        ->and(fn() => $response->assertJsonPath('status', 1))->toThrow(AssertionFailedError::class)
        ->and(fn() => $response->assertJsonPath('status', fn($status) => $status === 1))->toThrow(AssertionFailedError::class)
        ->and(fn() => $response->assertJsonCount(2, 'data.list'))->toThrow(AssertionFailedError::class);
});

test('assertJsonStructure 支持嵌套与通配', function () {
    $response = testing_response(200, [], (string)json_encode([
        'status' => 0,
        'data' => [
            'total' => 2,
            'list' => [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']],
        ],
    ]));

    // null 仅断言合法 JSON
    expect($response->assertJsonStructure())->toBeInstanceOf(TestResponse::class)
        // 简单结构
        ->and($response->assertJsonStructure(['status', 'data' => ['total']]))->toBeInstanceOf(TestResponse::class)
        // * 通配所有列表元素
        ->and($response->assertJsonStructure(['data' => ['list' => ['*' => ['id', 'name']]]]))->toBeInstanceOf(TestResponse::class)
        // key 缺失败
        ->and(fn() => $response->assertJsonStructure(['missing']))->toThrow(AssertionFailedError::class)
        ->and(fn() => $response->assertJsonStructure(['data' => ['list' => ['*' => ['id', 'missing']]]]))->toThrow(AssertionFailedError::class);
});

test('assertHeader 与 assertRedirect', function () {
    $redirect = testing_response(302, ['Location' => '/login']);

    expect($redirect->assertRedirect()->assertRedirect('/login'))->toBeInstanceOf(TestResponse::class)
        ->and(testing_response(200, ['X-Token' => 'abc'])->assertHeader('X-Token')->assertHeader('X-Token', 'abc'))
        ->toBeInstanceOf(TestResponse::class)
        ->and(fn() => testing_response(302, ['Location' => '/login'])->assertRedirect('/home'))->toThrow(AssertionFailedError::class)
        ->and(fn() => testing_response(200)->assertRedirect())->toThrow(AssertionFailedError::class)
        ->and(fn() => testing_response(200)->assertHeader('X-Missing'))->toThrow(AssertionFailedError::class);
});

test('assertCookie 从 Set-Cookie 解析', function () {
    $response = testing_response(200, [
        'Set-Cookie' => [
            'token=abc123; Path=/; HttpOnly',
            'other=xyz',
        ],
    ]);

    expect($response->assertCookie('token')->assertCookie('token', 'abc123')->assertCookie('other', 'xyz'))
        ->toBeInstanceOf(TestResponse::class)
        ->and(fn() => $response->assertCookie('missing'))->toThrow(AssertionFailedError::class)
        ->and(fn() => $response->assertCookie('token', 'wrong'))->toThrow(AssertionFailedError::class);
});

test('assertSee', function () {
    $response = testing_response(200, [], '<h1>你好 world</h1>');

    expect($response->assertSee('你好')->assertSee('world'))->toBeInstanceOf(TestResponse::class)
        ->and(fn() => $response->assertSee('missing'))->toThrow(AssertionFailedError::class);
});

test('状态码便捷断言（created/noContent/conflict/unprocessable/clientError/serverError 等）', function () {
    expect(testing_response(201)->assertCreated()->status())->toBe(201)
        ->and(testing_response(202)->assertAccepted()->status())->toBe(202)
        ->and(testing_response(204)->assertNoContent()->status())->toBe(204)
        ->and(testing_response(301)->assertMovedPermanently()->status())->toBe(301)
        ->and(testing_response(302)->assertFound()->status())->toBe(302)
        ->and(testing_response(409)->assertConflict()->status())->toBe(409)
        ->and(testing_response(422)->assertUnprocessable()->status())->toBe(422)
        ->and(testing_response(429)->assertTooManyRequests()->status())->toBe(429)
        ->and(testing_response(404)->assertClientError()->status())->toBe(404)
        ->and(testing_response(500)->assertServerError()->status())->toBe(500)
        ->and(fn() => testing_response(404)->assertServerError())->toThrow(AssertionFailedError::class)
        ->and(fn() => testing_response(500)->assertClientError())->toThrow(AssertionFailedError::class);
});

test('assertSeeText/assertDontSeeText 忽略 HTML 标签', function () {
    $response = testing_response(200, [], '<h1>标题</h1><p>内容 &amp; 更多</p>');

    expect($response->assertSeeText('标题'))->toBeInstanceOf(TestResponse::class)
        ->and($response->assertSeeText('内容'))->toBeInstanceOf(TestResponse::class)
        ->and(fn() => $response->assertDontSeeText('标题'))->toThrow(AssertionFailedError::class)
        // escape=true 时对 value 做 HTML 转义后匹配（&amp; 在响应中的渲染形态）
        ->and($response->assertSeeText('&'))->toBeInstanceOf(TestResponse::class)
        ->and(fn() => $response->assertSeeText('<h1>'))->toThrow(AssertionFailedError::class);
});

test('assertSeeInOrder/assertSeeTextInOrder 按出现顺序断言', function () {
    $response = testing_response(200, [], '<h1>a</h1><p>b</p>c');

    expect($response->assertSeeInOrder(['a', 'b', 'c']))->toBeInstanceOf(TestResponse::class)
        ->and(fn() => $response->assertSeeInOrder(['c', 'a']))->toThrow(AssertionFailedError::class)
        ->and($response->assertSeeTextInOrder(['a', 'b', 'c']))->toBeInstanceOf(TestResponse::class);
});

test('assertExactJson/assertSimilarJson 完全一致与宽松一致', function () {
    $response = testing_response(200, [], '{"id":1,"name":"x"}');

    expect($response->assertExactJson(['id' => 1, 'name' => 'x']))->toBeInstanceOf(TestResponse::class)
        // key 顺序无关
        ->and($response->assertExactJson(['name' => 'x', 'id' => 1]))->toBeInstanceOf(TestResponse::class)
        ->and(fn() => $response->assertExactJson(['id' => 1]))->toThrow(AssertionFailedError::class)
        // 类型宽松
        ->and($response->assertSimilarJson(['id' => '1', 'name' => 'x']))->toBeInstanceOf(TestResponse::class);
});

test('assertJsonFragment/assertJsonMissing 任意深度匹配', function () {
    $response = testing_response(200, [], '{"data":{"user":{"name":"webman","roles":["admin"]}}}');

    expect($response->assertJsonFragment(['name' => 'webman']))->toBeInstanceOf(TestResponse::class)
        ->and($response->assertJsonFragment(['roles' => ['admin']]))->toBeInstanceOf(TestResponse::class)
        ->and(fn() => $response->assertJsonFragment(['name' => 'missing']))->toThrow(AssertionFailedError::class)
        ->and($response->assertJsonMissing(['name' => 'missing']))->toBeInstanceOf(TestResponse::class)
        ->and(fn() => $response->assertJsonMissing(['name' => 'webman']))->toThrow(AssertionFailedError::class);
});

test('assertJsonMissingExact/assertJsonMissingPath/assertJsonIsArray/assertJsonIsObject', function () {
    $response = testing_response(200, [], '{"data":[1,2,3]}');

    expect($response->assertJsonMissingExact(['data' => []]))->toBeInstanceOf(TestResponse::class)
        ->and(fn() => $response->assertJsonMissingExact(['data' => [1, 2, 3]]))->toThrow(AssertionFailedError::class)
        ->and($response->assertJsonMissingPath('not.exists'))->toBeInstanceOf(TestResponse::class)
        ->and(fn() => $response->assertJsonMissingPath('data'))->toThrow(AssertionFailedError::class);

    $array = testing_response(200, [], '[1,2]');
    expect($array->assertJsonIsArray())->toBeInstanceOf(TestResponse::class)
        ->and(fn() => $array->assertJsonIsObject())->toThrow(AssertionFailedError::class)
        ->and($response->assertJsonIsObject())->toBeInstanceOf(TestResponse::class);
});

test('assertExactJsonStructure 不允许额外字段', function () {
    $response = testing_response(200, [], '{"code":0,"data":{"id":1,"name":"x"}}');

    expect($response->assertExactJsonStructure(['code', 'data' => ['id', 'name']]))->toBeInstanceOf(TestResponse::class)
        ->and(fn() => $response->assertExactJsonStructure(['code']))->toThrow(AssertionFailedError::class)
        ->and(fn() => $response->assertExactJsonStructure(['code', 'data' => ['id']]))->toThrow(AssertionFailedError::class);
});

test('assertJsonValidationErrors 系列（422 响应结构）', function () {
    $response = testing_response(422, [], '{"code":422,"errors":{"name":["name 不能为空"],"age":["age 不合法","age 超出范围"]}}');

    expect($response->assertUnprocessable())->toBeInstanceOf(TestResponse::class)
        ->and($response->assertJsonValidationErrors('name'))->toBeInstanceOf(TestResponse::class)
        ->and($response->assertJsonValidationErrors(['name', 'age']))->toBeInstanceOf(TestResponse::class)
        // 断言具体错误消息
        ->and($response->assertJsonValidationErrors(['name' => 'name 不能为空']))->toBeInstanceOf(TestResponse::class)
        ->and($response->assertJsonValidationErrorFor('age'))->toBeInstanceOf(TestResponse::class)
        ->and($response->assertInvalid(['age' => 'age 超出范围']))->toBeInstanceOf(TestResponse::class)
        ->and(fn() => $response->assertJsonValidationErrors('missing'))->toThrow(AssertionFailedError::class)
        ->and(fn() => $response->assertJsonValidationErrors(['name' => '不存在的消息']))->toThrow(AssertionFailedError::class);

    $ok = testing_response(200, [], '{"code":0}');
    expect($ok->assertJsonMissingValidationErrors())->toBeInstanceOf(TestResponse::class)
        ->and($ok->assertValid())->toBeInstanceOf(TestResponse::class)
        // 响应缺少 errors 字段视为无验证错误
        ->and(fn() => $response->assertJsonMissingValidationErrors())->toThrow(AssertionFailedError::class);
});

test('assertHeaderContains/assertHeaderMissing/assertLocation/assertRedirectContains', function () {
    $response = testing_response(302, ['Location' => '/login?from=/home', 'X-Msg' => 'hello-world']);

    expect($response->assertHeaderContains('X-Msg', 'hello'))->toBeInstanceOf(TestResponse::class)
        ->and($response->assertHeaderMissing('X-Not-Exist'))->toBeInstanceOf(TestResponse::class)
        ->and(fn() => $response->assertHeaderMissing('Location'))->toThrow(AssertionFailedError::class)
        ->and($response->assertRedirect()->assertRedirectContains('/login'))->toBeInstanceOf(TestResponse::class)
        ->and($response->assertLocation('/login?from=/home'))->toBeInstanceOf(TestResponse::class);
});

test('cookie 断言：missing/expired/notExpired/getCookie/plainCookie', function () {
    $expired = testing_response(200, ['Set-Cookie' => 'token=abc; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Path=/']);
    $alive = testing_response(200, ['Set-Cookie' => 'token=abc; Expires=Thu, 01 Jan 2099 00:00:00 GMT; Path=/; HttpOnly']);

    expect($expired->assertCookieExpired('token'))->toBeInstanceOf(TestResponse::class)
        ->and(fn() => $alive->assertCookieExpired('token'))->toThrow(AssertionFailedError::class)
        ->and($alive->assertCookieNotExpired('token'))->toBeInstanceOf(TestResponse::class)
        ->and(fn() => $expired->assertCookieNotExpired('token'))->toThrow(AssertionFailedError::class)
        ->and($alive->getCookie('token'))->toBe('abc')
        ->and($alive->getCookie('token', true))->toMatchArray(['path' => '/', 'httponly' => true])
        ->and($alive->assertPlainCookie('token', 'abc'))->toBeInstanceOf(TestResponse::class)
        ->and(testing_response(200, ['Set-Cookie' => 'other=1'])->assertCookieMissing('token'))->toBeInstanceOf(TestResponse::class);
});

test('assertContent/assertContentType', function () {
    $response = testing_response(200, ['Content-Type' => 'application/json'], '{"a":1}');

    expect($response->assertContent('{"a":1}'))->toBeInstanceOf(TestResponse::class)
        ->and($response->assertContentType('application/json'))->toBeInstanceOf(TestResponse::class)
        ->and(fn() => $response->assertContent('{"a":2}'))->toThrow(AssertionFailedError::class);
});

test('数组访问与 __get 读取响应 JSON', function () {
    $response = testing_response(200, [], '{"code":0,"data":{"name":"x"}}');

    expect($response['code'])->toBe(0)
        ->and($response['data'])->toBe(['name' => 'x'])
        ->and(isset($response['data']))->toBeTrue()
        ->and(isset($response['missing']))->toBeFalse()
        ->and($response->code)->toBe(0)
        ->and($response->data)->toBe(['name' => 'x'])
        ->and(fn() => $response['not-exist'])->toThrow(InvalidArgumentException::class);
});
