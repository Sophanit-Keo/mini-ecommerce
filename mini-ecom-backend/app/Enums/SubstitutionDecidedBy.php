<?php

namespace App\Enums;

/**
 * Records customer intent separately from picker outcome — the distinction the whole
 * `order_item_substitutions` table exists to preserve.
 */
enum SubstitutionDecidedBy: string
{
    case Picker = 'picker';
    case Customer = 'customer';
}
