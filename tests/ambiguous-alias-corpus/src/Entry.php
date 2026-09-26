<?php

namespace App;

final class Entry
{
    public function __construct(private Port $port) {}
    public function run(): void { $this->port->expensive(); }
}
