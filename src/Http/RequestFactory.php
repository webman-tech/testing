<?php

declare(strict_types=1);

namespace WebmanTech\Testing\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;

/**
 * 请求选项 → PSR-7 Request（guzzle options 形态的轻量解析器）
 *
 * 支持的选项键收口为 OPT_* 常量（避免字面量散落；新增选项先在这里登记）：
 * - OPT_HEADERS / OPT_JSON / OPT_FORM_PARAMS / OPT_QUERY / OPT_RAW_BODY：本类解析（与 guzzle 语义对齐）
 * - OPT_ALLOW_REDIRECTS：由 Server::request 消费（重定向跟随语义）
 *
 * 语义：
 * - json：数组编码为 JSON body，自动补 Content-Type: application/json
 * - form_params：数组编码为表单 body，自动补 Content-Type: application/x-www-form-urlencoded
 * - query：数组拼接到 uri（替换 uri 中已有的 query，guzzle 语义）
 * - raw_body：直接作为 body（重定向跟随等内部场景复用流）
 *
 * PSR-7 实现不做强依赖：优先使用 guzzle 自带的 guzzlehttp/psr7，
 * 不可用时抛可读异常提示（安装 guzzlehttp/guzzle 或 guzzlehttp/psr7）。
 */
final class RequestFactory
{
    /**
     * 请求选项键（收口：新增选项先在此登记，再在 create() 中实现解析；allow_redirects 由 Server::request 消费）
     */
    public const OPT_HEADERS = 'headers';
    public const OPT_JSON = 'json';
    public const OPT_FORM_PARAMS = 'form_params';
    public const OPT_QUERY = 'query';
    public const OPT_RAW_BODY = 'raw_body';
    public const OPT_ALLOW_REDIRECTS = 'allow_redirects';

    /**
     * 构造 PSR-7 请求
     *
     * @param array $options 请求选项（键名见 OPT_* 常量）
     */
    public static function create(string $method, string $uri, array $options): RequestInterface
    {
        $headers = $options[self::OPT_HEADERS] ?? [];
        $body = null;

        if (array_key_exists(self::OPT_RAW_BODY, $options)) {
            $rawBody = $options[self::OPT_RAW_BODY];
            // null 表示无 body（重定向转 GET 场景）；否则必须是 string / resource / StreamInterface
            if ($rawBody !== null && !is_string($rawBody) && !is_resource($rawBody) && !$rawBody instanceof StreamInterface) {
                throw new RuntimeException('raw_body 必须是 string / resource / StreamInterface / null');
            }
            $body = $rawBody;
        } elseif (array_key_exists(self::OPT_JSON, $options)) {
            $body = json_encode($options[self::OPT_JSON]);
            if ($body === false) {
                throw new RuntimeException('JSON 编码失败: ' . json_last_error_msg());
            }
            $headers['Content-Type'] ??= 'application/json';
        } elseif (array_key_exists(self::OPT_FORM_PARAMS, $options)) {
            $body = http_build_query($options[self::OPT_FORM_PARAMS]);
            $headers['Content-Type'] ??= 'application/x-www-form-urlencoded';
        }

        if (isset($options[self::OPT_QUERY])) {
            $query = http_build_query($options[self::OPT_QUERY]);
            // 替换 uri 中已有的 query（guzzle 语义）
            $uri = preg_replace('/\?.*$/', '', $uri) . ($query !== '' ? '?' . $query : '');
        }

        if (!class_exists(\GuzzleHttp\Psr7\Request::class)) {
            throw new RuntimeException(
                '缺少 PSR-7 请求实现（GuzzleHttp\Psr7\Request 不可用），'
                . '请安装 guzzlehttp/guzzle（或 guzzlehttp/psr7）'
            );
        }

        return new \GuzzleHttp\Psr7\Request($method, $uri, $headers, $body);
    }
}
