<?php

namespace ElektronNet\Payments\PayServer\Http;

/**
 * Common contract for anything a route handler can return: a normal
 * buffered JSON response (JsonResponse) or a long-lived streamed one
 * (SseResponse, section 10.C). The front controller just calls send() on
 * whatever comes back without needing to know which one it got.
 */
interface Responder
{
    public function send(): void;
}
