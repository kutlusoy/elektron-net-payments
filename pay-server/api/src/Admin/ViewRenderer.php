<?php

namespace ElektronNet\Payments\PayServer\Admin;

final class ViewRenderer
{
    private string $templateDir;

    public function __construct(string $templateDir)
    {
        $this->templateDir = rtrim($templateDir, '/');
    }

    /**
     * @param array<string, mixed> $variables
     */
    public function render(string $template, array $variables): string
    {
        extract($variables, EXTR_SKIP);
        ob_start();
        require $this->templateDir . '/' . $template;
        return (string) ob_get_clean();
    }

    /**
     * Renders $contentTemplate, then wraps it in the shared admin shell
     * (nav + chrome).
     *
     * @param array<string, mixed> $variables
     */
    public function renderPage(string $title, string $active, string $contentTemplate, array $variables): string
    {
        $content = $this->render($contentTemplate, $variables);

        return $this->render('_shell.php', [
            'title' => $title,
            'active' => $active,
            'content' => $content,
            'merchantName' => $variables['merchantName'] ?? '',
        ]);
    }
}
