<?php

declare(strict_types=1);

namespace AfazTech\LinOps;

/**
 * Selects how executeCommand() presents output to the caller.
 */
enum OutputMode: string
{
    /** Buffer all output and return it in the CommandResult after completion. */
    case CAPTURE = 'capture';

    /** Stream chunks to $onChunk in real time; stdout/stderr stay null in the result. */
    case STREAM = 'stream';

    /** Stream chunks and forward local STDIN (or a custom provider) to the process. */
    case INTERACTIVE = 'interactive';
}
