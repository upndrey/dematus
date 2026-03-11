<?php

namespace App\Enums\Stratz;

enum MatchPlayerPositionType: string
{
    case Position1 = 'POSITION_1';
    case Position2 = 'POSITION_2';
    case Position3 = 'POSITION_3';
    case Position4 = 'POSITION_4';
    case Position5 = 'POSITION_5';
    case Unknown = 'UNKNOWN';
    case Filtered = 'FILTERED';
    case All = 'ALL';
}
