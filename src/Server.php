<?php

namespace Amp\Http\Server;

use Amp\CallableMaker;
use Amp\Coroutine;
use Amp\Failure;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Driver\DefaultHttpDriverFactory;
use Amp\Http\Server\Driver\HttpDriverFactory;
use Amp\Http\Server\Driver\RemoteClient;
use Amp\Http\Server\Driver\TimeoutCache;
use Amp\Http\Server\Driver\TimeReference;
use Amp\Loop;
use Amp\Promise;
use Amp\Socket\Server as SocketServer;
use Amp\Success;
use Psr\Log\LoggerInterface as PsrLogger;
use Psr\Log\NullLogger;

class Server {
    use CallableMaker;

    const STOPPED  = 0;
    const STARTING = 1;
    const STARTED  = 2;
    const STOPPING = 3;

    const STATES = [
        self::STOPPED => "STOPPED",
        self::STARTING => "STARTING",
        self::STARTED => "STARTED",
        self::STOPPING => "STOPPING",
    ];

    /** @var int */
    private $state = self::STOPPED;

    /** @var Options */
    private $options;

    /** @var \Amp\Http\Server\Responder */
    private $responder;

    /** @var ErrorHandler */
    private $errorHandler;

    /** @var \Amp\Http\Server\Driver\HttpDriverFactory */
    private $driverFactory;

    /** @var \Psr\Log\LoggerInterface */
    private $logger;

    /** @var \Amp\Http\Server\Driver\TimeReference */
    private $timeReference;

    /** @var \SplObjectStorage */
    private $observers;

    /** @var string[] */
    private $acceptWatcherIds = [];

    /** @var resource[] Server sockets. */
    private $boundServers = [];

    /** @var \Amp\Http\Server\Driver\Client[] */
    private $clients = [];

    /** @var int */
    private $clientCount = 0;

    /** @var int[] */
    private $clientsPerIP = [];

    /** @var \Amp\Http\Server\Driver\TimeoutCache */
    private $timeouts;

    /**
     * @param \Amp\Socket\Server[] $servers
     * @param Responder $responder
     * @param Options|null $options Null creates an Options object with all default options.
     * @param \Psr\Log\LoggerInterface|null $logger Null automatically uses an instance of \Psr\Log\NullLogger.
     *
     * @throws \TypeError If $servers contains anything other than instances of \Amp\Socket\Server.
     */
    public function __construct(
        array $servers,
        Responder $responder,
        Options $options = null,
        PsrLogger $logger = null
    ) {
        foreach ($servers as $server) {
            if (!$server instanceof SocketServer) {
                throw new \TypeError(\sprintf("Only instances of %s should be given", SocketServer::class));
            }

            $this->boundServers[$server->getAddress()] = $server->getResource();
        }

        $this->responder = $responder;

        $this->options = $options ?? new Options;
        $this->logger = $logger ?? new NullLogger;

        $this->timeReference = new TimeReference;

        $this->timeouts = new TimeoutCache(
            $this->timeReference,
            $this->options->getConnectionTimeout()
        );

        $this->timeReference->onTimeUpdate($this->callableFromInstanceMethod("timeoutKeepAlives"));

        $this->observers = new \SplObjectStorage;

        $this->errorHandler = new DefaultErrorHandler;
        $this->driverFactory = new DefaultHttpDriverFactory;
    }

    /**
     * Define a custom HTTP driver factory.
     *
     * @param \Amp\Http\Server\Driver\HttpDriverFactory $driverFactory
     *
     * @throws \Error If the server has started.
     */
    public function setDriverFactory(HttpDriverFactory $driverFactory) {
        if ($this->state) {
            throw new \Error("Cannot set the driver factory after the server has started");
        }

        $this->driverFactory = $driverFactory;
    }

    /**
     * Set the error handler instance to be used for generating error responses.
     *
     * @param ErrorHandler $errorHandler
     *
     * @throws \Error If the server has started.
     */
    public function setErrorHandler(ErrorHandler $errorHandler) {
        if ($this->state) {
            throw new \Error("Cannot set the error handler after the server has started");
        }

        $this->errorHandler = $errorHandler;
    }

    /**
     * Retrieve the current server state.
     *
     * @return int
     */
    public function getState(): int {
        return $this->state;
    }

    /**
     * Retrieve the server options object.
     *
     * @return Options
     */
    public function getOptions(): Options {
        return $this->options;
    }

    /**
     * Retrieve the error handler.
     *
     * @return ErrorHandler
     */
    public function getErrorHandler(): ErrorHandler {
        return $this->errorHandler;
    }

    /**
     * Retrieve the logger.
     *
     * @return PsrLogger
     */
    public function getLogger(): PsrLogger {
        return $this->logger;
    }

    /**
     * @return \Amp\Http\Server\Driver\TimeReference
     */
    public function getTimeReference(): TimeReference {
        return $this->timeReference;
    }

    /**
     * Attach an observer.
     *
     * @param ServerObserver $observer
     *
     * @throws \Error If the server has started.
     */
    public function attach(ServerObserver $observer) {
        if ($this->state) {
            throw new \Error("Cannot attach observers after the server has started");
        }

        $this->observers->attach($observer);
    }

    /**
     * Start the server.
     *
     * @return \Amp\Promise
     */
    public function start(): Promise {
        try {
            if ($this->state === self::STOPPED) {
                return new Coroutine($this->doStart());
            }

            return new Failure(new \Error(
                "Cannot start server: already ".self::STATES[$this->state]
            ));
        } catch (\Throwable $uncaught) {
            return new Failure($uncaught);
        }
    }

    private function doStart(): \Generator {
        \assert($this->logger->debug("Starting") || true);

        $this->observers->attach($this->timeReference);

        if ($this->driverFactory instanceof ServerObserver) {
            $this->observers->attach($this->driverFactory);
        }

        if ($this->responder instanceof ServerObserver) {
            $this->observers->attach($this->responder);
        }

        $this->state = self::STARTING;
        try {
            $promises = [];
            foreach ($this->observers as $observer) {
                $promises[] = $observer->onStart($this, $this->logger, $this->errorHandler);
            }
            yield $promises;
        } catch (\Throwable $exception) {
            yield from $this->doStop();
            throw new \RuntimeException("onStart observer initialization failure", 0, $exception);
        }

        $this->state = self::STARTED;
        \assert($this->logger->debug("Started") || true);

        $protocols = $this->driverFactory->getApplicationLayerProtocols();

        $onAcceptable = $this->callableFromInstanceMethod("onAcceptable");
        foreach ($this->boundServers as $serverName => $server) {
            $context = \stream_context_get_options($server);

            if (isset($context["ssl"])) {
                if (self::hasAlpnSupport()) {
                    \stream_context_set_option($server, "ssl", "alpn_protocols", \implode(", ", $protocols));
                } elseif ($protocols) {
                    $this->logger->alert("ALPN not supported with the installed version of OpenSSL");
                }
            }

            $this->acceptWatcherIds[$serverName] = Loop::onReadable($server, $onAcceptable);
            $this->logger->info("Listening on {$serverName}");
        }
    }

    private function onAcceptable(string $watcherId, $server) {
        if (!$socket = @\stream_socket_accept($server, 0)) {
            return;
        }

        $client = new RemoteClient(
            $socket,
            $this->responder,
            $this->errorHandler,
            $this->logger,
            $this->options,
            $this->timeouts
        );

        \assert($this->logger->debug("Accept {$client->getRemoteAddress()}:{$client->getRemotePort()} on " .
                stream_socket_get_name($socket, false) . " #" . (int) $socket) || true);

        $net = $client->getNetworkId();

        if (!isset($this->clientsPerIP[$net])) {
            $this->clientsPerIP[$net] = 0;
        }

        $client->onClose(function (Client $client) {
            unset($this->clients[$client->getId()]);

            $net = $client->getNetworkId();
            if (--$this->clientsPerIP[$net] === 0) {
                unset($this->clientsPerIP[$net]);
            }

            --$this->clientCount;
        });

        if ($this->clientCount++ === $this->options->getMaxConnections()) {
            \assert($this->logger->debug("Client denied: too many existing connections") || true);
            $client->close();
            return;
        }

        $ip = $client->getRemoteAddress();
        $clientCount = $this->clientsPerIP[$net]++;

        // Connections on localhost are excluded from the connections per IP setting.
        // Checks IPv4 loopback (127.x), IPv6 loopback (::1) and IPv4-to-IPv6 mapped loopback.
        // Also excludes all connections that are via unix sockets.
        if ($clientCount === $this->options->getMaxConnectionsPerIp()
            && $ip !== "::1" && \strncmp($ip, "127.", 4) !== 0 && !$client->isUnix()
            && \strncmp(\inet_pton($ip), '\0\0\0\0\0\0\0\0\0\0\xff\xff\7f', 31)
        ) {
            \assert(function () use ($ip) {
                $addr = $ip;
                $packedIp = @\inet_pton($ip);

                if (isset($packedIp[4])) {
                    $addr .= "/56";
                }

                $this->logger->debug("Client denied: too many existing connections from {$addr}");

                return true;
            });

            $client->close();
            return;
        }

        $this->clients[$client->getId()] = $client;

        $client->start($this->driverFactory);
    }

    /**
     * Stop the server.
     *
     * @return Promise
     */
    public function stop(): Promise {
        switch ($this->state) {
            case self::STARTED:
                $stopPromise = new Coroutine($this->doStop());
                return Promise\timeout($stopPromise, $this->options->getShutdownTimeout());
            case self::STOPPED:
                return new Success;
            default:
                return new Failure(new \Error(
                    "Cannot stop server: currently ".self::STATES[$this->state]
                ));
        }
    }

    private function doStop(): \Generator {
        \assert($this->logger->debug("Stopping") || true);
        $this->state = self::STOPPING;

        foreach ($this->acceptWatcherIds as $watcherId) {
            Loop::cancel($watcherId);
        }
        $this->boundServers = [];
        $this->acceptWatcherIds = [];

        try {
            $promises = [];
            foreach ($this->observers as $observer) {
                $promises[] = $observer->onStop($this);
            }
            yield $promises;
        } catch (\Throwable $exception) {
            // Exception will be rethrown below once all clients are disconnected.
        }

        foreach ($this->clients as $client) {
            $client->close();
        }

        \assert($this->logger->debug("Stopped") || true);
        $this->state = self::STOPPED;

        if (isset($exception)) {
            throw new \RuntimeException("onStop observer failure", 0, $exception);
        }
    }

    private function timeoutKeepAlives(int $now) {
        foreach ($this->timeouts as $id => $expiresAt) {
            if ($now < $expiresAt) {
                break;
            }

            $client = $this->clients[$id];

            // Client is either idle or taking too long to send request, so simply close the connection.
            $client->close();
        }
    }

    public function __debugInfo() {
        return [
            "state" => $this->state,
            "timeReference" => $this->timeReference,
            "observers" => $this->observers,
            "acceptWatcherIds" => $this->acceptWatcherIds,
            "boundServers" => $this->boundServers,
            "clients" => $this->clients,
            "connectionTimeouts" => $this->timeouts,
        ];
    }

    /**
     * @see https://wiki.openssl.org/index.php/Manual:OPENSSL_VERSION_NUMBER(3)
     * @return bool
     */
    private static function hasAlpnSupport(): bool {
        if (!\defined("OPENSSL_VERSION_NUMBER")) {
            return false;
        }

        return \OPENSSL_VERSION_NUMBER >= 0x10002000;
    }
}
