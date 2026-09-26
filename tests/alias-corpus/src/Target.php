<?php

namespace Symfony\Component\DependencyInjection\Attribute;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Target
{
    public function __construct(public string $name) {}
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final class AsAlias
{
    public function __construct(public string $id) {}
}
