<?php
namespace PhpSlackBot\Webhook;

abstract class BaseWebhook extends \PhpSlackBot\Base {

    public function executeWebhook($payload) {
        return $this->execute($payload);
    }

}
