<?php

namespace Tripod\Mongo\Jobs;

use Tripod\Exceptions\Exception;
use \Tripod\Exceptions\ConfigException;
use Tripod\Exceptions\JobException;
use \MongoDB\Driver\ReadPreference;
use Tripod\ITripodConfigSerializer;
use Tripod\TripodConfigFactory;

/**
 * Todo: How to inject correct stat class... :-S
 */
abstract class JobBase extends \Tripod\Mongo\DriverBase
{
    private $tripod;
    const TRIPOD_CONFIG_KEY = 'tripodConfig';
    const TRIPOD_CONFIG_GENERATOR = 'tripodConfigGenerator';
    const QUEUE_KEY = 'queue';

    protected $mandatoryArgs = [];
    protected $configRequired = false;

    /** @var \Tripod\ITripodConfig */
    protected $tripodConfig;

    /** @var \Tripod\Timer */
    protected $timer;

    abstract public function perform();

    public function beforePeform()
    {
        $this->debugLog(
            '[JOBID ' . $this->job->payload['id'] . '] ' . get_class($this) . '::perform() start'
        );

        $this->timer = new \Tripod\Timer();
        $this->timer->start();
        $this->validateArgs();
        $this->setStatsConfig();

        if ($this->configRequired) {
            $this->setTripodConfig();
        }
    }

    public function afterPerform()
    {
        // stat time taken to process item, from time it was created (queued)
        $this->timer->stop();
        $this->debugLog(
            '[JOBID ' . $this->job->payload['id'] . '] ' . get_class($this) . "::perform() done in {$timer->result()}ms"
        );
    }

    /**
     * For mocking
     * @param string $storeName
     * @param string $podName
     * @param array $opts
     * @return \Tripod\Mongo\Driver
     */
    protected function getTripod($storeName, $podName, $opts = [])
    {
        $this->getTripodConfig();

        $opts = array_merge(
            $opts,
            [
                'stat' => $this->getStat(),
                'readPreference' => ReadPreference::RP_PRIMARY // important: make sure we always read from the primary
            ]
        );
        if ($this->tripod == null) {
            $this->tripod = new \Tripod\Mongo\Driver(
                $podName,
                $storeName,
                $opts
            );
        }
        return $this->tripod;
    }

    /**
     * Make sure each job considers how to validate its args
     * @return array
     */
    protected function getMandatoryArgs()
    {
        return $this->mandatoryArgs;
    }

    /**
     * Validate the arguments for this job
     * @throws \Exception
     */
    protected function validateArgs()
    {
        $message = null;
        foreach ($this->getMandatoryArgs() as $arg) {
            if (!isset($this->args[$arg])) {
                $message = "Argument $arg was not present in supplied job args for job " . get_class($this);
                $this->errorLog($message);
                throw new \Exception($message);
            }
        }
        if ($this->configRequired) {
            $this->ensureConfig();
        }
    }

    protected function ensureConfig()
    {
        if (!isset($this->args[self::TRIPOD_CONFIG_KEY]) && !isset($this->args[self::TRIPOD_CONFIG_GENERATOR])) {
            $message = 'Argument ' . self::TRIPOD_CONFIG_KEY . ' or ' . self::TRIPOD_CONFIG_GENERATOR .
                'was not present in supplied job args for job ' . get_class($this);
            $this->errorLog($message);
            throw new \Exception($message);
        }
    }

    /**
     * @param string $message
     * @param mixed $params
     */
    public function debugLog($message, $params = null)
    {
        parent::debugLog($message, $params);
    }

    /**
     * @param string $message
     * @param mixed $params
     */
    public function errorLog($message, $params = null)
    {
        parent::errorLog($message, $params);
    }


    /**
     * @param string $queueName
     * @param string $class
     * @param array $data
     * @param int $retryAttempts if queue fails, retry x times before throwing an exception
     * @return a tracking token for the submitted job
     * @throws JobException if there is a problem queuing the job
     */
    protected function submitJob($queueName, $class, array $data, $retryAttempts = 5)
    {
        // @see https://github.com/chrisboulton/php-resque/issues/228, when this PR is merged we can stop tracking the status in this way
        try {
            if (isset($data[self::TRIPOD_CONFIG_GENERATOR])) {
                $data[self::TRIPOD_CONFIG_GENERATOR] = $this->cacheConfig($data[self::TRIPOD_CONFIG_KEY]);
            }
            $token = $this->enqueue($queueName, $class, $data);
            if (!$this->getJobStatus($token)) {
                $this->errorLog("Could not retrieve status for queued $class job - job $token failed to $queueName");
                throw new \Exception("Could not retrieve status for queued job - job $token failed to $queueName");
            } else {
                $this->debugLog("Queued $class job with $token to $queueName");
                return $token;
            }
        } catch (\Exception $e) {
            if ($retryAttempts > 0) {
                sleep(1); // back off for 1 sec
                $this->warningLog("Exception queuing $class job - {$e->getMessage()}, retrying $retryAttempts times");
                return $this->submitJob($queueName, $class, $data, --$retryAttempts);
            } else {
                $this->errorLog("Exception queuing $class job - {$e->getMessage()}");
                throw new JobException("Exception queuing job  - {$e->getMessage()}", $e->getCode(), $e);
            }
        }
    }

    /**
     * Actually enqueues the job with Resque. Returns a tracking token. For mocking.
     * @param string $queueName
     * @param string $class
     * @param mixed $data
     * @internal param bool|\Tripod\Mongo\Jobs\false $tracking
     * @return string
     */
    protected function enqueue($queueName, $class, $data)
    {
        return \Resque::enqueue($queueName, $class, $data, true);
    }

    /**
     * Given a token, return the job status. For mocking
     * @param string $token
     * @return mixed
     */
    protected function getJobStatus($token)
    {
        $status = new \Resque_Job_Status($token);
        return $status->get();
    }

    /**
     * @return \Tripod\ITripodStat
     */
    public function getStat()
    {
        if ((!isset($this->statsConfig) || empty($this->statsConfig)) && isset($this->args['statsConfig'])) {
            $this->statsConfig = $this->args['statsConfig'];
        }
        return parent::getStat();
    }

    protected function serializeConfig(ITripodConfigSerializer $configSerializer)
    {
        return $configSerializer->serialize();
    }

    protected function deserializeConfig(array $config)
    {
        return TripodConfigFactory::create($config);
    }

    protected function setTripodConfig()
    {
        if (isset($this->args[self::TRIPOD_CONFIG_GENERATOR])) {
            $config =  $this->args[self::TRIPOD_CONFIG_GENERATOR];
        } else {
            $config = $this->args[self::TRIPOD_CONFIG_KEY];
        }
        $this->tripodConfig = $this->deserializeConfig($config);
    }

    protected function getTripodConfig()
    {
        if (!isset($this->tripodConfig)) {
            $this->ensureConfig();
            $this->setConfig();
        }
        return $this->tripodConfig;
    }

    protected function setStatsConfig()
    {
        if (isset($this->args['statsConfig'])) {
            $this->statsConfig = $this->args['statsConfig'];
        }
    }

    protected function getStatsConfig()
    {
        if (empty($this->statsConfig)) {
            $this->setStatsConfig();
        }
        return $this->statsConfig;
    }

    protected function getTripodOptions()
    {
        $statsConfig = $this->getStatsConfig();
        $options = [];
        if (!empty($statsConfig)) {
            $options['statsConfig'] = $statsConfig;
        }
        return $options;
    }
}
