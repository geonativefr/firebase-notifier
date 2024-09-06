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

use Google\Client;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\Cache\CacheItem;
use Symfony\Component\Notifier\Exception\InvalidArgumentException;
use Symfony\Component\Notifier\Exception\TransportException;
use Symfony\Component\Notifier\Exception\UnsupportedMessageTypeException;
use Symfony\Component\Notifier\Message\ChatMessage;
use Symfony\Component\Notifier\Message\MessageInterface;
use Symfony\Component\Notifier\Message\SentMessage;
use Symfony\Component\Notifier\Transport\AbstractTransport;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\Cache\Adapter\AdapterInterface;
use DateTimeImmutable;

/**
 * @author Jeroen Spee <https://github.com/Jeroeny>
 * @author Cesur APAYDIN <https://github.com/cesurapp>
 */
final class FirebaseTransport extends AbstractTransport
{
    protected const HOST = 'fcm.googleapis.com/v1/projects/project_id/messages:send';

    private array $credentials;

    public function __construct(
        #[\SensitiveParameter] array $credentials,
        HttpClientInterface $client = null,
        EventDispatcherInterface $dispatcher = null,
        private AdapterInterface $cache = new FilesystemAdapter(),
    )
    {
        $this->credentials = $credentials;
        $this->client = $client;
        $this->setHost(str_replace('project_id', $this->credentials['project_id'], $this->getDefaultHost()));

        parent::__construct($client, $dispatcher);
    }

    public function __toString(): string
    {
        return sprintf('firebase://%s', $this->getEndpoint());
    }

    public function supports(MessageInterface $message): bool
    {
        return $message instanceof ChatMessage && (null === $message->getOptions() || $message->getOptions() instanceof FirebaseOptions);
    }

    protected function doSend(MessageInterface $message): SentMessage
    {
        if (!$message instanceof ChatMessage) {
            throw new UnsupportedMessageTypeException(__CLASS__, ChatMessage::class, $message);
        }

        $endpoint = sprintf('https://%s', $this->getEndpoint());

        // Generate Options
        $options = $message->getOptions()?->toArray() ?? [];
        if (!isset($options['token']) && !isset($options['topic'])) {
            throw new InvalidArgumentException(sprintf('The "%s" transport required the "token" or "topic" option to be set.', __CLASS__));
        }
        $options['notification']['body'] = $message->getSubject();

        // Remove wrong options
        unset($options['data']);

        // Send
        $response = $this->client->request('POST', $endpoint, [
            'headers' => ['Authorization' => 'Bearer ' . $this->getAccessToken()],
            'json' => array_filter(['message' => $options]),
        ]);
        dump($endpoint, $options);
        dump($response->getStatusCode());

        try {
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            throw new TransportException('Could not reach the remote Firebase server.', $response, 0, $e);
        }

        $contentType = $response->getHeaders(false)['content-type'][0] ?? '';
        $jsonContents = str_starts_with($contentType, 'application/json') ? $response->toArray(false) : null;
        $errorMessage = null;

        if ($jsonContents && isset($jsonContents['results'][0]['error'])) {
            $errorMessage = $jsonContents['results'][0]['error'];
        } elseif (200 !== $statusCode) {
            $errorMessage = $response->getContent(false);
        }

        if (null !== $errorMessage) {
            throw new TransportException('Unable to post the Firebase message: ' . $errorMessage, $response);
        }

        $success = $response->toArray(false);

        $sentMessage = new SentMessage($message, (string)$this);
        $sentMessage->setMessageId($success['results'][0]['message_id'] ?? '');

        return $sentMessage;
    }

    private function getAccessToken(): string
    {
        // Check if token is in cache.
        $accessTokenItem = $this->cache->getItem('firebase_access_token');
        if (null !== $accessTokenItem->get()) {
            return $accessTokenItem->get();
        }

        // Get token from Google.
        $client = new Client();
        $client->setAuthConfig($this->credentials);
        $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
        $client->useApplicationDefaultCredentials();
        $reponse = $client->fetchAccessTokenWithAssertion();

        // Set token in cache.
        $accessTokenItem->set($reponse['access_token']);
        $accessTokenItem->expiresAt(new DateTimeImmutable('now + '.($reponse['expires_in'] - 60).' seconds'));
        $this->cache->save($accessTokenItem);

        return $reponse['access_token'];
    }

    protected function urlSafeEncode(string|array $data): string
    {
        if (is_array($data)) {
            $data = json_encode($data, JSON_UNESCAPED_SLASHES);
        }

        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    protected function encodePk(string $privateKey): string
    {
        $text = explode('-----', $privateKey);
        $text[2] = str_replace(['\n', '_', ' '], ["\n", "\n", '+'], $text[2]);

        return implode('-----', $text);
    }
}