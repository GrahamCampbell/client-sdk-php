<?php
# Generated by the protocol buffer compiler.  DO NOT EDIT!
# source: auth.proto

namespace Auth;

use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\RepeatedField;
use Google\Protobuf\Internal\GPBUtil;

/**
 * Generated from protobuf message <code>auth._GenerateApiTokenRequest</code>
 */
class _GenerateApiTokenRequest extends \Google\Protobuf\Internal\Message
{
    /**
     * session token recieved from `momento login` command
     *
     * Generated from protobuf field <code>string session_token = 3;</code>
     */
    protected $session_token = '';
    protected $expiry;

    /**
     * Constructor.
     *
     * @param array $data {
     *     Optional. Data for populating the Message object.
     *
     *     @type \Auth\_GenerateApiTokenRequest\Never $never
     *     @type \Auth\_GenerateApiTokenRequest\Expires $expires
     *     @type string $session_token
     *           session token recieved from `momento login` command
     * }
     */
    public function __construct($data = NULL) {
        \GPBMetadata\Auth::initOnce();
        parent::__construct($data);
    }

    /**
     * Generated from protobuf field <code>.auth._GenerateApiTokenRequest.Never never = 1;</code>
     * @return \Auth\_GenerateApiTokenRequest\Never|null
     */
    public function getNever()
    {
        return $this->readOneof(1);
    }

    public function hasNever()
    {
        return $this->hasOneof(1);
    }

    /**
     * Generated from protobuf field <code>.auth._GenerateApiTokenRequest.Never never = 1;</code>
     * @param \Auth\_GenerateApiTokenRequest\Never $var
     * @return $this
     */
    public function setNever($var)
    {
        GPBUtil::checkMessage($var, \Auth\_GenerateApiTokenRequest\Never::class);
        $this->writeOneof(1, $var);

        return $this;
    }

    /**
     * Generated from protobuf field <code>.auth._GenerateApiTokenRequest.Expires expires = 2;</code>
     * @return \Auth\_GenerateApiTokenRequest\Expires|null
     */
    public function getExpires()
    {
        return $this->readOneof(2);
    }

    public function hasExpires()
    {
        return $this->hasOneof(2);
    }

    /**
     * Generated from protobuf field <code>.auth._GenerateApiTokenRequest.Expires expires = 2;</code>
     * @param \Auth\_GenerateApiTokenRequest\Expires $var
     * @return $this
     */
    public function setExpires($var)
    {
        GPBUtil::checkMessage($var, \Auth\_GenerateApiTokenRequest\Expires::class);
        $this->writeOneof(2, $var);

        return $this;
    }

    /**
     * session token recieved from `momento login` command
     *
     * Generated from protobuf field <code>string session_token = 3;</code>
     * @return string
     */
    public function getSessionToken()
    {
        return $this->session_token;
    }

    /**
     * session token recieved from `momento login` command
     *
     * Generated from protobuf field <code>string session_token = 3;</code>
     * @param string $var
     * @return $this
     */
    public function setSessionToken($var)
    {
        GPBUtil::checkString($var, True);
        $this->session_token = $var;

        return $this;
    }

    /**
     * @return string
     */
    public function getExpiry()
    {
        return $this->whichOneof("expiry");
    }

}
