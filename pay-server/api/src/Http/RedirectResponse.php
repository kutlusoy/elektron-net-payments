<?php

namespace ElektronNet\Payments\PayServer\Http;

final class RedirectResponse implements Responder
{
    private string $location;

    public function __construct(string $location)
    {
        $this->location = $location;
    }

    public function send(): void
    {
        http_response_code(302);
        header('Location: ' . $this->location);
    }
}
