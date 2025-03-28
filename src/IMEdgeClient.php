<?php

namespace IMEdge\Web\Rpc;

use gipfl\Protocol\JsonRpc\JsonRpcConnection;
use gipfl\Protocol\JsonRpc\Notification;
use gipfl\Protocol\JsonRpc\Request;
use gipfl\Protocol\NetString\StreamWrapper;
use Icinga\Module\Imedge\Config\Defaults;
use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\UnixConnector;

use function React\Promise\resolve;

class IMEdgeClient
{
    protected string $socket;
    protected ?JsonRpcConnection $connection = null;
    /**
     * @var PromiseInterface<JsonRpcConnection>|null
     */
    protected ?PromiseInterface $pendingConnection = null;
    protected ?string $target = null;

    // Hint: Defaults depends on Icinga\Module\Imedge, we should introduce Imedge\Web\Config
    final public function __construct(string $socket = Defaults::IMEDGE_SOCKET)
    {
        $this->socket = $socket;
    }

    public function withTarget(string $target): self
    {
        $clone = new static($this->socket);
        $clone->target = $target;

        return $clone;
    }

    public function getTarget(): ?string
    {
        return $this->target;
    }

    public function getSocket(): string
    {
        return $this->socket;
    }

    public function socketIsWritable(): bool
    {
        return file_exists($this->socket) && is_writable($this->socket);
    }

    /**
     * @param array<mixed>|\stdClass|null $params
     * @return PromiseInterface<mixed>
     */
    public function request(string $method, $params = null): PromiseInterface
    {
        return $this->connection()->then(function (JsonRpcConnection $connection) use ($method, $params) {
            $packet = new Request($method, null, $params);
            if ($this->target) {
                $packet->setExtraProperties((object) ['target' => $this->target]);
            }

            return $connection->sendRequest($packet);
        });
    }

    /**
     * @param array<mixed>|\stdClass|null $params
     * @return PromiseInterface<null>
     */
    public function notify(string $method, $params = null): PromiseInterface
    {
        return $this->connection()->then(function (JsonRpcConnection $connection) use ($method, $params) {
            $packet = new Notification($method, $params);
            if ($this->target) {
                $packet->setExtraProperties((object) ['target' => $this->target]);
            }
            $connection->sendNotification($packet);
        });
    }

    /**
     * @return PromiseInterface<JsonRpcConnection>
     */
    protected function connection(): PromiseInterface
    {
        if ($this->connection === null) {
            if ($this->pendingConnection === null) {
                return $this->connect();
            }

            return $this->pendingConnection;
        } else {
            return resolve($this->connection);
        }
    }

    /**
     * @return PromiseInterface<JsonRpcConnection>
     */
    protected function connect(): PromiseInterface
    {
        $connector = new UnixConnector(Loop::get());
        $connected = function (ConnectionInterface $connection) {
            $jsonRpc = new JsonRpcConnection(new StreamWrapper($connection));
            $this->connection = $jsonRpc;
            $this->pendingConnection = null;
            $connection->on('close', function () {
                $this->connection = null;
            });

            return $jsonRpc;
        };

        return $this->pendingConnection = $connector
            ->connect('unix://' . $this->socket)
            ->then($connected);
    }
}
