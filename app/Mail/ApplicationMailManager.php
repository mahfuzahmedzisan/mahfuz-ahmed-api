<?php

namespace App\Mail;

use App\Services\ApplicationSettings;
use Illuminate\Mail\MailManager;

/**
 * Rebuilds the mailer after settings are read, so a long-running worker
 * picks up an SMTP change on the next message instead of the config it booted with.
 */
class ApplicationMailManager extends MailManager
{
    private bool $applying = false;

    public function mailer($name = null)
    {
        if (! $this->applying) {
            $this->applying = true;

            try {
                $this->app->make(ApplicationSettings::class)->applyMailSettings();
                $this->mailers = [];
            } finally {
                $this->applying = false;
            }
        }

        return parent::mailer($name);
    }
}
