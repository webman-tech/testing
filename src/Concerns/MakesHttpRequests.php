<?php

declare(strict_types=1);

namespace WebmanTech\Testing\Concerns;

use WebmanTech\Testing\Http\RequestFactory;
use WebmanTech\Testing\Http\TestResponse;

/**
 * laravel MakesHttpRequests 风格的便捷请求方法（真实进程 HTTP 请求）
 *
 * pest 用户经 `pest()->extend(TestCase::class)->in(...)` 绑定后可直接
 * `$this->postJson(...)`（laravel 同款语法）；宿主类需提供 webmanServer() 方法。
 *
 * 与 laravel 的差异：
 * - withCookie(s) 均为跨请求默认 cookie（laravel withCookie 为单次请求）
 * - withServerVariables 不支持（HTTP 跨进程无法注入 SERVER 变量）
 */
trait MakesHttpRequests
{
    /**
     * 跨请求复用的默认 headers（withToken 等写入；每个测试实例独立）
     */
    protected array $webmanDefaultHeaders = [];

    /**
     * 跨请求复用的默认 cookies（withCookie 等写入）
     */
    protected array $webmanDefaultCookies = [];

    /**
     * 是否跟随重定向（默认不跟随，便于断言 Location；laravel 同款语义）
     */
    protected bool $webmanFollowRedirects = false;

    protected int $webmanRedirectMax = 5;

    // ---- headers ----

    public function withHeaders(array $headers): static
    {
        $this->webmanDefaultHeaders = array_merge($this->webmanDefaultHeaders, $headers);

        return $this;
    }

    public function withHeader(string $name, string $value): static
    {
        return $this->withHeaders([$name => $value]);
    }

    public function withoutHeader(string $name): static
    {
        unset($this->webmanDefaultHeaders[$name]);

        return $this;
    }

    public function withoutHeaders(array $names): static
    {
        foreach ($names as $name) {
            $this->withoutHeader($name);
        }

        return $this;
    }

    public function flushHeaders(): static
    {
        $this->webmanDefaultHeaders = [];

        return $this;
    }

    // ---- 认证 ----

    public function withToken(string $token, string $type = 'Bearer'): static
    {
        return $this->withHeaders(['Authorization' => "{$type} {$token}"]);
    }

    public function withBasicAuth(string $user, string $password = ''): static
    {
        return $this->withHeaders(['Authorization' => 'Basic ' . base64_encode("{$user}:{$password}")]);
    }

    public function withoutToken(): static
    {
        return $this->withoutHeader('Authorization');
    }

    // ---- cookies ----

    public function withCookies(array $cookies): static
    {
        $this->webmanDefaultCookies = array_merge($this->webmanDefaultCookies, $cookies);

        return $this;
    }

    public function withCookie(string $name, string $value): static
    {
        return $this->withCookies([$name => $value]);
    }

    /**
     * laravel 兼容别名（webman 无 cookie 加密概念，等价 withCookies）
     */
    public function withUnencryptedCookies(array $cookies): static
    {
        return $this->withCookies($cookies);
    }

    /**
     * laravel 兼容别名（webman 无 cookie 加密概念，等价 withCookie）
     */
    public function withUnencryptedCookie(string $name, string $value): static
    {
        return $this->withCookie($name, $value);
    }

    public function flushCookies(): static
    {
        $this->webmanDefaultCookies = [];

        return $this;
    }

    // ---- 其他请求配置 ----

    /**
     * 后续请求跟随重定向（默认不跟随，便于断言 Location header）
     */
    public function followingRedirects(int $max = 5): static
    {
        $this->webmanFollowRedirects = true;
        $this->webmanRedirectMax = $max;

        return $this;
    }

    /**
     * 设置来源 URL（写入 Referer header；laravel 还用于相对 URL 基准，HTTP 模式下无此概念）
     */
    public function from(string $url): static
    {
        return $this->withHeader('Referer', $url);
    }

    // ---- HTTP 方法 ----

    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->webmanRequest('GET', $uri, [RequestFactory::OPT_HEADERS => $headers]);
    }

    public function getJson(string $uri, array $headers = []): TestResponse
    {
        return $this->get($uri, $headers + ['Accept' => 'application/json']);
    }

    public function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->webmanRequest('POST', $uri, [RequestFactory::OPT_FORM_PARAMS => $data, RequestFactory::OPT_HEADERS => $headers]);
    }

    public function postJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->webmanRequest('POST', $uri, [RequestFactory::OPT_JSON => $data, RequestFactory::OPT_HEADERS => $headers]);
    }

    public function put(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->webmanRequest('PUT', $uri, [RequestFactory::OPT_FORM_PARAMS => $data, RequestFactory::OPT_HEADERS => $headers]);
    }

    public function putJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->webmanRequest('PUT', $uri, [RequestFactory::OPT_JSON => $data, RequestFactory::OPT_HEADERS => $headers]);
    }

    public function patch(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->webmanRequest('PATCH', $uri, [RequestFactory::OPT_FORM_PARAMS => $data, RequestFactory::OPT_HEADERS => $headers]);
    }

    public function patchJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->webmanRequest('PATCH', $uri, [RequestFactory::OPT_JSON => $data, RequestFactory::OPT_HEADERS => $headers]);
    }

    public function delete(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->webmanRequest('DELETE', $uri, [RequestFactory::OPT_FORM_PARAMS => $data, RequestFactory::OPT_HEADERS => $headers]);
    }

    public function deleteJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->delete($uri, $data, $headers + ['Accept' => 'application/json']);
    }

    public function head(string $uri, array $headers = []): TestResponse
    {
        return $this->webmanRequest('HEAD', $uri, [RequestFactory::OPT_HEADERS => $headers]);
    }

    public function options(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->webmanRequest('OPTIONS', $uri, [RequestFactory::OPT_FORM_PARAMS => $data, RequestFactory::OPT_HEADERS => $headers]);
    }

    public function optionsJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->webmanRequest('OPTIONS', $uri, [RequestFactory::OPT_JSON => $data, RequestFactory::OPT_HEADERS => $headers]);
    }

    /**
     * 以任意方法发送 JSON 请求
     */
    public function json(string $method, string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->webmanRequest($method, $uri, [RequestFactory::OPT_JSON => $data, RequestFactory::OPT_HEADERS => $headers]);
    }

    /**
     * 发送原始请求（guzzle options 形态，trait 方法的逃生口；合并默认 headers/cookies/重定向配置）
     */
    protected function webmanRequest(string $method, string $uri, array $options): TestResponse
    {
        $options[RequestFactory::OPT_HEADERS] = array_merge($this->webmanDefaultHeaders, $options[RequestFactory::OPT_HEADERS] ?? []);
        if ($this->webmanDefaultCookies !== []) {
            $cookieHeader = $this->buildCookieHeader();
            $options[RequestFactory::OPT_HEADERS]['Cookie'] = isset($options[RequestFactory::OPT_HEADERS]['Cookie'])
                ? $options[RequestFactory::OPT_HEADERS]['Cookie'] . '; ' . $cookieHeader
                : $cookieHeader;
        }
        $options[RequestFactory::OPT_ALLOW_REDIRECTS] ??= $this->webmanFollowRedirects
            ? ['max' => $this->webmanRedirectMax]
            : false;

        return $this->webmanSend($method, $uri, $options);
    }

    /**
     * 发送层钩子（测试可 stub 以捕获最终请求参数）
     */
    protected function webmanSend(string $method, string $uri, array $options): TestResponse
    {
        return $this->webmanServer()->request($method, $uri, $options);
    }

    private function buildCookieHeader(): string
    {
        return implode('; ', array_map(
            fn(string $name, string $value): string => rawurlencode($name) . '=' . rawurlencode($value),
            array_keys($this->webmanDefaultCookies),
            array_values($this->webmanDefaultCookies),
        ));
    }
}
