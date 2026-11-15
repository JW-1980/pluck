<?php

declare(strict_types=1);

namespace Pluck\Contracts;

use Pluck\Support\BlockedTarget;

interface TargetValidator
{
    /**
     * Vets a URL before anything is requested and returns the address the
     * connection must be pinned to.
     *
     * @return array{scheme:string,host:string,port:int,ip:string}
     * @throws BlockedTarget
     */
    public function vet(string $url): array;
}
