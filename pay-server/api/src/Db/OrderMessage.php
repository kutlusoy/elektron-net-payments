<?php

namespace ElektronNet\Payments\PayServer\Db;

/**
 * Section 11: "a per-order, append-only message log both sides can write
 * to" -- human-authored communication, kept deliberately distinct from
 * `order_events` (the system's own automated audit trail).
 */
final class OrderMessage
{
    public string $id;
    public string $orderId;
    public string $sender;
    public string $body;
    public string $createdAt;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        $message = new self();
        $message->id = (string) $row['id'];
        $message->orderId = (string) $row['order_id'];
        $message->sender = (string) $row['sender'];
        $message->body = (string) $row['body'];
        $message->createdAt = (string) $row['created_at'];

        return $message;
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'sender' => $this->sender,
            'body' => $this->body,
            'created_at' => $this->createdAt,
        ];
    }
}
