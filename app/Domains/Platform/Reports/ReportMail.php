<?php

declare(strict_types=1);

namespace App\Domains\Platform\Reports;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A report by email: a short summary, plus the CSV attached (or a private download link when it's large). */
final class ReportMail extends Mailable
{
    /** @param array{columns: list<string>, rows: list<list<string|int>>, total: int} $result */
    public function __construct(public readonly string $name, public readonly string $period, public readonly array $result, public readonly ?string $csv, public readonly ?string $link) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->name}: {$this->period}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.report', with: ['name' => $this->name, 'period' => $this->period, 'columns' => $this->result['columns'],
            'preview' => array_slice($this->result['rows'], 0, 10), 'total' => $this->result['total'], 'link' => $this->link, 'attached' => $this->csv !== null]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return $this->csv === null ? [] : [Attachment::fromData(fn () => (string) $this->csv, \Illuminate\Support\Str::slug($this->name).'.csv')->withMime('text/csv')];
    }
}
