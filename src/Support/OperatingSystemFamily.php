<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Support;

enum OperatingSystemFamily: string
{
    case RHEL = 'rhel';
    case DEBIAN = 'debian';
    case UNKNOWN = 'unknown';
}
