<?php

namespace Starter\Ai\Agents;

/**
 * How a tool changes the application, which decides whether it runs
 * directly or waits for a confirmed proposal.
 */
enum ToolKind: string
{
    // Authorized reads run directly...
    case Read = 'read';

    // Ordinary changes explicitly requested run directly unless the agent
    // requires confirmation for every write...
    case Write = 'write';

    // Deletions, role and permission changes always need a confirmed proposal...
    case Sensitive = 'sensitive';
}
