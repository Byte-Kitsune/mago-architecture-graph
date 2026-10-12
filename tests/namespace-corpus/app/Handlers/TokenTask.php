<?php

declare(strict_types=1);

namespace Sample\Flow;

final class TokenTask
{
    public function name(): string { return PeerTask::class; }
}
