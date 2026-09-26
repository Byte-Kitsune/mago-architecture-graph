<?php

namespace App;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(Port::class)]
final class Second implements Port { public function expensive(): void {} }
