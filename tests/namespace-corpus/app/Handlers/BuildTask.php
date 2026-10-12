<?php

declare(strict_types=1);

namespace Sample\Flow;

final class BuildTask
{
    public function build(): PeerTask { return new PeerTask(); }
}
