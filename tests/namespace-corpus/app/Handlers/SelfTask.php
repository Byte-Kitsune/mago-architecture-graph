<?php

declare(strict_types=1);

namespace Sample\Flow;

final class SelfTask
{
    public function copy(): SelfTask { return new SelfTask(); }
}
