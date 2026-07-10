<?php
// GENERATED CODE -- DO NOT EDIT!
// Momento fork note: base class re-pointed at the in-repo
// Momento\Transport\BaseStub (ext-grpc removed).

namespace Webhook;

use Momento\Transport\BaseStub;
use Momento\Transport\Channel;
use Momento\Transport\UnaryCall;

/**
 * A Webhook is a mechanism to consume messages on a Topic.
 * The primary purpose of webhooks in Momento is to enable
 * Lambda to be a subscriber to the messages sent on a topic.
 * Secondarily, webhooks open us up to a whole lot of integrations
 * (slack, discord, event bridge, etc).
 */
class WebhookClient extends BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * @param \Webhook\_PutWebhookRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return UnaryCall
     */
    public function PutWebhook(\Webhook\_PutWebhookRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/webhook.Webhook/PutWebhook',
        $argument,
        ['\Webhook\_PutWebhookResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Webhook\_DeleteWebhookRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return UnaryCall
     */
    public function DeleteWebhook(\Webhook\_DeleteWebhookRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/webhook.Webhook/DeleteWebhook',
        $argument,
        ['\Webhook\_DeleteWebhookResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Webhook\_ListWebhookRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return UnaryCall
     */
    public function ListWebhooks(\Webhook\_ListWebhookRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/webhook.Webhook/ListWebhooks',
        $argument,
        ['\Webhook\_ListWebhooksResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Webhook\_GetWebhookSecretRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return UnaryCall
     */
    public function GetWebhookSecret(\Webhook\_GetWebhookSecretRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/webhook.Webhook/GetWebhookSecret',
        $argument,
        ['\Webhook\_GetWebhookSecretResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * @param \Webhook\_RotateWebhookSecretRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return UnaryCall
     */
    public function RotateWebhookSecret(\Webhook\_RotateWebhookSecretRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/webhook.Webhook/RotateWebhookSecret',
        $argument,
        ['\Webhook\_RotateWebhookSecretResponse', 'decode'],
        $metadata, $options);
    }

}
