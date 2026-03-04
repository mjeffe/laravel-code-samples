<?php

namespace App\Models;

interface ResourceInterface
{
    /**
     * Return the name of the type of resource being implemented
     */
    public function getResourceType();

    /**
     * Return the id (whatever is appropriate) of the owner of this resource
     *
     * i.e. applicant_id
     */
    public function getOwnerId();

    /**
     * Return the current status of this resource
     */
    public function getState();

    /**
     * Return the academic year of this resources top level parent
     */
    public function getAcademicYear();
}