<?php

namespace ElektronNet\Payments\PayServer\Http;

/**
 * Serves one static file at a fixed, hardcoded absolute path (never a
 * user-supplied one, so there is no path-traversal surface to guard
 * against). Used for the small, fixed set of checkout/admin CSS and JS
 * assets registered as their own routes in public/index.php. A real
 * deployment would typically front these same paths with nginx/Apache
 * serving the files directly and never reaching PHP at all; this keeps
 * local/dev runs (and this repository's own CI) working with nothing more
 * than the PHP built-in server.
 */
final class FileResponse implements Responder
{
    private string $absolutePath;
    private string $contentType;

    public function __construct(string $absolutePath, string $contentType)
    {
        $this->absolutePath = $absolutePath;
        $this->contentType = $contentType;
    }

    public function send(): void
    {
        if (!is_file($this->absolutePath)) {
            (new JsonResponse(404, ['error' => 'not_found', 'message' => 'Asset not found.']))->send();
            return;
        }

        header('Content-Type: ' . $this->contentType);
        header('Cache-Control: public, max-age=3600');
        readfile($this->absolutePath);
    }
}
