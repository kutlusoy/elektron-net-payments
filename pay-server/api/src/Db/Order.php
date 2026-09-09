<?php

namespace ElektronNet\Payments\PayServer\Db;

use ElektronNet\Payments\PayServer\Bip21;

final class Order
{
    public string $id;
    public string $merchantId;
    public string $mode;
    public string $status;
    public int $amountLep;
    public string $address;
    public ?string $externalReference;
    public string $expiresAt;
    public int $requiredConfirmations;
    public string $createdAt;
    /** Set only if this order was priced in fiat and converted at creation time (section 12), e.g. via /admin/terminal. */
    public ?string $fiatCurrency;
    public ?string $fiatAmount;
    public ?string $exchangeRateUsed;
    public ?string $refundAddress;
    public ?string $paymentRequestId;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $order = new self();
        $order->id = (string) $row['id'];
        $order->merchantId = (string) $row['merchant_id'];
        $order->mode = (string) $row['mode'];
        $order->status = (string) $row['status'];
        $order->amountLep = (int) $row['amount_lep'];
        $order->address = (string) $row['address'];
        $order->externalReference = $row['external_reference'] !== null ? (string) $row['external_reference'] : null;
        $order->expiresAt = (string) $row['expires_at'];
        $order->requiredConfirmations = (int) $row['required_confirmations'];
        $order->createdAt = (string) $row['created_at'];
        $order->fiatCurrency = ($row['fiat_currency'] ?? null) !== null ? (string) $row['fiat_currency'] : null;
        $order->fiatAmount = ($row['fiat_amount'] ?? null) !== null ? (string) $row['fiat_amount'] : null;
        $order->exchangeRateUsed = ($row['exchange_rate_used'] ?? null) !== null ? (string) $row['exchange_rate_used'] : null;
        $order->refundAddress = ($row['refund_address'] ?? null) !== null ? (string) $row['refund_address'] : null;
        $order->paymentRequestId = ($row['payment_request_id'] ?? null) !== null ? (string) $row['payment_request_id'] : null;

        return $order;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(string $checkoutBaseUrl): array
    {
        return [
            'order_id' => $this->id,
            'mode' => $this->mode,
            'status' => $this->status,
            'amount_lep' => $this->amountLep,
            'currency' => 'ELEK',
            'address' => $this->address,
            'external_reference' => $this->externalReference,
            'expires_at' => $this->expiresAt,
            'required_confirmations' => $this->requiredConfirmations,
            'created_at' => $this->createdAt,
            'checkout_url' => $checkoutBaseUrl . '/order/' . $this->id,
            'fiat_currency' => $this->fiatCurrency,
            'fiat_amount' => $this->fiatAmount !== null ? (float) $this->fiatAmount : null,
            'exchange_rate_used' => $this->exchangeRateUsed !== null ? (float) $this->exchangeRateUsed : null,
        ];
    }

    /**
     * Buyer-facing fields only (section 21: the order id itself is the
     * buyer's only credential -- this is deliberately narrower than
     * toApiArray(), never includes external_reference or merchant_id, and
     * is what the public checkout page's status polling/SSE endpoint
     * returns).
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(string $merchantLabel): array
    {
        return [
            'order_id' => $this->id,
            'mode' => $this->mode,
            'status' => $this->status,
            'amount_lep' => $this->amountLep,
            'currency' => 'ELEK',
            'address' => $this->address,
            'payment_uri' => Bip21::paymentUri($this->address, $this->amountLep, $merchantLabel),
            'expires_at' => $this->expiresAt,
            'required_confirmations' => $this->requiredConfirmations,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toAdminArray(): array
    {
        return [
            'order_id' => $this->id,
            'mode' => $this->mode,
            'status' => $this->status,
            'amount_lep' => $this->amountLep,
            'currency' => 'ELEK',
            'address' => $this->address,
            'external_reference' => $this->externalReference,
            'expires_at' => $this->expiresAt,
            'required_confirmations' => $this->requiredConfirmations,
            'created_at' => $this->createdAt,
            'fiat_currency' => $this->fiatCurrency,
            'fiat_amount' => $this->fiatAmount,
            'exchange_rate_used' => $this->exchangeRateUsed,
        ];
    }
}
