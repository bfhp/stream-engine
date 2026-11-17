<?php

declare(strict_types=1);

namespace StreamEngine\Extension;

/**
 * Base class for site code that changes the behavior of platform operations.
 *
 * A subclass defines public methods named after the extension points it
 * handles and nothing else. The available points, their arguments, and the
 * meaning of their results are listed in docs/EXTENSIONS_CONTRACT.md.
 *
 * Subclasses are discovered from the Composer classmap, like module
 * controllers, and are constructed lazily with the same shared services a
 * controller can receive, except RequestContext.
 *
 * A method returns null when it does not want to change the outcome. The
 * first non-null result among all extensions wins.
 */
abstract class Extension
{
}
