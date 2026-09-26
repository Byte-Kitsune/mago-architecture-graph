<?php

namespace App;

use function App\Sub\aliasTarget as importedTarget;
use Psr\Container\ContainerInterface;

class CompleteEntry
{
    private readonly Port $port;
    private readonly ContainerInterface $container;

    public function __construct(Port $port, ContainerInterface $container)
    {
        $this->port = $port;
        $this->container = $container;
    }

    public function run(string $text): void
    {
        if (\strlen($text) === 0) return;
        $this->fixed();
    }

    final public function fixed(): void
    {
        $this->port->expensive();
    }

    public function callback(): void
    {
        \call_user_func([$this->port, 'expensive']);
    }

    public function arrayCallback(): void
    {
        \call_user_func_array([$this->port, 'expensive'], []);
    }

    public function staticCallback(): void
    {
        \call_user_func([Gateway::class, 'staticExpensive']);
    }

    public function construction(): void
    {
        new Construction();
    }

    public function inheritedConstruction(): void
    {
        new ChildConstruction();
    }

    public function functionChain(): void
    {
        bridge();
    }

    public function importedFunction(): void
    {
        importedTarget();
    }

    public function functionConstruction(): void
    {
        constructGateway();
    }

    public function serviceById(): void
    {
        $this->container->get('gateway.service')->expensive();
    }

    public function serviceByAlias(): void
    {
        $this->container->get('gateway.alias')->expensive();
    }

    public function serviceByClass(): void
    {
        $this->container->get(Gateway::class)->expensive();
    }

    public function serviceByLocal(): void
    {
        $service = $this->container->get('gateway.alias');
        $service->expensive();
    }
}
