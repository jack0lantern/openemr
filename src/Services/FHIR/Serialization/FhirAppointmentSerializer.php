<?php

/**
 * FhirAppointmentSerializer.php
 * @package openemr
 * @link      https://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2022 Discover and Change, Inc. <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FHIR\Serialization;

use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRAppointment;

class FhirAppointmentSerializer
{
    public static function serialize(FHIRAppointment $object)
    {
        return $object->jsonSerialize();
    }

    /**
     * Takes a FHIR JSON representing an Appointment and returns the populated FHIRAppointment resource.
     * The FHIRAppointment constructor accepts the full array structure from decoded FHIR JSON.
     *
     * @param array $fhirJson The FHIR Appointment resource as decoded JSON
     * @return FHIRAppointment
     */
    public static function deserialize($fhirJson)
    {
        return new FHIRAppointment($fhirJson);
    }
}
