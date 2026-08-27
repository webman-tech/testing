<?php

declare(strict_types=1);

namespace WebmanTech\Testing\Http;

use ArrayAccess;
use InvalidArgumentException;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * laravel 风格的响应断言包装（包装 PSR-7 Response，与具体 HTTP client 解耦）
 *
 * 断言用 PHPUnit\Framework\Assert 静态方法实现，pest / phpunit 双兼容。
 * 方法集对齐 laravel Illuminate\Testing\TestResponse；webman 无 view/session
 * 概念的断言（assertViewHas/assertSessionHas 等）不提供。
 */
final class TestResponse implements ArrayAccess
{
    private mixed $decodedJson = null;

    private bool $jsonDecoded = false;

    public function __construct(
        private readonly ResponseInterface $response,
    ) {
    }

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function content(): string
    {
        return (string)$this->response->getBody();
    }

    public function headers(): array
    {
        return $this->response->getHeaders();
    }

    public function header(string $name): string
    {
        return $this->response->getHeaderLine($name);
    }

    /**
     * 解码后的 JSON；$key 支持 dot 路径（如 data.0.id）
     */
    public function json(?string $key = null): mixed
    {
        if (!$this->jsonDecoded) {
            try {
                $this->decodedJson = json_decode($this->content(), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new InvalidArgumentException(
                    '响应不是合法 JSON: ' . mb_substr($this->content(), 0, 200),
                    0,
                    $e,
                );
            }
            $this->jsonDecoded = true;
        }

        if ($key === null) {
            return $this->decodedJson;
        }

        $data = $this->decodedJson;
        foreach (explode('.', $key) as $segment) {
            if (is_array($data) && array_key_exists($segment, $data)) {
                $data = $data[$segment];
            } else {
                throw new InvalidArgumentException("JSON 路径 [{$key}] 不存在");
            }
        }

        return $data;
    }

    // ---- 状态码断言 ----

    public function assertStatus(int $status): self
    {
        $actual = $this->status();
        Assert::assertSame($status, $actual, "期望状态码 {$status}，实际 {$actual}。响应内容:\n" . mb_substr($this->content(), 0, 500));

        return $this;
    }

    public function assertOk(): self
    {
        return $this->assertStatus(200);
    }

    public function assertCreated(): self
    {
        return $this->assertStatus(201);
    }

    public function assertAccepted(): self
    {
        return $this->assertStatus(202);
    }

    public function assertNoContent(): self
    {
        return $this->assertStatus(204);
    }

    public function assertMovedPermanently(): self
    {
        return $this->assertStatus(301);
    }

    public function assertFound(): self
    {
        return $this->assertStatus(302);
    }

    public function assertConflict(): self
    {
        return $this->assertStatus(409);
    }

    public function assertUnprocessable(): self
    {
        return $this->assertStatus(422);
    }

    public function assertTooManyRequests(): self
    {
        return $this->assertStatus(429);
    }

    public function assertSuccessful(): self
    {
        return $this->assertStatusRange(200, 299, '2xx');
    }

    public function assertClientError(): self
    {
        return $this->assertStatusRange(400, 499, '4xx');
    }

    public function assertServerError(): self
    {
        return $this->assertStatusRange(500, 599, '5xx');
    }

    public function assertNotFound(): self
    {
        return $this->assertStatus(404);
    }

    public function assertUnauthorized(): self
    {
        return $this->assertStatus(401);
    }

    public function assertForbidden(): self
    {
        return $this->assertStatus(403);
    }

    // ---- header / redirect / cookie 断言 ----

    public function assertHeader(string $headerName, ?string $value = null): self
    {
        Assert::assertTrue(
            $this->response->hasHeader($headerName),
            "响应缺少 header [{$headerName}]",
        );
        if ($value !== null) {
            Assert::assertSame($value, $this->response->getHeaderLine($headerName));
        }

        return $this;
    }

    public function assertHeaderContains(string $headerName, string $value): self
    {
        Assert::assertStringContainsString($value, $this->response->getHeaderLine($headerName));

        return $this;
    }

    public function assertHeaderMissing(string $headerName): self
    {
        Assert::assertFalse(
            $this->response->hasHeader($headerName),
            "响应不应包含 header [{$headerName}]",
        );

        return $this;
    }

    public function assertLocation(string $uri): self
    {
        return $this->assertHeader('Location', $uri);
    }

    public function assertRedirect(?string $uri = null): self
    {
        Assert::assertContains(
            $this->status(),
            [201, 301, 302, 303, 307, 308],
            '期望重定向状态码，实际 ' . $this->status(),
        );
        if ($uri !== null) {
            Assert::assertSame($uri, $this->response->getHeaderLine('Location'));
        }

        return $this;
    }

    public function assertRedirectContains(string $uri): self
    {
        Assert::assertStringContainsString($uri, $this->response->getHeaderLine('Location'));

        return $this;
    }

    /**
     * 断言响应设置了指定 cookie；$path=true 时返回完整 cookie 属性（value/expires/max-age/path/...）
     *
     * @return string|array<string, mixed>|null
     */
    public function getCookie(string $name, bool $path = false): string|array|null
    {
        $cookie = $this->getCookieAttributes($name);
        if ($cookie === null) {
            return null;
        }

        return $path ? $cookie : $cookie['value'];
    }

    public function assertCookie(string $cookieName, ?string $value = null): self
    {
        $actual = $this->getCookieValue($cookieName);
        Assert::assertNotNull($actual, "响应缺少 cookie [{$cookieName}]");
        if ($value !== null) {
            Assert::assertSame($value, $actual);
        }

        return $this;
    }

    /**
     * laravel 兼容别名（webman 无 cookie 加密概念，等价 assertCookie）
     */
    public function assertPlainCookie(string $cookieName, ?string $value = null): self
    {
        return $this->assertCookie($cookieName, $value);
    }

    public function assertCookieMissing(string $cookieName): self
    {
        Assert::assertNull($this->getCookieValue($cookieName), "响应不应包含 cookie [{$cookieName}]");

        return $this;
    }

    public function assertCookieExpired(string $cookieName): self
    {
        Assert::assertTrue(
            $this->isCookieExpired($cookieName),
            "cookie [{$cookieName}] 未过期",
        );

        return $this;
    }

    public function assertCookieNotExpired(string $cookieName): self
    {
        Assert::assertFalse(
            $this->isCookieExpired($cookieName),
            "cookie [{$cookieName}] 已过期",
        );

        return $this;
    }

    // ---- 内容断言 ----

    public function assertContent(string $content): self
    {
        Assert::assertSame($content, $this->content());

        return $this;
    }

    public function assertContentType(string $type): self
    {
        return $this->assertHeader('Content-Type', $type);
    }

    public function assertSee(string $value): self
    {
        Assert::assertStringContainsString($value, $this->content());

        return $this;
    }

    public function assertSeeText(string $value, bool $escape = true): self
    {
        $value = $escape ? htmlspecialchars($value, ENT_QUOTES) : $value;
        Assert::assertStringContainsString($value, $this->withoutHtml($this->content()));

        return $this;
    }

    public function assertDontSeeText(string $value, bool $escape = true): self
    {
        $value = $escape ? htmlspecialchars($value, ENT_QUOTES) : $value;
        Assert::assertStringNotContainsString($value, $this->withoutHtml($this->content()));

        return $this;
    }

    public function assertSeeInOrder(array $values): self
    {
        $this->assertInOrder($values, $this->content());

        return $this;
    }

    public function assertSeeTextInOrder(array $values): self
    {
        $this->assertInOrder($values, $this->withoutHtml($this->content()));

        return $this;
    }

    // ---- JSON 断言 ----

    /**
     * 断言响应 JSON 包含给定子集（顶层递归子集匹配，同 laravel assertJson 语义）
     */
    public function assertJson(array $data): self
    {
        $actual = $this->json();
        Assert::assertIsArray($actual, '期望响应 JSON 顶层为数组');
        Assert::assertTrue(
            $this->arraySubset($data, $actual),
            "给定的 JSON 子集未匹配响应:\n" . mb_substr($this->content(), 0, 500),
        );

        return $this;
    }

    /**
     * 断言响应 JSON 与给定数据完全一致（递归严格比较，key 顺序无关）
     */
    public function assertExactJson(array $data): self
    {
        Assert::assertTrue(
            $this->exactArrayEquals($data, $this->json()),
            "响应 JSON 与期望不一致:\n" . mb_substr($this->content(), 0, 500),
        );

        return $this;
    }

    /**
     * 断言响应 JSON 与给定数据相似（递归宽松比较，忽略类型差异）
     */
    public function assertSimilarJson(array $data): self
    {
        Assert::assertEquals(
            $data,
            $this->json(),
            "响应 JSON 与期望不一致:\n" . mb_substr($this->content(), 0, 500),
        );

        return $this;
    }

    /**
     * 断言响应 JSON 任意深度包含给定 fragment
     */
    public function assertJsonFragment(array $data): self
    {
        Assert::assertTrue(
            $this->jsonContainsFragment($data, $this->json()),
            "响应 JSON 中未找到 fragment:\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );

        return $this;
    }

    public function assertJsonMissing(array $data): self
    {
        Assert::assertFalse(
            $this->jsonContainsFragment($data, $this->json()),
            "响应 JSON 中不应包含 fragment:\n" . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );

        return $this;
    }

    public function assertJsonMissingExact(array $data): self
    {
        Assert::assertFalse(
            $this->exactArrayEquals($data, $this->json()),
            '响应 JSON 不应与给定数据完全一致',
        );

        return $this;
    }

    /**
     * 断言指定 dot 路径的值；传 Closure 时以实际值为参数执行，返回真值即通过（同 laravel）
     */
    public function assertJsonPath(string $path, mixed $expected): self
    {
        if ($expected instanceof \Closure) {
            Assert::assertTrue(
                (bool)$expected($this->json($path)),
                "JSON 路径 [{$path}] 未满足回调断言",
            );
        } else {
            Assert::assertEquals($expected, $this->json($path));
        }

        return $this;
    }

    public function assertJsonMissingPath(string $path): self
    {
        try {
            $this->json($path);
            Assert::fail("JSON 路径 [{$path}] 不应存在");
        } catch (InvalidArgumentException) {
            // 路径不存在即通过
        }

        return $this;
    }

    /**
     * 断言响应 JSON 的结构（支持嵌套与 '*' 通配所有列表元素）
     *
     * 如：['data' => ['*' => ['id', 'username']]]、['msg', 'data' => ['total']]
     */
    public function assertJsonStructure(?array $structure = null): self
    {
        if ($structure === null) {
            // 仅断言响应是合法 JSON
            $this->json();

            return $this;
        }

        $actual = $this->json();
        Assert::assertIsArray($actual, '期望响应 JSON 顶层为数组');
        $this->assertStructure($structure, $actual);

        return $this;
    }

    /**
     * 断言响应 JSON 结构与给定结构完全一致（无额外字段）
     */
    public function assertExactJsonStructure(?array $structure = null): self
    {
        if ($structure === null) {
            return $this->assertJsonStructure();
        }

        $actual = $this->json();
        Assert::assertIsArray($actual, '期望响应 JSON 顶层为数组');
        $this->assertExactStructure($structure, $actual);

        return $this;
    }

    public function assertJsonCount(int $count, ?string $key = null): self
    {
        $actual = $this->json($key);
        Assert::assertIsArray($actual);
        Assert::assertCount($count, $actual);

        return $this;
    }

    public function assertJsonIsArray(): self
    {
        Assert::assertIsArray($this->json(), '期望响应 JSON 为数组');

        return $this;
    }

    public function assertJsonIsObject(): self
    {
        try {
            $decoded = json_decode($this->content(), false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Assert::fail('响应不是合法 JSON: ' . $e->getMessage());
        }
        Assert::assertIsObject($decoded, '期望响应 JSON 为对象');

        return $this;
    }

    // ---- 验证错误断言（422 场景） ----

    /**
     * 断言响应包含指定字段的验证错误；$errors 为字段名或 [字段 => 期望错误消息/消息数组]
     */
    public function assertJsonValidationErrors(array|string $errors, string $responseKey = 'errors'): self
    {
        $errorsData = $this->jsonValidationErrors($responseKey);

        if (is_string($errors)) {
            $errors = [$errors => null];
        }
        foreach ($errors as $field => $messages) {
            // 列表形态（['name', 'age']）：value 为字段名，无消息断言
            if (is_int($field)) {
                $field = (string)$messages;
                $messages = null;
            }
            Assert::assertArrayHasKey($field, $errorsData, "验证错误中缺少字段 [{$field}]");
            if ($messages === null) {
                continue;
            }
            $fieldErrors = $errorsData[$field];
            Assert::assertIsArray($fieldErrors, "字段 [{$field}] 的错误应为数组");
            foreach ((array)$messages as $message) {
                Assert::assertContains(
                    $message,
                    $fieldErrors,
                    "字段 [{$field}] 的错误消息中缺少 [{$message}]",
                );
            }
        }

        return $this;
    }

    public function assertJsonValidationErrorFor(string $key, string $responseKey = 'errors'): self
    {
        return $this->assertJsonValidationErrors([$key => null], $responseKey);
    }

    public function assertJsonMissingValidationErrors(string $responseKey = 'errors'): self
    {
        try {
            $errorsData = $this->json($responseKey);
        } catch (InvalidArgumentException) {
            // 响应无该字段即无验证错误
            return $this;
        }
        Assert::assertTrue(
            $errorsData === null || $errorsData === [],
            "响应存在非空验证错误:\n" . mb_substr($this->content(), 0, 500),
        );

        return $this;
    }

    /**
     * 断言响应无验证错误（laravel assertValid 语义）
     */
    public function assertValid(string $responseKey = 'errors'): self
    {
        return $this->assertJsonMissingValidationErrors($responseKey);
    }

    /**
     * 断言响应存在验证错误（laravel assertInvalid 语义）
     */
    public function assertInvalid(array|string $errors, string $responseKey = 'errors'): self
    {
        return $this->assertJsonValidationErrors($errors, $responseKey);
    }

    // ---- 调试 ----

    public function dump(): self
    {
        $content = $this->content();
        fwrite(STDERR, "响应状态: {$this->status()}\n响应头:\n" . $this->dumpHeadersText() . "\n响应内容:\n{$content}\n");

        return $this;
    }

    public function dumpHeaders(): self
    {
        fwrite(STDERR, "响应状态: {$this->status()}\n响应头:\n" . $this->dumpHeadersText() . "\n");

        return $this;
    }

    public function dumpJson(): self
    {
        fwrite(STDERR, "响应状态: {$this->status()}\n响应 JSON:\n" . $this->content() . "\n");

        return $this;
    }

    public function dd(): never
    {
        $this->dump();

        throw new RuntimeException('终止测试（dd）');
    }

    public function ddJson(): never
    {
        $this->dumpJson();

        throw new RuntimeException('终止测试（ddJson）');
    }

    // ---- 数组访问（响应 JSON） ----

    public function offsetExists(mixed $offset): bool
    {
        $data = $this->json();

        return (is_int($offset) || is_string($offset)) && is_array($data) && array_key_exists($offset, $data);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->json((string)$offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new RuntimeException('TestResponse 只读，不可写入');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new RuntimeException('TestResponse 只读，不可删除');
    }

    public function __get(string $key): mixed
    {
        return $this->json($key);
    }

    public function __isset(string $key): bool
    {
        return $this->offsetExists($key);
    }

    // ---- 内部实现 ----

    private function assertStatusRange(int $min, int $max, string $label): self
    {
        $actual = $this->status();
        Assert::assertTrue(
            $actual >= $min && $actual <= $max,
            "期望 {$label} 状态码，实际 {$actual}",
        );

        return $this;
    }

    private function withoutHtml(string $content): string
    {
        return trim((string)preg_replace('/<script[^>]*>[\s\S]*?<\/script>|<style[^>]*>[\s\S]*?<\/style>|<[^>]+>/', '', $content));
    }

    private function assertInOrder(array $values, string $haystack): void
    {
        $position = 0;
        foreach ($values as $value) {
            $index = strpos($haystack, (string)$value);
            Assert::assertNotFalse($index, "内容中未找到 [{$value}]");
            Assert::assertGreaterThanOrEqual(
                $position,
                $index,
                "内容中 [{$value}] 的出现顺序不符合期望",
            );
            $position = $index;
        }
    }

    private function arraySubset(array $expected, array $actual): bool
    {
        foreach ($expected as $key => $value) {
            if (!array_key_exists($key, $actual)) {
                return false;
            }
            if (is_array($value)) {
                if (!is_array($actual[$key]) || !$this->arraySubset($value, $actual[$key])) {
                    return false;
                }
            } elseif ($actual[$key] !== $value) {
                return false;
            }
        }

        return true;
    }

    /**
     * 递归严格相等（key 顺序无关 + 类型严格；PHPUnit assertSame 对数组是 key 顺序敏感的）
     */
    private function exactArrayEquals(mixed $expected, mixed $actual): bool
    {
        if (is_array($expected) && is_array($actual)) {
            if (count($expected) !== count($actual)) {
                return false;
            }
            foreach ($expected as $key => $value) {
                if (!array_key_exists($key, $actual) || !$this->exactArrayEquals($value, $actual[$key])) {
                    return false;
                }
            }

            return true;
        }

        return $expected === $actual;
    }

    private function jsonContainsFragment(array $fragment, mixed $haystack): bool
    {
        if (is_array($haystack) && $this->arraySubset($fragment, $haystack)) {
            return true;
        }
        if (!is_array($haystack)) {
            return false;
        }
        foreach ($haystack as $value) {
            if (is_array($value) && $this->jsonContainsFragment($fragment, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 期望结构的元素：int key + string（叶子字段名）/ string key + array（嵌套）/ '*'（通配列表）
     *
     * @param array<int|string, mixed> $expected
     */
    private function assertStructure(array $expected, array $actual): void
    {
        foreach ($expected as $key => $value) {
            if (is_int($key)) {
                // 列表形态：['id', 'name']，value 为 key 名
                Assert::assertIsString($value);
                Assert::assertArrayHasKey($value, $actual);
            } elseif ($key === '*') {
                // 通配：实际数组的每个元素都需匹配给定结构
                Assert::assertIsArray($value);
                foreach ($actual as $item) {
                    Assert::assertIsArray($item);
                    $this->assertStructure($value, $item);
                }
            } elseif (is_array($value)) {
                Assert::assertArrayHasKey($key, $actual);
                $nested = $actual[$key];
                Assert::assertIsArray($nested);
                $this->assertStructure($value, $nested);
            } else {
                Assert::assertArrayHasKey($key, $actual);
            }
        }
    }

    /**
     * 完全一致的结构校验（无额外字段）
     *
     * @param array<int|string, mixed> $expected
     */
    private function assertExactStructure(array $expected, array $actual): void
    {
        foreach ($expected as $key => $value) {
            if (is_int($key)) {
                Assert::assertIsString($value);
                Assert::assertArrayHasKey($value, $actual);
            } elseif ($key === '*') {
                Assert::assertIsArray($value);
                foreach ($actual as $item) {
                    Assert::assertIsArray($item);
                    $this->assertExactStructure($value, $item);
                }
            } elseif (is_array($value)) {
                Assert::assertArrayHasKey($key, $actual);
                $nested = $actual[$key];
                Assert::assertIsArray($nested);
                $this->assertExactStructure($value, $nested);
            } else {
                Assert::assertArrayHasKey($key, $actual);
            }
        }
        // 期望之外的顶层字段一律不允许
        $expectedKeys = [];
        foreach ($expected as $key => $value) {
            if (is_int($key)) {
                $expectedKeys[] = $value;
            } elseif ($key !== '*') {
                $expectedKeys[] = $key;
            }
        }
        if ($expectedKeys !== []) {
            Assert::assertSame(
                $expectedKeys,
                array_keys($actual),
                '响应 JSON 结构存在额外字段',
            );
        }
    }

    /**
     * 验证错误数据（responseKey 路径）；响应无该字段时断言失败
     */
    private function jsonValidationErrors(string $responseKey): array
    {
        try {
            $errorsData = $this->json($responseKey);
        } catch (InvalidArgumentException $e) {
            Assert::fail("响应缺少验证错误字段 [{$responseKey}]: " . $e->getMessage());
        }
        Assert::assertIsArray($errorsData, "验证错误字段 [{$responseKey}] 应为数组");

        return $errorsData;
    }

    private function getCookieValue(string $name): ?string
    {
        $cookie = $this->getCookieAttributes($name);

        return $cookie['value'] ?? null;
    }

    /**
     * 解析 Set-Cookie 属性（value/expires/max-age/path/domain/secure/httponly）
     *
     * @return array<string, mixed>|null
     */
    private function getCookieAttributes(string $name): ?array
    {
        foreach ($this->response->getHeader('Set-Cookie') as $cookie) {
            $parts = array_map('trim', explode(';', $cookie));
            $first = explode('=', array_shift($parts), 2);
            if ($first[0] !== $name) {
                continue;
            }
            $attributes = [
                'value' => $first[1] ?? '',
                'expires' => null,
                'max-age' => null,
                'path' => null,
                'domain' => null,
                'secure' => false,
                'httponly' => false,
            ];
            foreach ($parts as $part) {
                if ($part === '') {
                    continue;
                }
                [$attrKey, $attrValue] = array_pad(explode('=', $part, 2), 2, null);
                $lowerKey = strtolower((string)$attrKey);
                if ($lowerKey === 'secure' || $lowerKey === 'httponly') {
                    $attributes[$lowerKey] = true;
                } elseif (array_key_exists($lowerKey, $attributes)) {
                    $attributes[$lowerKey] = $attrValue;
                }
            }

            return $attributes;
        }

        return null;
    }

    private function isCookieExpired(string $name): bool
    {
        $cookie = $this->getCookieAttributes($name);
        Assert::assertNotNull($cookie, "响应缺少 cookie [{$name}]");

        $maxAge = $cookie['max-age'];
        if ($maxAge !== null) {
            return (int)$maxAge <= 0;
        }
        $expires = $cookie['expires'];
        if ($expires !== null) {
            return strtotime((string)$expires) < time();
        }

        return false;
    }

    private function dumpHeadersText(): string
    {
        $lines = [];
        foreach ($this->response->getHeaders() as $name => $values) {
            $lines[] = "  {$name}: " . implode(', ', $values);
        }

        return implode("\n", $lines);
    }
}
