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
            $this->processDataFromClient($socket);
            $this->sendDataToClients();
            sleep(1);
        }

        fclose($server);
    }

    public function sendDataToClients()
    {
        if ($this->messageQueue) {
            ['message' => $message, 'connections' => $connects] =
                array_shift($this->messageQueue);

            $this->send(json_encode($message), $connects);
        }
    }

    public function send(string $data, iterable $connects)
    {
        foreach ($connects as $connect) {
            print_r([$connect, $data]);
            fwrite($connect, $data);
        }
    }

    public function processDataFromClient($socket)
    {
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

        // // Game started
        // if ($this->started) {
        //     foreach ($read as $id => $connect) {
        //         // process connections
        //         $data = fread($connect, 100000);
        //         if (!$data) {
        //             // connection closed
        //             fclose($connect);
        //             unset($this->connects[array_search($connect, $this->connects)]);
        //             $this->onClose($connect); // call close scenario
        //             continue;
        //         }
        //         $this->onMessage($this->connects, $data, $id); // call on message scenario
        //     }
        // }
    }

    public function greetNewPlayer(string $username)
    {
        $this->pushMessage([
            'command' => Commands::USERNAME,
            'value' => $username,
        ], [$this->getPlayerConnection($username)]);

        echo "🤓 Connected player {$username}\n";
    }

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
        // echo "close\n";
    }

    public function onMessage($connects, $data, $username)
    {
        $dataDecoded = MessageEncoder::decode($data);

        if ($dataDecoded['type'] == 'ping' && isset($this->connects[$username])) {
            fwrite($this->connects[$username], MessageEncoder::encode('ping', 'pong', true));
        } else if (MessageEncoder::isJson($dataDecoded['payload'])) {
            $data = json_decode($dataDecoded['payload'], true);
            ['command' => $command, 'value' => $value] = $data;
            switch ($command) {
                case Commands::MOVE:
                    $this->move($username, $value);
                    break;
            }
        }
    }

    public function move($username, $value)
    {
        echo "🤓 $username: {$value}\n";

        if ($value !== 1) {
            $data = [
                'command' => Commands::MOVE,
                'value' => $value,
            ];

            $connect = $this->getNextPlayer($username);
            $dataEncoded = MessageEncoder::encode(json_encode($data));
            fwrite($connect, $dataEncoded);
        } else {
            echo "🎉 Winner is {$username}\n";

            $data = [
                'command' => Commands::WIN,
                'value' => $username,
            ];

            $dataEncoded = MessageEncoder::encode(json_encode($data));
            foreach ($this->connects as $connect) {
                fwrite($connect, $dataEncoded);
            }

            $this->endGame();
        }
    }

    public function getNextPlayer($username)
    {
        $nextPlayerIndex = (array_search($username, $this->players) + 1) % count($this->players);
        $nextPlayerUsername = $this->players[$nextPlayerIndex];

        return $this->connects[$nextPlayerUsername];
    }

    public function generateUsername()
    {
        $username = $this->faker->firstName;

        while (isset($this->connects[$username])) {
            $username = $this->faker->firstName;
        }

        return $username;
    }

    public function endGame()
    {
        echo "🏁 End of game";
        exit(0);
    }

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
}
