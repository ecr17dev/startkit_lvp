<?php

namespace Starter\Ai\Enums;

/**
 * What an AI call was made for (ledger dimension).
 */
enum Feature: string
{
    case Chat = 'chat';
    case Title = 'title';
    case Test = 'test';
    case Recommendation = 'recommendation';
    case Lab = 'lab';
    case Embedding = 'embedding';
    case Rag = 'rag';
    case Transcription = 'transcription';
    case Tool = 'tool';
    case Other = 'other';
}
