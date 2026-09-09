<?php

namespace ElektronNet\Payments\PayServer\Http;

final class JsonResponse
{
    public int $statusCode;
    /** @var array<string, mixed> */
    public array $data;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(int $statusCode, array $data)
    {
        $this->statusCode = $statusCode;
        $this->data = $data;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);
        header('Content-Type: application/json');
        echo json_encode($this->data, JSON_UNESCAPED_SLASHES);
    }
}
