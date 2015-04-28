<?php

require_once TRIPOD_DIR . 'mongo/MongoTripodConfig.class.php';
require_once TRIPOD_DIR . 'mongo/ModifiedSubject.class.php';
require_once TRIPOD_DIR . 'exceptions/TripodException.class.php';
require_once TRIPOD_DIR . 'mongo/delegates/MongoTripodTables.class.php';

// TODO: need to put an index on createdDate, lastUpdatedDate and status
class MongoTripodQueue extends MongoTripodBase
{
    protected $config = null;
    protected $queueConfig = null;
    public function __construct($stat=null)
    {
        $this->config = MongoTripodConfig::getInstance();
        $this->queueConfig = $this->config->getQueueConfig();
        $connStr = $this->config->getQueueConnStr();

        $this->debugLog("Connecting to queue with $connStr");

        // select a database
        $this->podName = $this->queueConfig['collection'];
        $this->collection = $this->config->getQueueDatabase()->selectCollection($this->podName);

        if ($stat!=null) $this->stat = $stat;
    }

    /**
     * Processes the next item on the queue
     * @return bool - false if there is no next item to process, otherwise true
     */
    public function processNext()
    {
        $now = new MongoDate();
        $data = $this->fetchNextQueuedItem();
        if(!empty($data))
        {
            /* @var $createdOn MongoDate */
            $createdOn = $data['createdOn'];

            $tripod = $this->getMongoTripod($data);

            $operations = $data['operations'];
            $modifiedSubjects = array();

            // de-serialize changeset
            $cs = new ChangeSet();
            $cs->from_json($data["changeSet"]);

            foreach($operations as $op)
            {
                $composite = $tripod->getComposite($op);
                $modifiedSubjects = array_merge($modifiedSubjects,$composite->getModifiedSubjects($cs,$data['deletedSubjects'],$data['contextAlias']));
            }

            try
            {
                if(!empty($modifiedSubjects)){
                    /* @var $subject ModifiedSubject */
                    foreach ($modifiedSubjects as $subject) {
                        $subject->notify();
                    }
                }
                $this->removeItem($data);
            }
            catch(Exception $e)
            {
                $this->errorLog("Error processing item in queue: ".$e->getMessage(),array("data"=>$data));
                $this->failItem($data, $e->getMessage()."\n".$e->getTraceAsString());
            }

            // stat time taken to process item, from time it was created (queued)
            $timeTaken = ($now->usec/1000 + $now->sec*1000) - ($createdOn->usec/1000 + $createdOn->sec*1000);
            $this->getStat()->timer(MONGO_QUEUE_SUCCESS,$timeTaken);
            return true;
        }
        return false;
    }

    protected function getMongoTripod($data) {
        return new MongoTripod(
            $data['storeName'],
            $data['podName'],
            array('stat'=>$this->stat));
    }

    /**
     * Add an item to the index queue
     * @param ModifiedSubject $subject
     */
    public function addItem(ChangeSet $cs,array $deletedSubjects,$storeName,$podName,$operations)
    {
        if (!empty($operations)) {
            $data = array();
            $data["changeSet"] = $cs->to_json();
            $data["deletedSubjects"] = $deletedSubjects;
            $data["operations"] = $operations;
            $data["tripodConfig"] = MongoTripodConfig::getConfig();
            $data["storeName"] = $storeName;
            $data["podName"] = $podName;
            $data["_id"] = $this->getUniqId();
            $data["createdOn"] = new MongoDate();
            $data['status'] = "queued";
            $this->collection->insert($data);
        }
    }

    /**
     * Removes an item from the index queue
     *
     * @param $subject ModifiedSubject the item to remove from the queue
     */
    public function removeItem(array $data)
    {
        $id = $data['_id'];
        $this->collection->remove(array("_id"=>$id));
    }

    /**
     * This method updates the status of the queued item to failed; and logs any error message you specify
     *
     * @param $subject ModifiedSubject the item to fail
     * @param $errorMessage, any error message you wish to be logged with the queued item
     */
    public function failItem(array $data, $errorMessage=null)
    {
        $id = $data['_id'];
        $this->collection->update(
            array("_id"=>$id),
            array('$set'=>array('status'=>'failed', 'lastUpdated'=>new MongoDate(), 'errorMessage'=>$errorMessage)),
            array('upsert'=>false, 'multiple'=>false)
        );
        $this->getStat()->increment(MONGO_QUEUE_FAIL);
    }

    /**
     * This item grabs the next queued item, it sets the state of the queued item to processing before returning it.
     *
     * @return null|array if nothing in the queue, otherwise it returns the first document it finds.
     * @throws Exception
     */
    public function fetchNextQueuedItem()
    {
        $response = $this->config->getQueueDatabase()->command(array(
            "findAndModify" => $this->podName,
            "query" => array("status"=>"queued"),
            "update" => array('$set'=>array(
                "status"=>"processing",
                'lastUpdated'=>new MongoDate())
                ),
            'new'=>true
         ));
        if ($response["ok"]==true)
        {
            if(!empty($response['value']))
            {
                return $response['value'];
            }
            else
            {
                // nothing in the queue
                return NULL;
            }
        }
        else
        {
            throw new Exception("Fetch Next Queued Item, Find and update failed!\n" . print_r($response, true));
        }
    }

    /**
     * This method retrieves an item from the queue based on the unique qid
     * only use this if you know the id of the queued item; in all other cases
     * you should use fetchNextQueuedItem()
     *
     * @param $_id, the id of the item in the queue
     * @return array|null
     */
    public function getItem($_id)
    {
        return $this->collection->findOne(array('_id'=>$_id));
    }

    /**
     * This method returns the number of items currently on the queue
     * @return int
     */
    public function count()
    {
        return $this->collection->count();
    }

    /**
     * Deletes everything from the queue
     */
    public function purgeQueue()
    {
        $this->collection->drop();
    }

    protected function getUniqId()
    {
        return new MongoId();
    }
}
