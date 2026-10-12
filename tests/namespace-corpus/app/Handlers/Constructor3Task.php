<?php

declare(strict_types=1);

namespace Sample\Flow;

final class Constructor3Task
{
    public function __construct(public PeerTask|PlainValue $peer) {}
}
