<?php
require_once dirname(__FILE__).'/../TripodTestBase.php';

class MongoTripodTablesTest extends TripodTestBase
{
    /**
     * @var MongoTripod
     */
    protected $tripod = null;
    /**
     * @var MongoTransactionLog
     */
    protected $tripodTransationLog = null;

    /**
     * @var MongoTripodTables
     */
    protected $tripodTables = null;

    private $tablesConstParams = null;

    protected function setUp()
    {
        parent::setup();
        //Mongo::setPoolSize(200);

        $this->tripodTransactionLog = new MongoTransactionLog();
        $this->tripodTransactionLog->purgeAllTransactions();

        $this->tripod = new MongoTripod("CBD_testing", "testing", array("async"=>array(OP_VIEWS=>false, OP_TABLES=>false, OP_SEARCH=>false)));

        $this->tripod->collection->drop();
        $this->tripod->setTransactionLog($this->tripodTransactionLog);

        $this->loadBaseDataViaTripod();

        // purge Queue
        $queue = new MongoTripodQueue();
        $queue->purgeQueue();

        $this->tablesConstParams = array($this->tripod->db,$this->tripod->collection,'http://talisaspire.com/');

        $this->tripodTables = new MongoTripodTables($this->tripod->db,$this->tripod->collection,null); // pass null context, should default to http://talisaspire.com

        // purge tables
        $this->tripodTables->db->selectCollection("table_rows")->drop();
    }

    public function testTripodSaveChangesUpdatesLiteralTripleInTable()
    {
        $this->tripodTables->generateTableRows("t_resource",'http://talisaspire.com/resources/3SplCtWGPqEyXcDiyhHQpA-2');

        $t1 = $this->tripodTables->getTableRows("t_resource",array("_id.r"=>"http://talisaspire.com/resources/3SplCtWGPqEyXcDiyhHQpA-2"));

        $expectedIsbn13s = array("9780393929691","9780393929691-2");
        $this->assertEquals($expectedIsbn13s,$t1['results'][0]['isbn13']);

        $g1 = $this->tripod->describeResource("http://talisaspire.com/works/4d101f63c10a6");
        $g2 = $this->tripod->describeResource("http://talisaspire.com/works/4d101f63c10a6");

        $g2->add_literal_triple("http://talisaspire.com/works/4d101f63c10a6",$g2->qname_to_uri("bibo:isbn13"),"9780393929691-3");
        $this->tripod->saveChanges($g1,$g2,'http://talisaspire.com/');

        $t2 = $this->tripodTables->getTableRows("t_resource",array("_id.r"=>"http://talisaspire.com/resources/3SplCtWGPqEyXcDiyhHQpA-2"));

        $expectedIsbn13s = array("9780393929691","9780393929691-2","9780393929691-3");
        $this->assertEquals($expectedIsbn13s,$t2['results'][0]['isbn13']);
    }

// TODO: work out if these tests are still relevant necessary now that impacted documents are calculated outside the update() method
//    public function testTripodQueuedWorkTriggersRegenerationOfTwoResources()
//    {
//        $mockTables = $this->getMock('MongoTripodTables', array('generateTableRows'), $this->tablesConstParams);
//        $mockTables->expects($this->at(0))->method('generateTableRows')->with("t_resource","http://talisaspire.com/resources/3SplCtWGPqEyXcDiyhHQpA","http://talisaspire.com/");
//        $mockTables->expects($this->at(1))->method('generateTableRows')->with("t_resource","http://talisaspire.com/resources/3SplCtWGPqEyXcDiyhHQpA-2","http://talisaspire.com/");
//
//        // generate table rows
//        $this->tripodTables->generateTableRows("t_resource");
//
//        $queuedItem = new ModifiedSubject(array("r"=>"http://talisaspire.com/works/4d101f63c10a6","c"=>'http://talisaspire.com/'));
//        // next, trigger regen for work we know is associated with 2x resources. Should trigger view regen for resources
//        $mockTables->update($queuedItem);
//    }
//
//    public function testTripodQueuedWorkTriggersRegenerationOfOneResource()
//    {
//        $mockTables = $this->getMock('MongoTripodTables', array('generateTableRows'), $this->tablesConstParams);
//        $mockTables->expects($this->once())->method('generateTableRows')->with("t_resource","http://talisaspire.com/resources/3SplCtWGPqEyXcDiyhHQpA-2","http://talisaspire.com/");
//
//        // generate table rows
//        $this->tripodTables->generateTableRows("t_resource",'http://talisaspire.com/resources/3SplCtWGPqEyXcDiyhHQpA-2');
//
//        $queuedItem = new ModifiedSubject(array("r"=>"http://talisaspire.com/works/4d101f63c10a6","c"=>'http://talisaspire.com/'));
//        // next, trigger regen for work we know is associated with resource above. Should trigger view regen for resource
//        $mockTables->update($queuedItem);
//    }

    public function testUpdateWillDeleteItem()
    {
        $mockTables = $this->getMock('MongoTripodTables', array('deleteTableRowsForResource','generateTableRowsForResource'), $this->tablesConstParams);
        $mockTables->expects($this->once())->method('deleteTableRowsForResource')->with("http://foo","context");
        $mockTables->expects($this->never())->method('generateTableRowsForResource');

        $data = array();
        $data["r"] = "http://foo";
        $data["c"] = "context";
        $data["delete"] = true;
        $mockTables->update(new ModifiedSubject($data));
    }

    public function testUpdateWillGenerateRows()
    {
        $mockTables = $this->getMock('MongoTripodTables', array('deleteRowsForResource','generateTableRowsForResource'), $this->tablesConstParams);
        $mockTables->expects($this->once())->method('generateTableRowsForResource')->with("http://foo","context");
        $mockTables->expects($this->never())->method('deleteTableRowsForResource');

        $data = array();
        $data["r"] = "http://foo";
        $data["c"] = "context";
        $mockTables->update(new ModifiedSubject($data));
    }

    public function testGenerateTableRows()
    {
        $this->tripodTables->generateTableRows("t_resource");

        $t1 = $this->tripodTables->getTableRows("t_resource");

        // expecting two rows
        $this->assertEquals(count($t1['results']),2);
        $result = $t1['results'][0];

        // check out the columns
        $this->assertTrue(isset($result['type']),"Result does not contain type");
        $this->assertTrue(isset($result['isbn']),"Result does not contain isbn");
        $this->assertTrue(isset($result['isbn13']),"Result does not contain isbn13");
    }

    public function testGetTableRowsSort()
    {
        $this->tripodTables->generateTableRows("t_resource");

        $t1 = $this->tripodTables->getTableRows("t_resource",array(),array("value.isbn"=>-1));
        // expecting two rows, first row should be one with highest numberic value of ISBN, due to sort DESC
        $this->assertEquals('http://talisaspire.com/resources/3SplCtWGPqEyXcDiyhHQpA-2',$t1['results'][0]['_id']['r']);

        $t1 = $this->tripodTables->getTableRows("t_resource",array(),array("value.isbn"=>1));

        // expecting two rows, first row should be one with lowest numberic value of ISBN, due to sort ASC
        $this->assertEquals('http://talisaspire.com/resources/3SplCtWGPqEyXcDiyhHQpA',$t1['results'][0]['_id']['r']);
    }

    public function testGetTableRowsFilter()
    {
        $this->tripodTables->generateTableRows("t_resource");

        $t1 = $this->tripodTables->getTableRows("t_resource",array("value.isbn"=>'9780393929690')); // only bring back rows with isbn = 9780393929690

        // expecting one row
        $this->assertTrue(count($t1['results'])==1);
        $this->assertEquals('http://talisaspire.com/resources/3SplCtWGPqEyXcDiyhHQpA',$t1['results'][0]['_id']['r']);
    }

    public function testGetTableRowsLimitOffset()
    {
        $this->tripodTables->generateTableRows("t_resource");

        $t1 = $this->tripodTables->getTableRows("t_resource",array(),array("value.isbn"=>1),0,1);

        // expecting http://talisaspire.com/resources/3SplCtWGPqEyXcDiyhHQpA
        $this->assertTrue(count($t1['results'])==1);
        $this->assertEquals('http://talisaspire.com/resources/3SplCtWGPqEyXcDiyhHQpA',$t1['results'][0]['_id']['r']);

        $t2 = $this->tripodTables->getTableRows("t_resource",array(),array("value.isbn"=>1),1,1);

        // expecting http://talisaspire.com/resources/3SplCtWGPqEyXcDiyhHQpA-2
        $this->assertTrue(count($t2['results'])==1);
        $this->assertEquals('http://talisaspire.com/resources/3SplCtWGPqEyXcDiyhHQpA-2',$t2['results'][0]['_id']['r']);
    }

    public function testGenerateTableRowsForResourceUnnamespaced()
    {
        $data = array();
        $data["r"] = "http://basedata.com/b/2";
        $data["c"] = "http://basedata.com/b/DefaultGraph";
        $this->tripodTables->update(new ModifiedSubject($data));

        $rows = $this->tripodTables->getTableRows("t_work2");

        $this->assertTrue($rows["head"]["count"]==1,"Expected one row");
    }
    public function testGenerateTableRowsForResourceNamespaced()
    {
        $data = array();
        $data["r"] = "baseData:2";
        $data["c"] = "baseData:DefaultGraph";
        $this->tripodTables->update(new ModifiedSubject($data));

        $rows = $this->tripodTables->getTableRows("t_work2");

        $this->assertTrue($rows["head"]["count"]==1,"Expected one row");
    }
    public function testGenerateTableRowsForResourceContextNamespaced()
    {
        $data = array();
        $data["r"] = "http://basedata.com/b/2";
        $data["c"] = "baseData:DefaultGraph";
        $this->tripodTables->update(new ModifiedSubject($data));

        $rows = $this->tripodTables->getTableRows("t_work2");

        $this->assertTrue($rows["head"]["count"]==1,"Expected one row");
    }
    public function testGenerateTableRowsForResourceResourceNamespaced()
    {
        $data = array();
        $data["r"] = "baseData:2";
        $data["c"] = "http://basedata.com/b/DefaultGraph";
        $this->tripodTables->update(new ModifiedSubject($data));

        $rows = $this->tripodTables->getTableRows("t_work2");

        $this->assertTrue($rows["head"]["count"]==1,"Expected one row");
    }

    public function testGenerateTableRowsForResourcesOfTypeWithNamespace()
    {
        /* @var $mockTripodTables MongoTripodTables */
        $mockTripodTables = $this->getMock('MongoTripodTables', array('generateTableRows'), array($this->tripod->db,$this->tripod->collection,'http://talisaspire.com/'));
        $mockTripodTables->expects($this->atLeastOnce())->method('generateTableRows')->will($this->returnValue(array("ok"=>true)));

        // check where referred to as acorn:Work2 in spec...
        $mockTripodTables->generateTableRowsForType("http://talisaspire.com/schema#Work2");

        /* @var $mockTripodTables MongoTripodTables */
        $mockTripodTables = $this->getMock('MongoTripodTables', array('generateTableRows'), array($this->tripod->db,$this->tripod->collection,'http://talisaspire.com/'));
        $mockTripodTables->expects($this->atLeastOnce())->method('generateTableRows')->will($this->returnValue(array("ok"=>true)));

        // check where referred to as http://talisaspire.com/schema#Resource in spec...
        $mockTripodTables->generateTableRowsForType("acorn:Resource");
    }

}