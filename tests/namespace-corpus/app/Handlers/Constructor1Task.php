<?php

declare(strict_types=1);

namespace Sample\Flow;

final class Constructor1Task
{
    public function __construct(public PeerTask $peer) {}
}
