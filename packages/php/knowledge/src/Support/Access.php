<?php

namespace Starter\Knowledge\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Starter\Knowledge\Models\Collection;
use Starter\Knowledge\Models\Document;

/**
 * Who may see what. Managers see every collection's configuration, but
 * content (documents, chunks, originals, search results) always requires
 * matching the access lists of the collection and the document.
 */
final class Access
{
    public static function canManage(?Authenticatable $user): bool
    {
        return $user !== null && Gate::forUser($user)->allows('rag.collections.manage');
    }

    public static function canSeeCollection(?Authenticatable $user, Collection $collection): bool
    {
        if ($user === null) {
            return false;
        }

        if (self::canManage($user)) {
            return true;
        }

        return Gate::forUser($user)->allows('rag.collections.read') && $collection->isVisibleTo($user);
    }

    public static function canReadContent(?Authenticatable $user, Collection $collection): bool
    {
        return $user !== null && Gate::forUser($user)->allows('rag.documents.read') && $collection->isVisibleTo($user);
    }

    public static function canReadDocument(?Authenticatable $user, Document $document): bool
    {
        if (! self::canReadContent($user, $document->collection)) {
            return false;
        }

        return array_intersect($document->effectiveAcl(), Principals::for($user)) !== [];
    }
}
