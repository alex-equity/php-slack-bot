<?php
namespace PhpSlackBot\Command;

class PingPongCommand extends BaseCommand {

    protected function configure() {
        $this->setName('ping');
    }

    protected function execute($message) {
        $this->send($this->getCurrentChannel(), $this->getCurrentUser(), 'Pong');
    }

}
