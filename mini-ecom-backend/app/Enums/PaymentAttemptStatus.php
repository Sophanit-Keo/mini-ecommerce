<?php

namespace App\Enums;

enum PaymentAttemptStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Expired = 'expired';
    case Failed = 'failed';
}
