<?php

namespace Starter\Ai\Enums;

enum BudgetScope: string
{
    case Global = 'global';
    case User = 'user';
    case Role = 'role';
    case Feature = 'feature';
    case Connection = 'connection';
    case Agent = 'agent';
}
