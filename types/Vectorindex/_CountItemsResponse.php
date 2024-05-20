<?php
# Generated by the protocol buffer compiler.  DO NOT EDIT!
# source: vectorindex.proto

namespace Vectorindex;

use Google\Protobuf\Internal\GPBType;
use Google\Protobuf\Internal\RepeatedField;
use Google\Protobuf\Internal\GPBUtil;

/**
 * Generated from protobuf message <code>vectorindex._CountItemsResponse</code>
 */
class _CountItemsResponse extends \Google\Protobuf\Internal\Message
{
    /**
     * Generated from protobuf field <code>uint64 item_count = 1;</code>
     */
    protected $item_count = 0;

    /**
     * Constructor.
     *
     * @param array $data {
     *     Optional. Data for populating the Message object.
     *
     *     @type int|string $item_count
     * }
     */
    public function __construct($data = NULL) {
        \GPBMetadata\Vectorindex::initOnce();
        parent::__construct($data);
    }

    /**
     * Generated from protobuf field <code>uint64 item_count = 1;</code>
     * @return int|string
     */
    public function getItemCount()
    {
        return $this->item_count;
    }

    /**
     * Generated from protobuf field <code>uint64 item_count = 1;</code>
     * @param int|string $var
     * @return $this
     */
    public function setItemCount($var)
    {
        GPBUtil::checkUint64($var);
        $this->item_count = $var;

        return $this;
    }

}
