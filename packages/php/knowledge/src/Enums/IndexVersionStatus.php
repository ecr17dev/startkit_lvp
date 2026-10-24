<?php

namespace Starter\Knowledge\Enums;

enum IndexVersionStatus: string
{
    case Building = 'building';
    case Active = 'active';
    case Superseded = 'superseded';
    case Failed = 'failed';
    case Discarded = 'discarded';
}
