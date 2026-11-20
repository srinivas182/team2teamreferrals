<?php

declare(strict_types=1);

namespace App\Support\Mail;

use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends email through Brevo's HTTP API (https://developers.brevo.com/reference/sendtransacemail) instead of SMTP.
 * HTTPS on port 443 works on servers whose hosting company blocks the email ports (587, 465, 25).
 * The From address must be a sender verified in Brevo (Senders, domains → Senders).
 */
final class BrevoApiTransport extends AbstractTransport
{
    public const ENDPOINT = 'https://api.brevo.com/v3/smtp/email';

    public function __construct(private readonly string $apiKey)
    {
        parent::__construct();
    }

    protected function doSend(SentMessage $message): void
    {
        $original = $message->getOriginalMessage();
        if (! $original instanceof Message) {
            throw new TransportException('Brevo’s API needs a structured email, not a raw message.');
        }
        $email = MessageConverter::toEmail($original);
        $from = $email->getFrom()[0] ?? $message->getEnvelope()->getSender();
        $payload = array_filter([
            'sender' => self::person($from),
            'to' => array_map(self::person(...), $email->getTo() ?: $message->getEnvelope()->getRecipients()),
            'cc' => array_map(self::person(...), $email->getCc()),
            'bcc' => array_map(self::person(...), $email->getBcc()),
            'replyTo' => ($r = $email->getReplyTo()[0] ?? null) ? self::person($r) : null,
            'subject' => (string) $email->getSubject(),
            'htmlContent' => self::body($email->getHtmlBody()),
            'textContent' => self::body($email->getTextBody()),
            'attachment' => array_map(fn ($a) => ['name' => $a->getFilename() ?? 'attachment', 'content' => base64_encode($a->getBody())], $email->getAttachments()),
        ], fn ($v) => $v !== null && $v !== [] && $v !== '');
        if (! isset($payload['htmlContent']) && ! isset($payload['textContent'])) {
            $payload['textContent'] = ' '; // Brevo needs one of the two; an empty message is still sent.
        }

        $response = Http::withHeaders(['api-key' => $this->apiKey, 'accept' => 'application/json'])->timeout(15)->post(self::ENDPOINT, $payload);
        if (! $response->successful()) {
            throw new TransportException('Brevo refused the email ('.$response->status().'): '.mb_substr((string) ($response->json('message') ?? $response->body()), 0, 300));
        }
        if ($id = $response->json('messageId')) {
            $message->setMessageId((string) $id);
        }
    }

    public function __toString(): string
    {
        return 'brevo+api://api.brevo.com';
    }

    /** @return array{email: string, name?: string} */
    private static function person(Address $a): array
    {
        $person = ['email' => $a->getAddress()];
        if ($a->getName() !== '') {
            $person['name'] = $a->getName();
        }

        return $person;
    }

    /** @param  resource|string|null  $body */
    private static function body(mixed $body): ?string
    {
        if (is_resource($body)) {
            $body = stream_get_contents($body);
        }

        return is_string($body) ? $body : null;
    }
}
