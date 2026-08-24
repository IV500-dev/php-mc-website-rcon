<?php
class MinecraftRcon {
    private $host;
    private $port;
    private $password;
    private $timeout;
    private $socket;
    private $authorized;

    public function __construct($host, $port, $password, $timeout = 3) {
        $this->host = $host;
        $this->port = $port;
        $this->password = $password;
        $this->timeout = $timeout;
    }

    public function connect() {
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        if (!$this->socket) {
            return false;
        }
        return $this->authorize();
    }

    private function authorize() {
        $this->write(3, $this->password);
        $response = $this->read();
        if ($response['id'] == -1) {
            $this->authorized = false;
            return false;
        }
        $this->authorized = true;
        return true;
    }

    public function sendCommand($command) {
        if (!$this->authorized) return false;
        $this->write(2, $command);
        $response = $this->read();
        return $response['body'];
    }

    private function write($type, $body) {
        $id = rand(1, 1000);
        $packet = pack("VV", $id, $type) . $body . "\x00\x00";
        $packet = pack("V", strlen($packet)) . $packet;
        fwrite($this->socket, $packet, strlen($packet));
    }

    private function read() {
        $size = unpack("V1size", fread($this->socket, 4));
        $packet = fread($this->socket, $size['size']);
        $data = unpack("V1id/V1type", substr($packet, 0, 8));
        $body = substr($packet, 8, -2);
        return ['id' => $data['id'], 'type' => $data['type'], 'body' => $body];
    }

    public function disconnect() {
        if ($this->socket) {
            fclose($this->socket);
        }
    }
}