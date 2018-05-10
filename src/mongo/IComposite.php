<?php

namespace Tripod\Mongo\Composites;

/**
 * Class IComposite
 * @package Tripod\Mongo\Composites
 */
interface IComposite
{
    /**
     * Returns the operation this composite can satisfy
     * @return string
     */
    public function getOperationType();

    /**
     * Returns the subjects that this composite will need to regenerate given changes made to the underlying dataset
     * @param array $subjectsAndPredicatesOfChange
     * @param string $contextAlias
     * @param \MongoDB\BSON\UTCDateTime|null Optional timestamp to filter on composites created on or before
     * @return mixed
     */
    public function getImpactedSubjects(array $subjectsAndPredicatesOfChange, $contextAlias, $timestamp = null);

    /**
     * Invalidate/regenerate the composite based on the impacted subject
     * @param \Tripod\Mongo\ImpactedSubject $subject
     * @return void
     */
    public function update(\Tripod\Mongo\ImpactedSubject $subject);
}
