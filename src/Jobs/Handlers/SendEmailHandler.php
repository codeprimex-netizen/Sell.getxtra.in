<?php

declare(strict_types=1);

namespace App\Jobs\Handlers;

use App\Infrastructure\Mail\Mailer;
use App\Infrastructure\Queue\JobHandler;

/**
 * Sends a transactional email (Req 13.1). Payload: to, subject, template,
 * vars. Renders a minimal HTML body from the template + vars.
 */
final class SendEmailHandler implements JobHandler
{
    public function __construct(private Mailer $mailer)
    {
    }

    public function handle(array $payload): void
    {
        $to = (string) ($payload['to'] ?? '');
        if ($to === '') {
            return;
        }
        $subject = (string) ($payload['subject'] ?? 'Notification from Code.getxtra.in');
        $html = $this->render((string) ($payload['template'] ?? 'generic'), (array) ($payload['vars'] ?? []));

        $this->mailer->send($to, $subject, $html);
    }

    /** @param array<string,mixed> $vars */
    private function render(string $template, array $vars): string
    {
        $lines = '';
        foreach ($vars as $key => $value) {
            if (is_scalar($value)) {
                $lines .= '<p><strong>' . htmlspecialchars((string) $key) . ':</strong> '
                    . htmlspecialchars((string) $value) . '</p>';
            }
        }
        $title = htmlspecialchars(ucwords(str_replace(['_', '.'], ' ', $template)));

        return '<!doctype html><html><body style="font-family:sans-serif">'
            . '<h2>' . $title . '</h2>' . $lines
            . '<hr><p style="color:#888;font-size:12px">Code.getxtra.in</p></body></html>';
    }
}
