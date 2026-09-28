<?php

namespace App;

interface Port { public function hit(): void; }

final class Processor
{
    public function __construct(private Port $port) {}

    public function run(): void { $this->forward(); }

    private function forward(): void { $this->port->hit(); }
}

final class SafePort implements Port { public function hit(): void {} }

final class DangerPort implements Port { public function hit(): void {} }
