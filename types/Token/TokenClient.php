<?php
// GENERATED CODE -- DO NOT EDIT!
// Momento fork note: base class re-pointed at the in-repo
// Momento\Transport\BaseStub (ext-grpc removed).

namespace Token;

use Momento\Transport\BaseStub;
use Momento\Transport\Channel;
use Momento\Transport\UnaryCall;

/**
 */
class TokenClient extends BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * @param \Token\_GenerateDisposableTokenRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return UnaryCall
     */
    public function GenerateDisposableToken(\Token\_GenerateDisposableTokenRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/token.Token/GenerateDisposableToken',
        $argument,
        ['\Token\_GenerateDisposableTokenResponse', 'decode'],
        $metadata, $options);
    }

}
