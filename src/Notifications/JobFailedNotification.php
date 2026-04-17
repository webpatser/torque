<?php

declare(strict_types=1);

namespace Webpatser\Torque\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Webpatser\Torque\Support\PayloadSanitizer;

/**
 * Notification sent when a job permanently fails after exhausting all retries.
 *
 * Supports mail and array (database) channels out of the box. Users can extend
 * or replace this with their own notification by listening to the
 * {@see \Webpatser\Torque\Events\JobPermanentlyFailed} event.
 */
final class JobFailedNotification extends Notification
{
    public function __construct(
        public readonly string $jobName,
        public readonly string $queue,
        public readonly string $exceptionMessage,
        public readonly string $failedAt,
    ) {}

    /**
     * @return string[]
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Torque: Job Failed on {$this->queue}")
            ->line("A job has permanently failed after exhausting all retries.")
            ->line("**Job:** {$this->jobName}")
            ->line("**Queue:** {$this->queue}")
            ->line("**Error:** {$this->sanitizedMessage()}")
            ->line("**Failed at:** {$this->failedAt}")
            ->line('Check the dead-letter stream in your Torque dashboard for details.');
    }

    private function sanitizedMessage(): string
    {
        return PayloadSanitizer::sanitizeMessage($this->exceptionMessage);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'job_name' => $this->jobName,
            'queue' => $this->queue,
            'exception_message' => $this->exceptionMessage,
            'failed_at' => $this->failedAt,
        ];
    }
}
