<?php

declare(strict_types=1);

namespace Webpatser\Torque\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;
use Psr\Http\Message\UriInterface;

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
     * The message type for the route the package's router will pick.
     *
     * A webhook URL goes to SlackWebhookChannel, which up to 3.5 only takes the legacy
     * message and throws a TypeError on the Block Kit one; from 3.6 it takes both. Any
     * other route goes to the Web API SlackChannel, which only takes Block Kit.
     *
     * Soft dependency: both classes only exist with laravel/slack-notification-channel.
     */
    public function toSlack(object $notifiable): mixed
    {
        $route = method_exists($notifiable, 'routeNotificationFor')
            ? $notifiable->routeNotificationFor('slack', $this)
            : null;

        if ($route instanceof UriInterface || (is_string($route) && Str::startsWith($route, ['http://', 'https://']))) {
            $class = 'Illuminate\\Notifications\\Messages\\SlackMessage';

            /** @var object $message */
            $message = new $class; // @phpstan-ignore class.notFound

            return $message->content($this->summary()); // @phpstan-ignore method.notFound
        }

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
