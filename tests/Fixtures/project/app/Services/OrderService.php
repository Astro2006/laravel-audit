<?php

namespace App\Services;

class OrderService
{
    public function update($order, array $data)
    {
        $order->fill($data)->save();

        return $order;
    }
}
