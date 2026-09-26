<?php

namespace App;

use function App\Sub\aliasTarget as importedTarget;
use Psr\Container\ContainerInterface;

class Entry
{
    private readonly Port $port;
    private readonly ContainerInterface $container;

    public function __construct(Port $port, ContainerInterface $container)
    {
        $this->port = $port;
        $this->container = $container;
    }

    public function run(): void
    {
        $this->helper();
    }

    private function helper(): void
    {
        $this->port->expensive();
    }

    public function finalDispatch(): void
    {
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

    public function unqualifiedCallback(): void
    {
        call_user_func([$this->port, 'expensive']);
    }

    public function staticCallback(): void
    {
        \call_user_func([Gateway::class, 'staticExpensive']);
    }

    public function dynamic(callable $callback): void
    {
        $callback();
    }

    public function namedFunction(): void
    {
        bridge();
    }

    public function importedFunction(): void
    {
        importedTarget();
    }

    public function dynamicFunctionBody(): void
    {
        dynamicHelper();
    }

    public function unknownService(): void
    {
        $this->container->get('unknown.service')->expensive();
    }

    public function dynamicService(string $id): void
    {
        $this->container->get($id)->expensive();
    }

    public function escapedService(): object
    {
        return $this->container->get('gateway.service');
    }

    public function reassignedService(): void
    {
        $service = $this->container->get('gateway.service');
        $service = new Gateway();
        $service->expensive();
    }

    public function dynamicConstruction(string $name): void
    {
        new $name();
    }

    public function externalConstruction(): void
    {
        new \DateTimeImmutable();
    }

    public function deferred(): void
    {
        Gateway::staticExpensive(...);
    }

    public function invalidStaticCallback(): void
    {
        \call_user_func([Gateway::class, 'expensive']);
    }

    public function overridable(): void
    {
        $this->hook();
    }

    public function hook(): void {}
}
