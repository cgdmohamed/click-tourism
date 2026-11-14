<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A scheduled report with its file attached (ADR-0026). The body holds no report data. */
class ScheduledReportMail extends Mailable
{
    public function __construct(
        private readonly string $reportName,
        private readonly string $period,
        private readonly string $fileName,
        private readonly string $fileContent,
        private readonly string $mime,
        private readonly string $company,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[{$this->company}] {$this->reportName} - {$this->period}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.scheduled-report', with: ['reportName' => $this->reportName, 'period' => $this->period, 'company' => $this->company]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [Attachment::fromData(fn () => $this->fileContent, $this->fileName)->withMime($this->mime)];
    }
}
