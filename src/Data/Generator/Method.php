<?php

namespace Crescat\SaloonSdkGenerator\Data\Generator;

use Saloon\Enums\Method as SaloonMethod;

enum Method: string
{
    public static function parse($value, ?self $fallback = self::GET): ?self
    {
        // Should be the same, this enum is a clone of Saloon´s enum.
        if ($value instanceof SaloonMethod) {
            return self::from($value->value);
        }

        return self::tryFrom(strtoupper(trim($value)));
    }

    public function isConnect(): bool
    {
        return $this == self::CONNECT;
    }

    public function isDelete(): bool
    {
        return $this == self::DELETE;
    }

    public function isGet(): bool
    {
        return $this == self::GET;
    }

    public function isHead(): bool
    {
        return $this == self::HEAD;
    }

    public function isOptions(): bool
    {
        return $this == self::OPTIONS;
    }

    public function isPatch(): bool
    {
        return $this == self::PATCH;
    }

    public function isPost(): bool
    {
        return $this == self::POST;
    }

    public function isPut(): bool
    {
        return $this == self::PUT;
    }

    public function isTrace(): bool
    {
        return $this == self::TRACE;
    }
    case CONNECT = 'CONNECT';
    case DELETE = 'DELETE';
    case GET = 'GET';
    case HEAD = 'HEAD';
    case OPTIONS = 'OPTIONS';
    case PATCH = 'PATCH';
    case POST = 'POST';
    case PUT = 'PUT';
    case TRACE = 'TRACE';
}
