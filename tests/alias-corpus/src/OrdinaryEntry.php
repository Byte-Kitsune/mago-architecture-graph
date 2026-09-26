<?php

namespace App;

final class OrdinaryEntry
{
    private Port $port;

    public function __construct(Port $port)
    {
        $this->port = $port;
    }

    public function run(): void
    {
        $this->port->expensive();
    }
}
