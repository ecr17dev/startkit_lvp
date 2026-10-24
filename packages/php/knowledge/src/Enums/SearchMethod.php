<?php

namespace Starter\Knowledge\Enums;

enum SearchMethod: string
{
    case Vector = 'vector';
    case Text = 'text';
    case Hybrid = 'hybrid';
}
