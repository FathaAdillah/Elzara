<?php

declare(strict_types=1);

namespace App\Modules\Identity\Infrastructure\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

// Kode OTP atau token reset sekali-pakai. Nilai mentah hanya ada di email ini;
// basis data menyimpan hash Argon2id.
final class OneTimeCodeMail extends Mailable
{
    use Queueable;

    public function __construct(
        public readonly string $purpose,
        public readonly string $secret,
        public readonly string $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->purpose === 'reset_password_link'
            ? 'Atur ulang kata sandi Elzara'
            : 'Kode verifikasi Elzara');
    }

    public function content(): Content
    {
        return new Content(htmlString: sprintf(
            '<p>%s: <strong>%s</strong></p><p>Berlaku sampai %s. Abaikan email ini bila Anda tidak memintanya.</p>',
            $this->purpose === 'reset_password_link' ? 'Token atur ulang kata sandi' : 'Kode verifikasi',
            e($this->secret),
            e($this->expiresAt),
        ));
    }
}
