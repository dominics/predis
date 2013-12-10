<?php

/*
 * This file is part of the Predis package.
 *
 * (c) Daniele Alessandri <suppakilla@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Predis\Connection;

use Predis\NotSupportedException;
use Predis\Command\CommandInterface;
use Predis\Response;

/**
 * This class provides the implementation of a Predis connection that uses PHP's
 * streams for network communication and wraps the phpiredis C extension (PHP
 * bindings for hiredis) to parse and serialize the Redis protocol.
 *
 * This class is intended to provide an optional low-overhead alternative for
 * processing responses from Redis compared to the standard pure-PHP classes.
 * Differences in speed when dealing with short inline responses are practically
 * nonexistent, the actual speed boost is for big multibulk responses when this
 * protocol processor can parse and return responses very fast.
 *
 * For instructions on how to build and install the phpiredis extension, please
 * consult the repository of the project.
 *
 * The connection parameters supported by this class are:
 *
 *  - scheme: it can be either 'tcp' or 'unix'.
 *  - host: hostname or IP address of the server.
 *  - port: TCP port of the server.
 *  - timeout: timeout to perform the connection.
 *  - read_write_timeout: timeout of read / write operations.
 *  - async_connect: performs the connection asynchronously.
 *  - tcp_nodelay: enables or disables Nagle's algorithm for coalescing.
 *  - persistent: the connection is left intact after a GC collection.
 *
 * @link https://github.com/nrk/phpiredis
 * @author Daniele Alessandri <suppakilla@gmail.com>
 */
class PhpiredisStreamConnection extends StreamConnection
{
    private $reader;

    /**
     * {@inheritdoc}
     */
    public function __construct(ConnectionParametersInterface $parameters)
    {
        $this->assertExtensions();

        parent::__construct($parameters);

        $this->reader = $this->createReader();
    }

    /**
     * {@inheritdoc}
     */
    public function __destruct()
    {
        phpiredis_reader_destroy($this->reader);

        parent::__destruct();
    }

    /**
     * Checks if the phpiredis extension is loaded in PHP.
     */
    private function assertExtensions()
    {
        if (!extension_loaded('phpiredis')) {
            throw new NotSupportedException(
                'The "phpiredis" extension is required by this connection backend'
            );
        }
    }

    /**
     * Creates a new instance of the protocol reader resource.
     *
     * @return resource
     */
    private function createReader()
    {
        $reader = phpiredis_reader_create();

        phpiredis_reader_set_status_handler($reader, $this->getStatusHandler());
        phpiredis_reader_set_error_handler($reader, $this->getErrorHandler());

        return $reader;
    }

    /**
     * Returns the underlying protocol reader resource.
     *
     * @return resource
     */
    protected function getReader()
    {
        return $this->reader;
    }

    /**
     * Returns the handler used by the protocol reader for inline responses.
     *
     * @return \Closure
     */
    protected function getStatusHandler()
    {
        return function ($payload) {
            return Response\Status::get($payload);
        };
    }

    /**
     * Returns the handler used by the protocol reader for error responses.
     *
     * @return \Closure
     */
    protected function getErrorHandler()
    {
        return function ($errorMessage) {
            return new Response\Error($errorMessage);
        };
    }

    /**
     * {@inheritdoc}
     */
    public function read()
    {
        $socket = $this->getResource();
        $reader = $this->reader;

        while (PHPIREDIS_READER_STATE_INCOMPLETE === $state = phpiredis_reader_get_state($reader)) {
            $buffer = fread($socket, 4096);

            if ($buffer === false || $buffer === '') {
                $this->onConnectionError('Error while reading bytes from the server');

                return;
            }

            phpiredis_reader_feed($reader, $buffer);
        }

        if ($state === PHPIREDIS_READER_STATE_COMPLETE) {
            return phpiredis_reader_get_reply($reader);
        } else {
            $this->onProtocolError(phpiredis_reader_get_error($reader));
        }
    }

    /**
     * {@inheritdoc}
     */
    public function writeRequest(CommandInterface $command)
    {
        $arguments = $command->getArguments();
        array_unshift($arguments, $command->getId());

        $this->writeBytes(phpiredis_format_command($arguments));
    }

    /**
     * {@inheritdoc}
     */
    public function __wakeup()
    {
        $this->assertExtensions();
        $this->reader = $this->createReader();
    }
}
