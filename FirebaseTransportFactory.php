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

use Symfony\Component\Notifier\Exception\MissingRequiredOptionException;
use Symfony\Component\Notifier\Exception\UnsupportedSchemeException;
use Symfony\Component\Notifier\Transport\AbstractTransportFactory;
use Symfony\Component\Notifier\Transport\Dsn;

/**
 * @author Jeroen Spee <https://github.com/Jeroeny>
 */
final class FirebaseTransportFactory extends AbstractTransportFactory
{
    public function create(Dsn $dsn): FirebaseTransport
    {
        $scheme = $dsn->getScheme();
        if ('firebase' !== $scheme) {
            throw new UnsupportedSchemeException($dsn, 'firebase', $this->getSupportedSchemes());
        }

        $credentials = [
            'client_email' => sprintf('%s@%s', $dsn->getUser(), $dsn->getHost()),
            ...$dsn->getOptions()
        ];

        // Retrieve the original DSN to get the private key
        $originalDsn = $dsn->getOriginalDsn();
        $credentials['private_key'] = explode("&", explode("private_key=", $originalDsn)[1])[0];

        $requiredParameters = array_diff(
            array_keys($credentials),
            ['type', 'project_id', 'private_key_id', 'private_key', 'client_email', 'client_id']
        );
        if ($requiredParameters) {
            throw new MissingRequiredOptionException(implode(', ', $requiredParameters));
        }

        return (new FirebaseTransport($credentials, $this->client, $this->dispatcher));
    }

    protected function getSupportedSchemes(): array
    {
        return ['firebase'];
    }
}
