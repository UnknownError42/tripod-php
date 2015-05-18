<?php

require "../../src/tripod.inc.php";
// the global is necessary for Resque worker to send statements to
$logger = new \Monolog\Logger("TRIPOD-WORKER");
$logger->pushHandler(new \Monolog\Handler\StreamHandler('php://stderr', Psr\Log\LogLevel::WARNING)); // resque too chatty on NOTICE & INFO. YMMV

// this is so tripod itself uses the same logger
\Tripod\Mongo\TripodBase::$logger = new \Monolog\Logger("TRIPOD-JOB",array(new \Monolog\Handler\StreamHandler('php://stderr', Psr\Log\LogLevel::DEBUG)));