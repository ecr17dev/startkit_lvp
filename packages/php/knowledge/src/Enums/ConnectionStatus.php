<?php

namespace Starter\Knowledge\Enums;

enum ConnectionStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Failed = 'failed';
    case Disabled = 'disabled';
}
