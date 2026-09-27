<?php
namespace App\Scope;
use App\Gateway;
use App\OtherGateway;
final class SafeEntry {
    public function temporary(): void { (new Gateway())->expensive(); }
    public function local(): void {
        $gateway = new Gateway();
        $gateway->expensive();
    }
}
final class UnsafeEntry {
    public function reassigned(): void {
        $gateway = new Gateway();
        $gateway = new OtherGateway();
        $gateway->expensive();
    }
    public function branched(bool $switch): void {
        $gateway = new Gateway();
        if ($switch) $gateway = new OtherGateway();
        $gateway->expensive();
    }
    public function escaped(): void {
        $gateway = new Gateway();
        $gateway->accept($gateway);
    }
}
