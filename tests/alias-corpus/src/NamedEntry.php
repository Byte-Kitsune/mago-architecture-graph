<?php

namespace App;

use Symfony\Component\DependencyInjection\Attribute\Target;

final class NamedEntry
{
    public function __construct(#[Target('admin')] private Port $port) {}
    public function run(): void { $this->port->expensive(); }
}
