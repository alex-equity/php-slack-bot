<?php
namespace PhpSlackBot\Command;

abstract class BaseCommand extends \PhpSlackBot\Base {

    protected $context;
    public function executeCommand($message) {
        return $this->execute($message);
    }

}
