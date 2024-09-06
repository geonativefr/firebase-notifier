<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Notifier\Bridge\Firebase;

use Symfony\Component\Notifier\Message\MessageOptionsInterface;

/**
 * @author Jeroen Spee <https://github.com/Jeroeny>
 *
 * @see https://firebase.google.com/docs/cloud-messaging/xmpp-server-ref.html
 */
abstract class FirebaseOptions implements MessageOptionsInterface
{
    private string $tokenOrTopic;

    /**
     * @see https://firebase.google.com/docs/cloud-messaging/xmpp-server-ref.html#notification-payload-support
     */
    protected $options;

    private bool $useTopic;

    public function __construct(string $tokenOrTopic, array $options, bool $useTopic = false)
    {
        $this->tokenOrTopic = $tokenOrTopic;
        $this->options = $options;
        $this->useTopic = $useTopic;
    }

    public function toArray(): array
    {
        return [
            ($this->useTopic ? 'topic' : 'token') => $this->tokenOrTopic,
            ...$this->options
        ];
    }

    public function getRecipientId(): ?string
    {
        return $this->tokenOrTopic;
    }

    /**
     * @return $this
     */
    public function title(string $title): static
    {
        $this->options['title'] = $title;

        return $this;
    }

    /**
     * @return $this
     */
    public function body(string $body): static
    {
        $this->options['body'] = $body;

        return $this;
    }
}
