<?php

namespace App\Enums;

enum FulfillmentEventType: string
{
    case Picked = 'picked';
    case Substituted = 'substituted';
    case Unavailable = 'unavailable';
    case Finalized = 'finalized';
    case Reconciled = 'reconciled';
}
