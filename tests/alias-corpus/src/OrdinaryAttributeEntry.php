<?php

namespace App;

final class OrdinaryAttributeEntry
{
    private AsAliasPort $port;

    public function __construct(AsAliasPort $port)
    {
        $this->port = $port;
    }

    public function run(): void
    {
        $this->port->expensive();
    }
}
