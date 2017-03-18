<?php
namespace PhpSlackBot;

class Bot {

    private $params = array();
    private $context = array();
    private $wsUrl;
    private $commands = array();
    private $webhooks = array();
    private $webserverPort = null;
    private $webserverAuthentificationToken = null;
    private $catchAllCommands = array();

    public function setToken($token) {
        $this->params = array('token' => $token);
    }

    public function loadCommand($command) {
        if ($command instanceof Command\BaseCommand) {
            $this->commands[$command->getName()] = $command;
        }
        else {
            throw new \Exception('Command must implement PhpSlackBot\Command\BaseCommand');
        }
    }

    public function loadWebhook($webhook) {
        if ($webhook instanceof Webhook\BaseWebhook) {
            $this->webhooks[$webhook->getName()] = $webhook;
        }
        else {
            throw new \Exception('Webhook must implement PhpSlackBot\Webhook\BaseWebhook');
        }
    }

    public function loadCatchAllCommand($command) {
        if ($command instanceof Command\BaseCommand) {
            $this->catchAllCommands[] = $command;
        }
        else {
            throw new \Exception('Command must implement PhpSlackBot\Command\BaseCommand');
        }
    }

    public function enableWebserver($port, $authentificationToken = null) {
        $this->webserverPort = $port;
        $this->authentificationToken = $authentificationToken;
    }

    public function run() {
        if (!isset($this->params['token'])) {
            throw new \Exception('A token must be set. Please see https://my.slack.com/services/new/bot');
        }
        $this->init();
        $logger = new \Zend\Log\Logger();
        $writer = new \Zend\Log\Writer\Stream("php://output");
        $logger->addWriter($writer);

        $loop = \React\EventLoop\Factory::create();
        $client = new \Devristo\Phpws\Client\WebSocket($this->wsUrl, $loop, $logger);

        foreach ($this->commands as $command) {
            $command->setClient($client);
            $command->setContext($this->context);
        }
        foreach ($this->catchAllCommands as $command) {
            $command->setClient($client);
            $command->setContext($this->context);
        }
        foreach ($this->webhooks as $hook) {
            $hook->setClient($client);
            $hook->setContext($this->context);
        }

        $client->on("request", function($headers) use ($logger){
                $logger->notice("Request object created!");
        });

        $client->on("handshake", function() use ($logger) {
                $logger->notice("Handshake received!");
        });

        $client->on("connect", function() use ($logger, $client){
                $logger->notice("Connected!");
        });

        $client->on("message", function($message) use ($client, $logger){
            $data = $message->getData();
            $logger->notice("Got message: ".$data);
            $data = json_decode($data, true);

            if (count($this->catchAllCommands)) {
              foreach ($this->catchAllCommands as $command) {
                $command->executeCommand($data);
              }
            }
            $command = $this->getCommand($data);
            if ($command instanceof Command\BaseCommand) {
                $command->setChannel($data['channel']);
                $command->setUser($data['user']);
                $command->executeCommand($data);
            }
        });


        $client->open();

        /* Webserver */
        if (null !== $this->webserverPort) {
            $logger->notice("Listening on port ".$this->webserverPort);
            $resp_headers = array('Content-Type' => 'application/json; charset=utf8');
            $socket = new \React\Socket\Server($loop);
            $http   = new \React\Http\Server($socket);
            $http->on('request', function ($request, $response) use ($client, $resp_headers) {
                $request->on('data', function ($data) use ($client, $request, $response, $resp_headers) {
                    $req_headers = $request->getHeaders();
                    if (! isset($req_headers['Content-Type'])) {
                        $reply = ['error' => 'Invalid request; missing Content-Type'];
                        $response->writeHead(400, $resp_headers);
                        $response->end(json_encode($reply));
                        return;
                    }
                    if (strpos($req_headers['Content-Type'], 'json')) {
                        $data = json_decode($data, true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $reply = ['error' => 'Invalid request; ' . json_last_error_msg()];
                            $response->writeHead(400, $resp_headers);
                            $response->end(json_encode($reply));
                            return;
                        }
                    }
                    else {
                        parse_str($data, $post);
                        if (! isset($post['payload'])) {
                            $reply = ['error' => 'Invalid request; missing payload'];
                            $response->writeHead(400, $resp_headers);
                            $response->end(json_encode($reply));
                            return;
                        }
                        $payload = json_decode($post['payload'], true);
                        if (json_last_error() !== JSON_ERROR_NONE) {
                            $reply = ['error' => 'Invalid request; ' . json_last_error_msg()];
                            $response->writeHead(400, $resp_headers);
                            $response->end(json_encode($reply));
                            return;
                        }
                        $data = array_merge($post, $payload);
                    }

                    if ($this->authentificationToken !== null &&
                        (! isset($data['webserver_auth']) || $data['webserver_auth'] !== $this->authentificationToken)) {
                        $err = 'Invalid auth token';
                        $reply = array('error' => $err);
                        $http_code = 403;
                    }
                    elseif (! isset($data['webhook']) ||
                        (is_string($data['webhook']) && !isset($this->webhooks[$data['webhook']]))) {
                        $err = 'No webhook found named, "' . $data['webhook'] . '"';
                        $reply = ['error' => $err];
                        $http_code = 404;
                    }
                    else {
                        $hook = $this->webhooks[$data['webhook']];
                        $reply = ['data' => $hook->executeWebhook($data)];
                        $http_code = 200;
                    }
                    $response->writeHead($http_code, $resp_headers);
                    $response->end(json_encode($reply));
                });
            });
            $socket->listen($this->webserverPort);
        }

        $loop->run();
    }

    private function init() {
        $url = 'https://slack.com/api/rtm.start';
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url.'?'.http_build_query($this->params));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $body = curl_exec($ch);
        if ($body === false) {
            throw new \Exception('Error when requesting '.$url.' '.curl_error($ch));
        }
        curl_close($ch);
        $response = json_decode($body, true);
        if (is_null($response)) {
            throw new \Exception('Error when decoding body ('.$body.').');
        }
        $this->context = $response;
        if (isset($response['error'])) {
            throw new \Exception($response['error']);
        }
        $this->wsUrl = $response['url'];
    }

    public function loadInternalCommands() {
        $commands = array(
                          new \PhpSlackBot\Command\PingPongCommand,
                          new \PhpSlackBot\Command\CountCommand,
                          new \PhpSlackBot\Command\DateCommand,
                          new \PhpSlackBot\Command\PokerPlanningCommand,
                          );
        foreach ($commands as $command) {
            if (!isset($this->commands[$command->getName()])) {
                $this->commands[$command->getName()] = $command;
            }
        }
    }

    public function loadInternalWebhooks() {
        $webhooks = array(
                          new \PhpSlackBot\Webhook\OutputWebhook,
                          );
        foreach ($webhooks as $webhook) {
            if (!isset($this->webhooks[$webhook->getName()])) {
                $this->webhooks[$webhook->getName()] = $webhook;
            }
        }
    }

    private function getCommand($data) {
        if (empty($data['text'])) {
            return null;
        }

        $find = '/^'.preg_quote('<@'.$this->context['self']['id'].'>', '/').'[ ]*/';
        $text = preg_replace($find, '', $data['text']);

        if (empty($text)) {
            return null;
        }

        foreach ($this->commands as $commandName => $availableCommand) {
            $find = '/^'.preg_quote($commandName).'/';
            if (preg_match($find, $text)) {
                return $availableCommand;
            }
        }

        return null;
    }

}
