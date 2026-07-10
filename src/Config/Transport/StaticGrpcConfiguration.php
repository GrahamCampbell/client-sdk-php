<?php
declare(strict_types=1);

namespace Momento\Config\Transport;

class StaticGrpcConfiguration implements IGrpcConfiguration
{

    private ?int $deadlineMilliseconds;
    private int $numGrpcChannels;

    public function __construct(
        ?int $deadlineMilliseconds = null,
        int $numGrpcChannels = 1
    ) {
        $this->deadlineMilliseconds = $deadlineMilliseconds;
        $this->numGrpcChannels = $numGrpcChannels;
    }

    public function getDeadlineMilliseconds(): ?int
    {
        return $this->deadlineMilliseconds;
    }

    public function withDeadlineMilliseconds(int $deadlineMilliseconds): StaticGrpcConfiguration
    {
        return new StaticGrpcConfiguration($deadlineMilliseconds, $this->numGrpcChannels);
    }

    public function getNumGrpcChannels(): int
    {
        return $this->numGrpcChannels;
    }

    public function withNumGrpcChannels(int $numGrpcChannels): IGrpcConfiguration
    {
        return new StaticGrpcConfiguration($this->deadlineMilliseconds, $numGrpcChannels);
    }
}
