<?php

namespace App\Enums;

enum CartStatus: string
{
    case Active = 'active';
    case Converted = 'converted';
    case Merged = 'merged';
    case Abandoned = 'abandoned';
}
