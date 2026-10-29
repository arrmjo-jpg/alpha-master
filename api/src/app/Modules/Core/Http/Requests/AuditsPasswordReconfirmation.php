<?php

namespace App\Modules\Core\Http\Requests;

use App\Modules\Core\Audit\AuditLogger;
use App\Modules\Core\Audit\AuditOutcome;
use Illuminate\Contracts\Validation\Validator;

/**
 * For Form Requests that re-confirm the actor's password (`current_password`).
 * A wrong password is recorded as `auth.reconfirm_failed` so guessing with a
 * stolen session or token is visible; the attempts are also rate-limited by
 * the `sensitive` limiter on the route.
 */
trait AuditsPasswordReconfirmation
{
    protected function failedValidation(Validator $validator): void
    {
        if ($validator->errors()->has('current_password')) {
            app(AuditLogger::class)->record('auth.reconfirm_failed', $this->user(), [
                'route' => $this->route()?->getName() ?? $this->path(),
            ], AuditOutcome::Failed);
        }

        parent::failedValidation($validator);
    }
}
