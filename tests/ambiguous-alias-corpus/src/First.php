<?php

namespace App;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(Port::class)]
final class First implements Port { public function expensive(): void {} }
