<?php

namespace Tripod\Mongo;

require_once(TRIPOD_DIR . "mongo/Config.class.php");

/**
 * Class IndexUtils
 * @package Tripod\Mongo
 */
class IndexUtils
{
    /**
     * Ensures the index for the given $dbName. As a consequence, sets the global
     * MongoCursor timeout to -1 for this thread, so use with caution from anything
     * other than a setup script
     * @param bool $reindex - force a reindex of existing data
     * @param null $dbName - database name to ensure indexes for
     * @param bool $background - index in the background (default) or lock DB whilst indexing
     */
    public function ensureIndexes($reindex=false,$dbName=null,$background=true)
    {
        //MongoCursor::$timeout = -1; // set this otherwise you'll see timeout errors for large indexes

        $config = Config::getInstance();
        $dbs = ($dbName==null) ? $config->getDbs() : array($dbName);
        foreach ($dbs as $dbName)
        {
            $collections = Config::getInstance()->getIndexesGroupedByCollection($dbName);
            foreach ($collections as $collectionName=>$indexes)
            {

                if ($reindex)
                {
                    $config->getCollectionForCBD($dbName, $collectionName)->deleteIndexes();
                }
                foreach ($indexes as $indexName=>$fields)
                {
                    $indexName = substr($indexName,0,127); // ensure max 128 chars
                    if (is_numeric($indexName))
                    {
                        // no name
                        $config->getCollectionForCBD($dbName, $collectionName)->ensureIndex($fields,array("background"=>$background));
                    }
                    else
                    {
                        $config->getCollectionForCBD($dbName, $collectionName)->ensureIndex($fields,array('name'=>$indexName,"background"=>$background));
                    }
                }
            }
            // finally, for views, make sure type is indexed
            $dataSources = array();
            foreach($config->getViewSpecifications($dbName) as $viewId=>$spec)
            {
                $dataSources[] = $spec['to'];
            }
            foreach(array_unique($dataSources) as $dataSource)
            {
                $config->getDatabase($dbName, $dataSource)
                    ->selectCollection(VIEWS_COLLECTION)
                    ->ensureIndex(array("_id.type"=>1),array("background"=>$background));
            }
        }
    }
}
