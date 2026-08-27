<?php

use Psr\Http\Message\RequestInterface;
use WebmanTech\Testing\Http\HttpClientFactory;
use WebmanTech\Testing\Http\RequestFactory;

/*
 * HttpClientFactory 自动发现（guzzle 已安装场景）与 RequestFactory 的
 * options → PSR-7 解析（json/form_params/query/raw_body 语义对齐 guzzle）
 */

test('自动发现：未注入自定义客户端时返回 guzzle 的 PSR-18 客户端', function () {
    $client = HttpClientFactory::create();

    expect($client)->toBeInstanceOf(Psr\Http\Client\ClientInterface::class)
        // vendor 装有 guzzle 时自动使用 guzzle
        ->and($client)->toBeInstanceOf(GuzzleHttp\Client::class);
});

test('自动发现：自定义客户端优先于 guzzle', function () {
    $custom = new class implements Psr\Http\Client\ClientInterface {
        public function sendRequest(Psr\Http\Message\RequestInterface $request): Psr\Http\Message\ResponseInterface
        {
            throw new RuntimeException('not used');
        }
    };

    expect(HttpClientFactory::create($custom))->toBe($custom);
});

test('RequestFactory: json 选项编码为 JSON body 并补 Content-Type', function () {
    /** @var RequestInterface $request */
    $request = RequestFactory::create('POST', 'http://localhost/uri', ['json' => ['a' => 1]]);

    expect($request->getMethod())->toBe('POST')
        ->and((string)$request->getUri())->toBe('http://localhost/uri')
        ->and($request->getBody()->getContents())->toBe('{"a":1}')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/json');
});

test('RequestFactory: form_params 选项编码为表单 body 并补 Content-Type', function () {
    /** @var RequestInterface $request */
    $request = RequestFactory::create('POST', '/uri', ['form_params' => ['a' => 1, 'b' => 'x y']]);

    expect($request->getBody()->getContents())->toBe('a=1&b=x+y')
        ->and($request->getHeaderLine('Content-Type'))->toBe('application/x-www-form-urlencoded');
});

test('RequestFactory: json 与 form_params 的 Content-Type 不覆盖显式 headers', function () {
    /** @var RequestInterface $request */
    $request = RequestFactory::create('POST', '/uri', [
        'json' => ['a' => 1],
        'headers' => ['Content-Type' => 'application/problem+json'],
    ]);

    expect($request->getHeaderLine('Content-Type'))->toBe('application/problem+json');
});

test('RequestFactory: query 选项替换 uri 中已有 query（guzzle 语义）', function () {
    /** @var RequestInterface $request */
    $request = RequestFactory::create('GET', '/uri?old=1', ['query' => ['page' => 2]]);

    expect((string)$request->getUri())->toBe('/uri?page=2');
});

test('RequestFactory: raw_body 直传 body（重定向跟随复用流）', function () {
    $stream = \GuzzleHttp\Psr7\Utils::streamFor('{"keep":true}');

    /** @var RequestInterface $request */
    $request = RequestFactory::create('POST', '/uri', ['raw_body' => $stream]);

    expect($request->getBody())->toBe($stream)
        ->and($request->getBody()->getContents())->toBe('{"keep":true}');
});

test('RequestFactory: raw_body 支持 null（无 body）与非法类型拒绝', function () {
    /** @var RequestInterface $request */
    $request = RequestFactory::create('GET', '/uri', ['raw_body' => null]);

    expect($request->getBody()->getSize())->toBe(0)
        ->and(fn() => RequestFactory::create('POST', '/uri', ['raw_body' => 123]))->toThrow(RuntimeException::class);
});

test('RequestFactory: json 编码失败抛可读异常', function () {
    expect(fn() => RequestFactory::create('POST', '/uri', ['json' => [NAN]]))
        ->toThrow(RuntimeException::class, 'JSON 编码失败');
});
