<?php

it('checks out', function () {
    $page = visit('/checkout');

    $page->assertSee('Pay');

    visit('/cart');
});
