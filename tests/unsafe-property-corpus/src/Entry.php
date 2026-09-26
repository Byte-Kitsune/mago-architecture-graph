<?php

namespace App;

final class Entry
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

    public function replace(Port $port): void
    {
        $this->port = $port;
    }
}
