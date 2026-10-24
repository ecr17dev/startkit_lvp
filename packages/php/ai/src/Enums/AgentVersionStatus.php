<?php

namespace Starter\Ai\Enums;

/**
 * Lifecycle of an agent version: one editable draft, one published version
 * serving conversations, and the archived history kept for rollbacks.
 */
enum AgentVersionStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Archived = 'archived';
}
