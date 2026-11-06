<?php

declare(strict_types=1);

namespace Webpatser\Torque\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a queue's oldest waiting job passes its `waits` threshold.
 *
 * Delivered to the routes set with Torque::routeMailNotificationsTo() and
 * Torque::routeSlackNotificationsTo(). Slack needs the optional
 * laravel/slack-notification-channel package.
 */
final class LongWaitNotification extends Notification
{
    /**
     * @param  list<string>  $channels
     */
    public function __construct(
        public readonly string $queue,
        public readonly int $seconds,
        public readonly int $threshold,
        public readonly array $channels = ['mail'],
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Torque: long wait on {$this->queue}")
            ->line($this->summary());
    }

    /**
     * The Block Kit message, accepted by both the webhook channel and the Web API
     * channel from laravel/slack-notification-channel 3.6 (enforced by a composer conflict).
     *
     * Soft dependency: the class only exists with laravel/slack-notification-channel.
     */
    public function toSlack(object $notifiable): mixed
    {
        $class = 'Illuminate\\Notifications\\Slack\\SlackMessage';

        /** @var object $message */
        $message = new $class; // @phpstan-ignore class.notFound

        return $message->text($this->summary()); // @phpstan-ignore method.notFound
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(object $notifiable): array
    {
        return ['queue' => $this->queue, 'seconds' => $this->seconds, 'threshold' => $this->threshold];
    }

    private function summary(): string
    {
        return "The oldest waiting job on queue {$this->queue} has waited {$this->seconds}s (threshold {$this->threshold}s).";
    }
}
