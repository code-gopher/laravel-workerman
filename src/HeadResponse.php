<?php

declare(strict_types=1);

namespace CodeGopher\LaravelWorkerman;

use Workerman\Protocols\Http\Response;
use function explode;
use function implode;
use function stripos;
use function strpos;
use function substr;

/**
 * 用于正确序列化 HEAD 响应的 Workerman 响应。
 *
 * Workerman 默认序列化器会在已有 Content-Length 后再追加空正文长度，
 * 因此这里仅移除其自动追加的 Content-Length: 0，保留表示 GET 实体的长度。
 */
final class HeadResponse extends Response
{
    /**
     * 序列化 HEAD 响应头，不发送正文或重复的内容长度。
     *
     * @return string
     */
    public function __toString(): string
    {
        $serialized = parent::__toString();
        $headerEnd = strpos($serialized, "\r\n\r\n");
        if ($headerEnd === false) {
            return $serialized;
        }

        $headerLines = explode("\r\n", substr($serialized, 0, $headerEnd));
        $hasExplicitLength = false;
        foreach ($this->getHeaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Content-Length') === 0) {
                $hasExplicitLength = true;
                break;
            }
        }

        $filteredLines = [];
        $keptLength = false;
        foreach ($headerLines as $line) {
            if (stripos($line, 'Content-Length:') === 0) {
                if (!$hasExplicitLength || $keptLength) {
                    continue;
                }
                $keptLength = true;
            }
            $filteredLines[] = $line;
        }

        return implode("\r\n", $filteredLines) . "\r\n\r\n";
    }
}
