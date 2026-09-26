<?php

namespace App;

final class AttributeEntry
{
    public function __construct(private AsAliasPort $port) {}
    public function run(): void { $this->port->expensive(); }
}
