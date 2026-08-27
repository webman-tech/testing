<?php

declare(strict_types=1);

namespace WebmanTech\Testing\Http;

use Psr\Http\Client\ClientInterface;
use RuntimeException;

/**
 * PSR-18 HTTP 客户端自动发现
 *
 * 组件只依赖 PSR 标准接口（psr/http-client、psr/http-message），不绑定任何实现：
 * - 优先使用 Server::setHttpClient() 注入的自定义客户端
 * - 否则检测 guzzle（最常用的 PSR-18 实现，安装后自动使用，构造参数经 TestingConfig::httpClient 配置化）
 * - 都没有时抛可读异常提示
 *
 * 注意：客户端需保证 4xx/5xx 响应不抛异常（对应 guzzle 的 http_errors=false），
 * 否则 TestResponse 无法断言错误响应、探活会把就绪误判为失败。
 */
final class HttpClientFactory
{
    /**
     * 创建 PSR-18 客户端：$custom 优先，其次自动发现 guzzle（$options 为其构造参数，
     * http_errors 恒为 false 不可覆盖，保证 4xx/5xx 交由断言层处理）
     */
    public static function create(?ClientInterface $custom = null, array $options = []): ClientInterface
    {
        if ($custom !== null) {
            return $custom;
        }

        if (class_exists(\GuzzleHttp\Client::class)) {
            return new \GuzzleHttp\Client(array_merge($options, [
                // 4xx/5xx 不抛异常，交由 TestResponse 断言
                'http_errors' => false,
            ]));
        }

        throw new RuntimeException(
            '未检测到 PSR-18 HTTP 客户端实现，请安装 guzzlehttp/guzzle'
            . '（composer require --dev guzzlehttp/guzzle），'
            . '或调用 Server::setHttpClient() 注入自定义 PSR-18 客户端'
            . '（自定义客户端需保证 4xx/5xx 响应不抛异常，对应 guzzle 的 http_errors=false 行为）'
        );
    }
}
