<?php
// GENERATED CODE -- DO NOT EDIT!
// Momento fork note: base class re-pointed at the in-repo
// Momento\Transport\BaseStub (ext-grpc removed).

namespace Cache_client;

use Momento\Transport\BaseStub;
use Momento\Transport\Channel;
use Momento\Transport\UnaryCall;

/**
 */
class PingClient extends BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * @param \Cache_client\_PingRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return UnaryCall
     */
    public function Ping(\Cache_client\_PingRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/cache_client.Ping/Ping',
        $argument,
        ['\Cache_client\_PingResponse', 'decode'],
        $metadata, $options);
    }

}
