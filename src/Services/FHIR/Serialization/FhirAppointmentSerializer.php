<?php

/**
 * FhirAppointmentSerializer
 *
 * @package   OpenEMR
 * @link      https://www.open-emr.org
 * @author    OpenEMR
 * @copyright Copyright (c) 2025 OpenEMR
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FHIR\Serialization;

use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRAppointment;
use OpenEMR\FHIR\R4\FHIRResource\FHIRAppointment\FHIRAppointmentParticipant;

class FhirAppointmentSerializer
{
    public static function serialize(FHIRAppointment $object)
    {
        return $object->jsonSerialize();
    }

    /**
     * Takes FHIR JSON representing an Appointment and returns the populated resource.
     *
     * @param array $fhirJson The FHIR Appointment resource as array
     * @return FHIRAppointment
     */
    public static function deserialize($fhirJson)
    {
        $participants = $fhirJson['participant'] ?? [];

        unset($fhirJson['participant']);

        $appointment = new FHIRAppointment($fhirJson);

        foreach ($participants as $item) {
            $participant = new FHIRAppointmentParticipant($item);
            $appointment->addParticipant($participant);
        }

        return $appointment;
    }
}
