<?php

namespace App;

use Psr\Container\ContainerInterface;

final class Entry
{
    public function __construct(private ContainerInterface $container) {}

    public function safe(): void { $this->container->get('processor.safe')->run(); }

    public function danger(): void { $this->container->get('processor.danger')->run(); }

    public function alias(): void { $this->container->get('processor.alias')->run(); }
}
