<?php

namespace Psr\Container;

interface ContainerInterface
{
    public function get(string $id): object;
}
