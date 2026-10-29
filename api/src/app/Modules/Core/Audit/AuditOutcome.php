<?php

namespace App\Modules\Core\Audit;

enum AuditOutcome: string
{
    case Success = 'success';
    case Denied = 'denied';
    case Failed = 'failed';
}
