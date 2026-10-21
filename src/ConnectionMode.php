<?php

declare(strict_types=1);

namespace AfazTech\LinOps;

/**
 * Determines how command execution is dispatched.
 */
enum ConnectionMode: string
{
    /** Execute commands over SSH via the worker pool. */
    case SSH = 'ssh';

    /** Execute commands directly on the host running this process. */
    case LOCAL = 'local';
}
