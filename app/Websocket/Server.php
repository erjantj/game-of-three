<?php

namespace App\Websocket;

use App\Game\Commands;

class Server
{
    public $host;
    public $port;
    public $playersCount;
    private $config;
    private $connects;
    private $players;
    private $faker;
    private $started = false;
    private $messageQueue = [];

    public function __construct($config)
    {
        $this->config = $config;
        $this->connects = [];
        $this->host = $this->config['websocket']['host'];
        $this->port = $this->config['websocket']['port'];
        $this->playersCount = $this->config['game']['players_count'];
        $this->faker = \Faker\Factory::create();
        $this->connects = [];
        $this->players = [];
    }

    /**
     * Socket entrypoint
     */
    public function start()
    {
        $socket = stream_socket_server("tcp://{$this->host}:{$this->port}", $errno, $errstr);

        if (!$socket) {
            die("$errstr ($errno)\n");
        }

        echo "Server started at tcp://{$this->host}:{$this->port} \n";
        echo "Waiting for players...\n";

        while (true) {
            // Handle messages to client
            $this->sendDataToClients();

            // Wait for active connections
            $activeConnections = $this->selectActiveConnections($socket);
            if (!$activeConnections) {
                break;
            }

            if (in_array($socket, $activeConnections)) {
                // Handle new clients
                $this->handleClient($socket);
                unset($activeConnections[array_search($socket, $activeConnections)]);
            }

            // Process client requests
            $this->processClients($activeConnections);

            sleep(1);
        }

        fclose($server);
    }

    /**
     * Wait and get active connections
     * @param  resource $socket server socket
     * @return iterable
     */
    public function selectActiveConnections($socket)
    {
        $read = $this->connects;
        $read[] = $socket;
        $write = $except = null;

        if (!stream_select($read, $write, $except, null)) {
            return null;
        }

        return $read;
    }

    /**
     * Send messages to clients
     */
    public function sendDataToClients()
    {
        while ($this->messageQueue) {
            ['message' => $message, 'connections' => $connects] =
                array_shift($this->messageQueue);

            // if not connections specified send to all
            if (!$connects) {
                $connects = $this->connects;
            }

            $dataEncoded = MessageEncoder::encode(json_encode($message));
            foreach ($connects as $connect) {
                fwrite($connect, $dataEncoded);
            }
        }
    }

    /**
     * Handle new client
     * @param  resource $socket server socket
     */
    public function handleClient($socket)
    {
        if (!$this->started) {
            // if new connection, accept connection and make handshake
            if (
                ($connect = stream_socket_accept($socket, -1)) &&
                $info = $this->handshake($connect)
            ) {
                $username = $this->generateUsername();
                // add to array to process
                $this->connects[$username] = $connect;
                $this->players[] = $username;

                $this->greetNewPlayer($username);
                $this->checkGameStarted();
            }
        }
    }

    /**
     * Process client requests
     * @param  iterable $connections client connections
     */
    public function processClients($connections)
    {
        // Game started
        if ($this->started) {
            foreach ($connections as $username => $connect) {
                // process connections
                $data = fread($connect, 100000);
                if (!$data) {
                    $this->onClose($connect); // call close scenario
                    continue;
                }

                $this->onMessage($data, $username); // call on message scenario
            }
        }
    }

    /**
     * Greet new player and send username to player
     * @param  string $username player username
     */
    public function greetNewPlayer(string $username)
    {
        $this->pushMessage([
            'command' => Commands::USERNAME,
            'value' => $username,
        ], [$this->getPlayerConnection($username)]);

        echo "🤓 Connected player {$username}\n";
    }

    /**
     * Check if game started, if started ask first client for random number
     * @return [type] [description]
     */
    public function checkGameStarted()
    {
        if (count($this->players) >= $this->playersCount) {
            $this->started = true;
            $username = $this->players[0];
            $this->pushMessage([
                'command' => Commands::GENERATE,
                'value' => null,
            ], [$this->getPlayerConnection($username)]);

            echo "🤔 Player {$username} generating random number...\n";
        }
    }

    /**
     * Handshake
     * @param  socket $connect socket
     * @return array           socket info
     */
    public function handshake($connect)
    {
        $info = [];

        $line = fgets($connect);
        $header = explode(' ', $line);
        $info['method'] = $header[0];
        $info['uri'] = $header[1];

        // read connection headers
        while ($line = rtrim(fgets($connect))) {
            if (preg_match('/\A(\S+): (.*)\z/', $line, $matches)) {
                $info[$matches[1]] = $matches[2];
            } else {
                break;
            }
        }

        $address = explode(':', stream_socket_get_name($connect, true)); // get client address
        $info['ip'] = $address[0];
        $info['port'] = $address[1];

        if (empty($info['Sec-WebSocket-Key'])) {
            return false;
        }

        // send websocket headers
        $SecWebSocketAccept = base64_encode(pack('H*', sha1($info['Sec-WebSocket-Key'] . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        $upgrade = "HTTP/1.1 101 Web Socket Protocol Handshake\r\n" .
            "Upgrade: websocket\r\n" .
            "Connection: Upgrade\r\n" .
            "Sec-WebSocket-Accept:$SecWebSocketAccept\r\n" .
            "Sec-WebSocket-Protocol: json\r\n\r\n";

        fwrite($connect, $upgrade);

        return $info;
    }

    public function onOpen($connect, $info)
    {
        // echo "open\n";
        // fwrite($connect, MessageEncoder::encode('Привет'));
    }

    public function onClose($connect)
    {
        // connection closed
        fclose($connect);
        unset($this->connects[array_search($connect, $this->connects)]);
    }

    /**
     * Handle client messages
     * @param  string $data     client data
     * @param  string $username client username
     */
    public function onMessage(string $data, string $username)
    {
        $dataDecoded = MessageEncoder::decode($data);
        if (MessageEncoder::isJson($dataDecoded['payload'])) {
            $data = json_decode($dataDecoded['payload'], true);
            ['command' => $command, 'value' => $value] = $data;

            switch ($command) {
                case Commands::MOVE:
                    $this->move($username, $value);
                    break;
            }
        }
    }

    /**
     * Handle client move, if client move is equal to 1, then end game
     * @param  string $username client username
     * @param  string $value    client move value
     */
    public function move($username, $value)
    {
        echo "🤓 $username: {$value}\n";

        if ($value !== 1) {
            $this->pushMessage([
                'command' => Commands::MOVE,
                'value' => $value,
            ], [$this->getNextPlayer($username)]);
        } else {
            echo "🎉 Winner is {$username}\n";

            $this->pushMessage([
                'command' => Commands::WIN,
                'value' => $username,
            ]);

            $this->endGame();
        }
    }

    /**
     * Next next player connection
     * @param  string $username client username
     * @return resource
     */
    public function getNextPlayer(string $username)
    {
        $nextPlayerIndex = (array_search($username, $this->players) + 1) % count($this->players);
        $nextPlayerUsername = $this->players[$nextPlayerIndex];

        return $this->connects[$nextPlayerUsername];
    }

    /**
     * Generate random username for client
     * @return string
     */
    public function generateUsername(): string
    {
        $username = $this->faker->firstName;

        while (isset($this->connects[$username])) {
            $username = $this->faker->firstName;
        }

        return $username;
    }

    /**
     * End game logic
     */
    public function endGame()
    {
        // Handle messages to client
        $this->sendDataToClients();

        // Close all connections
        foreach ($this->connects as $connect) {
            $this->onClose($connect);
        }

        echo "🏁 End of game";
        exit(0);
    }

    /**
     * Get player connection by username
     * @param  string $username client username
     * @return resource
     */
    public function getPlayerConnection(string $username)
    {
        return $this->connects[$username];
    }

    /**
     * Push message to queue
     * @param  iterable $message message
     */
    public function pushMessage(iterable $message, iterable $connections = null)
    {
        $this->messageQueue[] = ['message' => $message, 'connections' => $connections];
    }
};
