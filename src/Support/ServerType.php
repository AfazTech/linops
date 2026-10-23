<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Support;

enum ServerType: string
{
    case VPS = 'vps';
    case DEDICATED = 'dedicated';
    case UNKNOWN = 'unknown';
}
