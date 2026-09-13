<?php

namespace StreamEngine\Core;

use DateTimeZone;
use StreamEngine\Domain\User;

/**
 * Everything about the current request that a controller is allowed to know
 * without reaching for a superglobal.
 *
 * `$query` has a default so the dozens of existing call sites - almost all of
 * them tests, plus `StreamEngine::runCron()`, which has no query string at
 * all - keep working unchanged and get an empty bag, which is the right
 * answer for a request that has no query string.
 */
final readonly class RequestContext
{
    public function __construct(
        public User $user,
        public DateTimeZone $tz,
        public QueryParams $query = new QueryParams(),
    ) {

    }
}
