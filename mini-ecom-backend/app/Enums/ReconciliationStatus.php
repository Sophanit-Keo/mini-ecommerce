<?php

namespace App\Enums;

enum ReconciliationStatus: string
{
    // Finalization has not yet established the authoritative basket total.
    case Pending = 'pending';

    // The authorized/paid amount and final total match, or an admin has recorded the required
    // external collection/refund outcome with a reference.
    case Settled = 'settled';

    // Final basket cost exceeds the amount already paid/authorized.
    case AmountDue = 'amount_due';

    // Final basket cost is lower than the amount already paid/authorized.
    case RefundDue = 'refund_due';

    // Cash-on-delivery does not have a pre-delivery processor amount to reconcile.
    case NotRequired = 'not_required';

    public function blocksDispatch(): bool
    {
        return in_array($this, [self::Pending, self::AmountDue, self::RefundDue], true);
    }
}
