<?php

namespace App\Mail;

use App\Models\Upload;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * The subtitles of a ready upload, attached (one file per format). Sent by the
 * SendSubtitlesEmail job, which deletes the upload right after.
 */
class SubtitlesReady extends Mailable
{
    private const MIME_TYPES = [
        'srt' => 'application/x-subrip',
        'vtt' => 'text/vtt',
    ];

    public function __construct(public Upload $upload) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Your subtitles for {$this->upload->original_name}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.subtitles-ready');
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (string $format): Attachment => Attachment::fromStorageDisk(config('uploads.disk'), $this->upload->subtitlePath($format))
                ->as($this->upload->subtitleFileName($format))
                ->withMime(self::MIME_TYPES[$format]),
            $this->upload->subtitleFormats(),
        );
    }
}
