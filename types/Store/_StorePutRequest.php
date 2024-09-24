<?php
# Generated by the protocol buffer compiler.  DO NOT EDIT!
# source: store.proto

namespace Store;

use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\RepeatedField;
use Google\Protobuf\Internal\GPBUtil;

/**
 * Generated from protobuf message <code>store._StorePutRequest</code>
 */
class _StorePutRequest extends \Google\Protobuf\Internal\Message
{
    /**
     * Generated from protobuf field <code>string key = 1;</code>
     */
    protected $key = '';
    /**
     * Generated from protobuf field <code>.store._StoreValue value = 2;</code>
     */
    protected $value = null;

    /**
     * Constructor.
     *
     * @param array $data {
     *     Optional. Data for populating the Message object.
     *
     *     @type string $key
     *     @type \Store\_StoreValue $value
     * }
     */
    public function __construct($data = NULL) {
        \GPBMetadata\Store::initOnce();
        parent::__construct($data);
    }

    /**
     * Generated from protobuf field <code>string key = 1;</code>
     * @return string
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * Generated from protobuf field <code>string key = 1;</code>
     * @param string $var
     * @return $this
     */
    public function setKey($var)
    {
        GPBUtil::checkString($var, True);
        $this->key = $var;

        return $this;
    }

    /**
     * Generated from protobuf field <code>.store._StoreValue value = 2;</code>
     * @return \Store\_StoreValue|null
     */
    public function getValue()
    {
        return $this->value;
    }

    public function hasValue()
    {
        return isset($this->value);
    }

    public function clearValue()
    {
        unset($this->value);
    }

    /**
     * Generated from protobuf field <code>.store._StoreValue value = 2;</code>
     * @param \Store\_StoreValue $var
     * @return $this
     */
    public function setValue($var)
    {
        GPBUtil::checkMessage($var, \Store\_StoreValue::class);
        $this->value = $var;

        return $this;
    }

}
