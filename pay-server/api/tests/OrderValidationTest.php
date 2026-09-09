<?php

namespace ElektronNet\Payments\PayServer\Tests;

use ElektronNet\Payments\PayServer\Db\Merchant;
use ElektronNet\Payments\PayServer\Http\ApiException;
use ElektronNet\Payments\PayServer\OrderValidation;
use PHPUnit\Framework\TestCase;

final class OrderValidationTest extends TestCase
{
    private function merchant(array $overrides = []): Merchant
    {
        return Merchant::fromRow(array_merge([
            'id' => 'merchant-1',
            'name' => 'Demo Merchant',
            'display_name' => null,
            'logo_url' => null,
            'theme_color' => null,
            'base_currency' => 'ELEK',
            'receiving_xpub' => 'xpub6D4BDPcP2GT577Vvch3R8wDkScZWzQzMMUm3PWbmWvVJrZwQY4VUNgqFJPMM3No2dFDFGTsxxpG5uJh7n7epu4trkrX7x7DogT5Uv6fcLW5',
            'order_expiry_minutes' => 15,
            'default_required_confirmations' => 1,
            'underpayment_tolerance_percent' => '0',
        ], $overrides));
    }

    public function testValidDirectOrderPasses(): void
    {
        $this->expectNotToPerformAssertions();
        OrderValidation::validateCreateOrderPayload(
            ['mode' => 'direct', 'amount' => 1.5],
            $this->merchant(),
            false
        );
    }

    public function testEscrowRejectedWhenDisabled(): void
    {
        $this->expectException(ApiException::class);
        try {
            OrderValidation::validateCreateOrderPayload(
                ['mode' => 'escrow', 'amount' => 1.0],
                $this->merchant(),
                false
            );
        } catch (ApiException $e) {
            $this->assertSame('escrow_disabled', $e->errorCode());
            throw $e;
        }
    }

    public function testNonPositiveAmountRejected(): void
    {
        $this->expectException(ApiException::class);
        OrderValidation::validateCreateOrderPayload(
            ['mode' => 'direct', 'amount' => 0],
            $this->merchant(),
            false
        );
    }

    public function testNonElekCurrencyRejectedWithoutPriceFeed(): void
    {
        $this->expectException(ApiException::class);
        OrderValidation::validateCreateOrderPayload(
            ['mode' => 'direct', 'amount' => 1.0, 'currency' => 'USD'],
            $this->merchant(),
            false
        );
    }

    public function testMissingReceivingXpubRejected(): void
    {
        $this->expectException(ApiException::class);
        try {
            OrderValidation::validateCreateOrderPayload(
                ['mode' => 'direct', 'amount' => 1.0],
                $this->merchant(['receiving_xpub' => null]),
                false
            );
        } catch (ApiException $e) {
            $this->assertSame('receiving_wallet_not_connected', $e->errorCode());
            throw $e;
        }
    }
}
