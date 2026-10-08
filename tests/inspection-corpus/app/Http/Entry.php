<?php
namespace Frontend;
final class Entry {
    public function __construct(private \Storage\Store $store, private \Storage\Unused $unused) {}
    public function index(): void { $this->store->find(); }
}
