<?php

namespace ElektronNet\Payments\PayServer\Http;

final class HtmlResponse implements Responder
{
    private int $statusCode;
    private string $html;

    public function __construct(int $statusCode, string $html)
    {
        $this->statusCode = $statusCode;
        $this->html = $html;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);
        header('Content-Type: text/html; charset=utf-8');
        echo $this->html;
    }
}
