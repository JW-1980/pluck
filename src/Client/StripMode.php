<?php

declare(strict_types=1);

namespace Pluck\Client;

enum StripMode: string
{
    case None = 'none';
    case Conservative = 'conservative';
    case Readability = 'readability';
    case XPath = 'xpath';
}
