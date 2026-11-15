<?php

declare(strict_types=1);

namespace Pluck\Support;

use RuntimeException;

/** The target URL failed SSRF validation; nothing was requested. */
final class BlockedTarget extends RuntimeException {}
