<?php

namespace Starter\Ai\Enums;

enum RequestStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Blocked = 'blocked';
    case Interrupted = 'interrupted';
}
