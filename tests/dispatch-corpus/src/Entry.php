<?php

namespace App;

use function App\Sub\aliasTarget as importedTarget;

class Entry
{
    private readonly Port $port;

    public function __construct(Port $port)
    {
        $this->port = $port;
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
