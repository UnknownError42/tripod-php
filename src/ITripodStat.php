<?php

namespace Tripod;

/**
 * Class ITripodStat
 * @package Tripod
 */
interface ITripodStat
{
    /**
     * @param string $operation
     * @return mixed
     */
    public function increment($operation);

    /**
     * @param string $operation
     * @param number $duration
     * @return mixed
     */
    public function timer($operation,$duration);
}