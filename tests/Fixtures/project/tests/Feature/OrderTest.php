<?php

use Acme\TestOnly\Factory;
use App\Http\Controllers\OrderController;

it('updates an order', function () {
    Factory::make();

    $this->put('/orders/1')->assertOk();
    $this->get(route('orders.index'))->assertOk();
});

it('references the controller', function () {
    expect(OrderController::class)->toBeString();
});
