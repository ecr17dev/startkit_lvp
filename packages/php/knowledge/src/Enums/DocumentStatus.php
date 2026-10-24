<?php

namespace Starter\Knowledge\Enums;

enum DocumentStatus: string
{
    case Pending = 'pending';
    case Indexing = 'indexing';
    case Indexed = 'indexed';
    case Failed = 'failed';
    // No useful text (e.g. a scanned PDF without OCR); never shown as indexed...
    case Empty = 'empty';
    case Duplicate = 'duplicate';
    case Deleted = 'deleted';
}
