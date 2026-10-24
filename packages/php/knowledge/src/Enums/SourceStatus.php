<?php

namespace Starter\Knowledge\Enums;

enum SourceStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Deleting = 'deleting';
}
