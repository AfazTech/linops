<?php

declare(strict_types=1);

namespace AfazTech\LinOps\Support;

enum HostKeyPolicy: string
{
    /**
     * The server host key must be present in known_hosts and match exactly.
     * This is the default and recommended policy for production.
     */
    case STRICT = 'strict';

    /**
     * Trust on first use: if the host is unknown, store its key in known_hosts.
     * If the host is known, the key must match.
     */
    case TOFU = 'tofu';

    /**
     * Do not verify the host key. This is insecure and must be requested explicitly.
     */
    case INSECURE = 'insecure';
}
