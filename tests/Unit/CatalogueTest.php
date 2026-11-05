<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The catalogue is the only source of prices, so its shape is worth guarding. */
class CatalogueTest extends TestCase
{
    private array $shop;

    protected function setUp(): void
    {
        $this->shop = require dirname(__DIR__, 2).'/config/shop.php';
    }

    public function test_every_product_has_a_name_and_a_positive_integer_amount_in_cents(): void
    {
        $this->assertNotEmpty($this->shop['products']);

        foreach ($this->shop['products'] as $sku => $product) {
            $this->assertNotSame('', trim($product['name']), "{$sku} needs a name");
            $this->assertIsInt($product['amount'], "{$sku} amount must be an integer number of cents");
            $this->assertGreaterThan(0, $product['amount'], "{$sku} amount must be positive");
        }
    }

    public function test_currency_is_a_three_letter_lowercase_code(): void
    {
        $this->assertMatchesRegularExpression('/^[a-z]{3}$/', $this->shop['currency']);
    }
}
