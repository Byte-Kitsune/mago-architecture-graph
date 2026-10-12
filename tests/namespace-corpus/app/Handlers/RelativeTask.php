<?php

declare(strict_types=1);

namespace Sample\Flow;

final class RelativeTask
{
    public function build(): namespace\PeerTask { return new namespace\PeerTask(); }
}
