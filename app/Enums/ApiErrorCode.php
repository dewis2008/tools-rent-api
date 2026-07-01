<?php

namespace App\Enums;

enum ApiErrorCode: string
{
    case BadRequest = 'bad_request';
    case Unauthenticated = 'unauthenticated';
    case Forbidden = 'forbidden';
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case Conflict = 'conflict';
    case PayloadTooLarge = 'payload_too_large';
    case UnsupportedMediaType = 'unsupported_media_type';
    case ValidationFailed = 'validation_failed';
    case SessionExpired = 'session_expired';
    case RateLimited = 'rate_limited';
    case HttpError = 'http_error';
    case ServerError = 'server_error';
    case ServiceUnavailable = 'service_unavailable';
    case AccountInactive = 'account_inactive';
    case InvalidWebhookSignature = 'invalid_webhook_signature';

    public static function forStatus(int $status): self
    {
        return match ($status) {
            400 => self::BadRequest,
            401 => self::Unauthenticated,
            403 => self::Forbidden,
            404 => self::NotFound,
            405 => self::MethodNotAllowed,
            409 => self::Conflict,
            413 => self::PayloadTooLarge,
            415 => self::UnsupportedMediaType,
            419 => self::SessionExpired,
            422 => self::ValidationFailed,
            429 => self::RateLimited,
            503 => self::ServiceUnavailable,
            default => $status >= 500 ? self::ServerError : self::HttpError,
        };
    }
}
