<?php

namespace App\Modules\Core\Errors;

/**
 * Stable, locale-independent error codes (ADR-0006). Adding a case is
 * non-breaking; renaming or removing one is a breaking API change.
 */
enum ErrorCode: string
{
    case BadRequest = 'BAD_REQUEST';
    case Unauthenticated = 'UNAUTHENTICATED';
    case InvalidCredentials = 'INVALID_CREDENTIALS';
    case Forbidden = 'FORBIDDEN';
    case AdminAccessRequired = 'ADMIN_ACCESS_REQUIRED';
    case AccountInactive = 'ACCOUNT_INACTIVE';
    case AccountBanned = 'ACCOUNT_BANNED';
    case StatefulRequestRequired = 'STATEFUL_REQUEST_REQUIRED';
    case ResourceNotFound = 'RESOURCE_NOT_FOUND';
    case MethodNotAllowed = 'METHOD_NOT_ALLOWED';
    case NotAcceptable = 'NOT_ACCEPTABLE';
    case CsrfTokenMismatch = 'CSRF_TOKEN_MISMATCH';
    case Conflict = 'CONFLICT';
    case SelfModificationForbidden = 'SELF_MODIFICATION_FORBIDDEN';
    case LastAdminProtected = 'LAST_ADMIN_PROTECTED';
    case OwnerProtected = 'OWNER_PROTECTED';
    case ProtectedRole = 'PROTECTED_ROLE';
    case GrantCeilingExceeded = 'GRANT_CEILING_EXCEEDED';
    case TargetNotManageable = 'TARGET_NOT_MANAGEABLE';
    case RolesRequireAdminAccount = 'ROLES_REQUIRE_ADMIN_ACCOUNT';
    case ValidationError = 'VALIDATION_ERROR';
    case RateLimitExceeded = 'RATE_LIMIT_EXCEEDED';
    case InternalServerError = 'INTERNAL_SERVER_ERROR';
    case ServiceUnavailable = 'SERVICE_UNAVAILABLE';
    case HttpError = 'HTTP_ERROR';

    public function status(): int
    {
        return match ($this) {
            self::BadRequest, self::StatefulRequestRequired => 400,
            self::Unauthenticated, self::InvalidCredentials => 401,
            self::Forbidden, self::AdminAccessRequired, self::AccountInactive, self::AccountBanned,
            self::SelfModificationForbidden, self::OwnerProtected, self::ProtectedRole,
            self::GrantCeilingExceeded, self::TargetNotManageable => 403,
            self::ResourceNotFound => 404,
            self::MethodNotAllowed => 405,
            self::NotAcceptable => 406,
            self::CsrfTokenMismatch => 419,
            self::Conflict, self::LastAdminProtected, self::RolesRequireAdminAccount => 409,
            self::ValidationError => 422,
            self::RateLimitExceeded => 429,
            self::ServiceUnavailable => 503,
            self::InternalServerError, self::HttpError => 500,
        };
    }

    public static function forStatus(int $status): self
    {
        return match ($status) {
            400 => self::BadRequest,
            401 => self::Unauthenticated,
            403 => self::Forbidden,
            404 => self::ResourceNotFound,
            405 => self::MethodNotAllowed,
            406 => self::NotAcceptable,
            409 => self::Conflict,
            419 => self::CsrfTokenMismatch,
            422 => self::ValidationError,
            429 => self::RateLimitExceeded,
            503 => self::ServiceUnavailable,
            500 => self::InternalServerError,
            default => self::HttpError,
        };
    }

    /** Localized, human-readable title (core::errors.<CODE>). */
    public function title(): string
    {
        return __('core::errors.'.$this->value);
    }

    /** Relative problem-type URI (RFC 9457 §3.1.1), resolved against the API origin. */
    public function type(): string
    {
        return '/docs/problems/'.str_replace('_', '-', strtolower($this->value));
    }
}
