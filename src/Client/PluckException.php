<?php

declare(strict_types=1);

namespace Pluck\Client;

use RuntimeException;

/**
 * Failure of the Pluck machinery itself (transport, malformed answer, invalid
 * request) as opposed to a well-formed {@see Outcome} the proxy reported.
 * Never swallow this and continue as if the page had no data.
 */
class PluckException extends RuntimeException {}
