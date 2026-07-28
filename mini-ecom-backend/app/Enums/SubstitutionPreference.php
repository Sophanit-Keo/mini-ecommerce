<?php

namespace App\Enums;

/**
 * What the customer wants done when a line cannot be picked as ordered.
 */
enum SubstitutionPreference: string
{
    case None = 'none';
    case Similar = 'similar';
    case ContactMe = 'contact_me';
}
