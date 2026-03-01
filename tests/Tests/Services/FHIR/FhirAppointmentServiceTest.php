<?php

/*
 * FhirAppointmentServiceTest.php
 * @package openemr
 * @link      https://www.open-emr.org
 * @author    OpenEMR Contributors
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Tests\Services\FHIR;

use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRAppointment;
use OpenEMR\Services\FHIR\FhirAppointmentService;
use PHPUnit\Framework\TestCase;

class FhirAppointmentServiceTest extends TestCase
{
    public function testParseFhirResourceMapsAppointmentToOpenEMRFields(): void
    {
        $service = new class extends FhirAppointmentService {
            protected function resolvePatientPid(string $patientUuid)
            {
                return $patientUuid === 'patient-uuid-1' ? 1001 : false;
            }

            protected function resolveProviderId(string $providerUuid)
            {
                return $providerUuid === 'provider-uuid-1' ? 2001 : false;
            }

            protected function resolveFacilityIdFromLocation(string $locationUuid)
            {
                return $locationUuid === 'location-uuid-1' ? 3 : false;
            }

            protected function resolveDefaultFacilityId()
            {
                return 9;
            }

            protected function resolveCategoryId(?string $appointmentTypeCode)
            {
                return $appointmentTypeCode === 'office_visit' ? 5 : 10;
            }
        };

        $appointment = new FHIRAppointment([
            'status' => 'booked',
            'start' => '2026-03-02T08:00:00Z',
            'minutesDuration' => 30,
            'comment' => 'Follow up visit',
            'appointmentType' => [
                'coding' => [
                    [
                        'code' => 'office_visit',
                        'display' => 'Office Visit',
                    ],
                ],
                'text' => 'Office Visit',
            ],
            'participant' => [
                ['actor' => ['reference' => 'Patient/patient-uuid-1']],
                ['actor' => ['reference' => 'Practitioner/provider-uuid-1']],
                ['actor' => ['reference' => 'Location/location-uuid-1']],
            ],
        ]);

        $result = $service->parseFhirResource($appointment);

        $this->assertSame(1001, $result['pid']);
        $this->assertSame(2001, $result['pc_aid']);
        $this->assertSame(3, $result['pc_facility']);
        $this->assertSame(3, $result['pc_billing_location']);
        $this->assertSame(5, $result['pc_catid']);
        $this->assertSame('Office Visit', $result['pc_title']);
        $this->assertSame('Follow up visit', $result['pc_hometext']);
        $this->assertSame('*', $result['pc_apptstatus']);
        $this->assertSame('2026-03-02', $result['pc_eventDate']);
        $this->assertSame('08:00', $result['pc_startTime']);
        $this->assertSame(1800, $result['pc_duration']);
    }

    public function testParseFhirResourceRequiresPatientParticipant(): void
    {
        $service = new FhirAppointmentService();
        $appointment = new FHIRAppointment([
            'status' => 'booked',
            'start' => '2026-03-02T08:00:00Z',
            'minutesDuration' => 30,
            'participant' => [
                ['actor' => ['reference' => 'Practitioner/provider-uuid-1']],
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Patient participant is required');

        $service->parseFhirResource($appointment);
    }
}
