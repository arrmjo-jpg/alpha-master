<?php

namespace App\Modules\Users\Enums;

/** Mutually exclusive account classification (ADR-0002). Not a permission. */
enum AccountType: string
{
    case Admin = 'admin';
    case Writer = 'writer';
    case User = 'user';
}
