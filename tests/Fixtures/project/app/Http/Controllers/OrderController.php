<?php

namespace App\Http\Controllers;

use Acme\DevTool\Debugger;
use Acme\Transitive\Client;
use Acme\UsedLib\Formatter;
use Acme\UsedLib\Money;
use App\Services\OrderService;
use Illuminate\Http\Request;

class OrderController
{
    public function update(Request $request, OrderService $orders)
    {
        Debugger::log($request->all());

        return (new Formatter)->format(Money::of(1), new Client);
    }
}
