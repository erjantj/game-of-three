<?php

namespace App\Websocket;

use App\Game\Commands;
use App\Game\Player;

class Client
{
    public $host;
    public $port;
    public $player;
    private $connected = false;
    private $socket;
    private $config;
    private $messageQueue = [];

    public function __construct($config)
    {
        $this->config = $config;
        $this->host = $this->config['host'];
        $this->port = $this->config['port'];
        $this->player = new Player();
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /**
     * Client entrypoint
     */
    public function start()
    {
        // Connect to server
        if (!$this->socket) {
            $connected = $this->connect($this->host, $this->port, "/ws/");
            if ($connected) {
                echo "Connected to server at tcp://{$this->host}:{$this->port}\n";
                echo "Waiting for game to start...\n";
            } else {
                echo "Problem connecting to server at tcp://{$this->host}:{$this->port}\n";
                echo "Check server is running\n";
                $this->end();
            }
        }
        // Event loop
        while (true) {
            // Wait for data
            $data = @fread($this->socket, 10000);

            if ($data) {
                $this->processDataFromServer($data);
                $this->sendDataToServer();
            }

            sleep(1);
        }
    }

    /**
     * Send data to server
     * @param  string   $data   data to send
     * @param  string   $type   data type
     * @param  boolean  $masked is masked
     * @return is data sent
     */
    public function send(string $data, string $type = 'text', bool $masked = true): bool
    {
        if ($this->connected === false) {
            return false;
        }

        if (empty($data)) {
            return false;
        }

        $result = @fwrite($this->socket, MessageEncoder::encode($data, $type, $masked));
        if ($result === 0 || $result === false) {
            return false;
        }
        return true;
    }

    /**
     * Connect to server
     * @param  string  $host   server host
     * @param  string  $port   server port
     * @param  string  $path   server path
     * @param  boolean $origin server origin
     * @return is connected
     */
    public function connect(string $host, string $port, string $path, bool $origin = false)
    {
        $this->host = $host;
        $this->port = $port;
        $this->path = $path;
        $this->origin = $origin;

        $key = base64_encode($this->_generateRandomString(16, false, true));
        $header = "GET " . $path . " HTTP/1.1\r\n";
        $header .= "Host: " . $host . ":" . $port . "\r\n";
        $header .= "Upgrade: websocket\r\n";
        $header .= "Connection: Upgrade\r\n";
        $header .= "Sec-WebSocket-Key: " . $key . "\r\n";
        if ($origin !== false) {
            $header .= "Sec-WebSocket-Origin: " . $origin . "\r\n";
        }
        $header .= "Sec-WebSocket-Version: 13\r\n";
        $header .= "Sec-WebSocket-Protocol: json\r\n\r\n";

        $this->socket = @fsockopen($host, $port, $errno, $errstr, 2);
        if (!$this->socket) {
            return $this->connected;
        }

        socket_set_timeout($this->socket, 0, 10000);
        @fwrite($this->socket, $header);
        $response = @fread($this->socket, 1500);

        preg_match('#(Sec-WebSocket-Accept):(.*)#', $response, $matches);
        $expectedResonse = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));
        if (!isset($matches[2])) {
            return $this->connected;
        }

        $keyAccept = trim($matches[2]);
        $expectedResonse = base64_encode(pack('H*', sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11')));

        $this->connected = ($keyAccept === $expectedResonse) ? true : false;
        return $this->connected;
    }

    public function checkConnection()
    {
        $this->connected = false;

        // send ping:
        @fwrite($this->socket, MessageEncoder::encode('ping?', 'ping', true));
        $response = @fread($this->socket, 300);

        if (empty($response)) {
            return false;
        }
        $response = MessageEncoder::decode($response);
        if (!is_array($response)) {
            return false;
        }
        if (!isset($response['type']) || $response['type'] !== 'pong') {
            return false;
        }
        $this->connected = true;
        return true;
    }

    /**
     * Disconnect
     */
    public function disconnect()
    {
        if ($this->socket) {
            $this->connected = false;
            @fclose($this->socket);
        }
    }

    /**
     * Reconect to server
     */
    public function reconnect()
    {
        sleep(10);
        $this->connected = false;
        @fclose($this->socket);
        $this->connect($this->host, $this->port, $this->path, $this->origin);
    }

    /**
     * Generate random string
     * @param  integer $length     string length
     * @param  boolean $addSpaces  add spaces
     * @param  boolean $addNumbers add numbers
     * @return random string
     */
    private function _generateRandomString(int $length = 10, bool $addSpaces = true, bool $addNumbers = true)
    {
        $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"§$%&/()=[]{}';
        $useChars = [];
        // select some random chars:
        for ($i = 0; $i < $length; $i++) {
            $useChars[] = $characters[mt_rand(0, strlen($characters) - 1)];
        }
        // add spaces and numbers:
        if ($addSpaces === true) {
            array_push($useChars, ' ', ' ', ' ', ' ', ' ', ' ');
        }
        if ($addNumbers === true) {
            array_push($useChars, rand(0, 9), rand(0, 9), rand(0, 9));
        }
        shuffle($useChars);
        $randomString = trim(implode('', $useChars));
        $randomString = substr($randomString, 0, $length);
        return $randomString;
    }

    /**
     * Handle server data
     * @param  string $data data from server
     */
    public function processDataFromServer(string $data)
    {
        $data = $this->parseData($data);
        if ($data) {
            ['command' => $command, 'value' => $value] = $data;

            switch ($command) {
                case Commands::USERNAME:
                    $this->player->setUsername($value);
                    break;

                case Commands::GENERATE:
                    $randomNumber = $this->player->generateRandomNumber();
                    $this->pushMessage([
                        'command' => Commands::MOVE,
                        'value' => $randomNumber,
                    ]);
                    break;

                case Commands::MOVE:
                    $nextNumber = $this->player->move($value);
                    $this->pushMessage([
                        'command' => Commands::MOVE,
                        'value' => $nextNumber,
                    ]);
                    break;

                case Commands::WIN:
                    if ($this->player->username == $value) {
                        $this->player->win();
                    } else {
                        $this->player->loose();
                    }
                    $this->end();
                    break;
            }
        }
    }

    /**
     * Push message to queue
     * @param  iterable $message message
     */
    public function pushMessage(iterable $message)
    {
        $this->messageQueue[] = $message;
    }

    /**
     * Send player data to server
     */
    public function sendDataToServer()
    {
        if ($this->messageQueue) {
            $message = array_shift($this->messageQueue);
            $this->send(json_encode($message));
        }
    }

    /**
     * Parse data
     * @param  string $data data
     * @return parsed data
     */
    public function parseData(string $data):  ? iterable
    {
        $dataDecoded = MessageEncoder::decode($data);
        if (MessageEncoder::isJson($dataDecoded['payload'])) {
            return json_decode($dataDecoded['payload'], true);
        }

        return null;
    }

    /**
     * End game logic
     */
    public function end()
    {
        echo "🏁 End of game\n";
        $this->disconnect();
        exit(0);
    }
}
