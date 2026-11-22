<?php

namespace App\Member;

enum Status
{
    case Done;
    case Rejected;
    case Failed;
    case Partial;
}
