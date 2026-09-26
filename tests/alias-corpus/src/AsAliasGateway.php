<?php

namespace App;

use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(AsAliasPort::class)]
final class AsAliasGateway implements AsAliasPort
{
    public function expensive(): void {}
}
