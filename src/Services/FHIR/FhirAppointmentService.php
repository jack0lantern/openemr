<?php

/**
 * FhirAppointmentService handles the mapping of data from the OpenEMR appointment service into FHIR resources.
 * @package openemr
 * @link      https://www.open-emr.org
 * @author    Stephen Nielson <snielson@discoverandchange.com>
 * @copyright Copyright (c) 2022 Discover and Change, Inc. <snielson@discoverandchange.com>
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Services\FHIR;

use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Uuid\UuidRegistry;
use OpenEMR\FHIR\R4\FHIRDomainResource\FHIRAppointment;
use OpenEMR\FHIR\R4\FHIRElement\FHIRAppointmentStatus;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCodeableConcept;
use OpenEMR\FHIR\R4\FHIRElement\FHIRCoding;
use OpenEMR\FHIR\R4\FHIRElement\FHIRId;
use OpenEMR\FHIR\R4\FHIRElement\FHIRInstant;
use OpenEMR\FHIR\R4\FHIRElement\FHIRMeta;
use OpenEMR\FHIR\R4\FHIRElement\FHIRParticipationStatus;
use OpenEMR\FHIR\R4\FHIRResource\FHIRAppointment\FHIRAppointmentParticipant;
use OpenEMR\FHIR\R4\FHIRResource\FHIRDomainResource;
use OpenEMR\Services\AppointmentService;
use OpenEMR\Services\FHIR\Traits\BulkExportSupportAllOperationsTrait;
use OpenEMR\Services\FHIR\Traits\FhirBulkExportDomainResourceTrait;
use OpenEMR\Services\FHIR\Traits\FhirServiceBaseEmptyTrait;
use OpenEMR\Services\FHIR\Traits\PatientSearchTrait;
use OpenEMR\Services\PatientService;
use OpenEMR\Services\Search\FhirSearchParameterDefinition;
use OpenEMR\Services\Search\ISearchField;
use OpenEMR\Services\Search\SearchFieldType;
use OpenEMR\Services\Search\ServiceField;
use OpenEMR\Validators\ProcessingResult;

class FhirAppointmentService extends FhirServiceBase implements IPatientCompartmentResourceService, IFhirExportableResourceService
{
    use FhirServiceBaseEmptyTrait;
    use BulkExportSupportAllOperationsTrait;
    use FhirBulkExportDomainResourceTrait;
    use PatientSearchTrait;

    const APPOINTMENT_TYPE_LOCATION = "LOC";
    const APPOINTMENT_TYPE_LOCATION_TEXT = "Location";
    const PARTICIPANT_TYPE_LOCATION = "LOC";
    const PARTICIPANT_TYPE_LOCATION_TEXT = "Location";
    const PARTICIPANT_TYPE_PARTICIPANT = "PART";
    const PARTICIPANT_TYPE_PRIMARY_PERFORMER = "PPRF";
    const PARTICIPANT_TYPE_PRIMARY_PERFORMER_TEXT = "Primary Performer";
    const PARTICIPANT_TYPE_PARTICIPANT_TEXT = "Participant";

    /**
     * @var AppointmentService
     */
    private $appointmentService;

    public function __construct($fhirApiURL = null)
    {
        parent::__construct($fhirApiURL);
        $this->appointmentService = new AppointmentService();
    }

    /**
     * Returns an array mapping FHIR Resource search parameters to OpenEMR search parameters
     */
    protected function loadSearchParameters()
    {
        return  [
            'patient' => $this->getPatientContextSearchField(),
            '_id' => new FhirSearchParameterDefinition('_id', SearchFieldType::TOKEN, [new ServiceField('pc_uuid', ServiceField::TYPE_UUID)]),
            'date' => new FhirSearchParameterDefinition('date', SearchFieldType::DATE, ['pc_eventDate']),
            '_lastUpdated' => $this->getLastModifiedSearchField(),
        ];
    }

    public function getLastModifiedSearchField(): ?FhirSearchParameterDefinition
    {
        return new FhirSearchParameterDefinition('_lastUpdated', SearchFieldType::DATETIME, ['pc_time']);
    }

    /**
     * Parses an OpenEMR data record, returning the equivalent FHIR Resource
     *
     * @param $dataRecord The source OpenEMR data record
     * @param $encode Indicates if the returned resource is encoded into a string. Defaults to True.
     * @return the FHIR Resource. Returned format is defined using $encode parameter.
     */
    public function parseOpenEMRRecord($dataRecord = [], $encode = false)
    {
        $appt = new FHIRAppointment();

        $fhirMeta = new FHIRMeta();
        $fhirMeta->setVersionId("1");
        $fhirMeta->setLastUpdated(UtilsService::getLocalDateAsUTC($dataRecord['pc_time']));
        $appt->setMeta($fhirMeta);

        $id = new FHIRId();
        $id->setValue($dataRecord['pc_uuid']);
        $appt->setId($id);

        // now we need to parse out our status
        $statusCode = 'pending'; // there can be a lot of different status and we will default to pending
        switch ($dataRecord['pc_apptstatus']) {
            case '-': // none
                // None of the participant(s) have finalized their acceptance of the appointment request, and the start/end time might not be set yet.
                $statusCode = 'proposed';
                break;

            case '#': // insurance / financial issue
            case '^': // pending
                // Some or all of the participant(s) have not finalized their acceptance of the appointment request.
                $statusCode = 'pending';
                break;
            case '>': // checked out
            case '$': // coding done
                $statusCode = 'fulfilled';
                break;
            case 'AVM': // AVM confirmed
            case 'SMS': // SMS confirmed
            case 'EMAIL': // Email confirmed
            case '*': // reminder done
                // All participant(s) have been considered and the appointment is confirmed to go ahead at the date/times specified.
                $statusCode = 'booked';
                break;
            case '%': // Cancelled < 24h
            case '!': // left w/o visit
            case 'x':
                // The appointment has been cancelled.
                $statusCode = 'cancelled';
                break;
            case '?':
                // Some or all of the participant(s) have not/did not appear for the appointment (usually the patient).
                $statusCode = 'noshow';
                break;
            case '~': // arrived late
            case '@':
                $statusCode = 'arrived';
                break;
            case '<': // in exam room
            case '+': // chart pulled
                // When checked in, all pre-encounter administrative work is complete, and the encounter may begin. (where multiple patients are involved, they are all present).
                $statusCode = 'checked-in';
                break;
            case 'CALL': // Callback requested
                $statusCode = 'waitlist';
                //  The appointment has been placed on a waitlist, to be scheduled/confirmed in the future when a slot/service is available. A specific time might or might not be pre-allocated.
                break;
        }
        // TODO: add an event here allowing people to update / configure the FHIR status
        $apptStatus = new FHIRAppointmentStatus();
        $apptStatus->setValue($statusCode);
        $appt->setStatus($apptStatus);

        // now add appointmentType coding
        if (!empty($dataRecord['pc_catid'])) {
            $category = $this->appointmentService->getOneCalendarCategory($dataRecord['pc_catid']);
            $appointmentType = new FHIRCodeableConcept();
            $code = new FHIRCoding();
            $code->setCode($category[ 0 ][ 'pc_constant_id' ]);
            $code->setDisplay($category[ 0 ][ 'pc_catname' ]);
            // var_dump( $_SERVER );
            $system = str_replace('/Appointment', '/ValueSet/appointment-type', $GLOBALS['site_addr_oath'] . ($_SERVER['REDIRECT_URL'] ?? ''));
            $code->setSystem($system);
            $appointmentType->addCoding($code);
            $appt->setAppointmentType($appointmentType);
        }


        // now parse out the participants
        // patient first
        if (!empty($dataRecord['puuid'])) {
            $patient = new FHIRAppointmentParticipant();
            $participantType = UtilsService::createCodeableConcept([
                self::PARTICIPANT_TYPE_PARTICIPANT =>
                    [
                        'code' => self::PARTICIPANT_TYPE_PARTICIPANT
                        ,'description' => self::PARTICIPANT_TYPE_PARTICIPANT_TEXT
                        ,'system' => FhirCodeSystemConstants::HL7_PARTICIPATION_TYPE
                    ]
            ]);
            $patient->addType($participantType);
            $patient->setActor(UtilsService::createRelativeReference('Patient', $dataRecord['puuid']));
            $status = new FHIRParticipationStatus();
            $status->setValue('accepted'); // we don't really track any other field right now in FHIR
            $patient->setStatus($status);
            $appt->addParticipant($patient);
        }

        // now provider
        if (!empty($dataRecord['pce_aid_uuid'])) {
            $provider = new FHIRAppointmentParticipant();
            $providerType = UtilsService::createCodeableConcept([
                self::PARTICIPANT_TYPE_PRIMARY_PERFORMER =>
                    [
                        'code' => self::PARTICIPANT_TYPE_PRIMARY_PERFORMER
                        ,'description' => self::PARTICIPANT_TYPE_PRIMARY_PERFORMER_TEXT
                        ,'system' => FhirCodeSystemConstants::HL7_PARTICIPATION_TYPE
                    ]
            ]);
            $provider->addType($providerType);
            // we can only provide the provider if they have an NPI, otherwise they are a person
            if (!empty($dataRecord['pce_aid_npi'])) {
                $provider->setActor(UtilsService::createRelativeReference('Practitioner', $dataRecord['pce_aid_uuid']));
            } else {
                $provider->setActor(UtilsService::createRelativeReference('Person', $dataRecord['pce_aid_uuid']));
            }
            $status = new FHIRParticipationStatus();
            $status->setValue('accepted'); // we don't really track any other field right now in FHIR
            $provider->setStatus($status);
            $appt->addParticipant($provider);
        }

        // now location
        if (!empty($dataRecord['facility_uuid'])) {
            $location = new FHIRAppointmentParticipant();
            $participantType = UtilsService::createCodeableConcept([
                self::PARTICIPANT_TYPE_LOCATION =>
                    [
                        'code' => self::PARTICIPANT_TYPE_LOCATION
                        ,'description' => self::PARTICIPANT_TYPE_LOCATION_TEXT
                        ,'system' => FhirCodeSystemConstants::HL7_PARTICIPATION_TYPE
                    ]
            ]);
            $location->addType($participantType);
            $location->setActor(UtilsService::createRelativeReference('Location', $dataRecord['facility_uuid']));
            $status = new FHIRParticipationStatus();
            $status->setValue('accepted'); // we don't really track any other field right now in FHIR
            $location->setStatus($status);
            $appt->addParticipant($location);
        }

        // now let's get start and end dates

        // start time
        if (!empty($dataRecord['pc_eventDate'])) {
            $concatenatedDate = $dataRecord['pc_eventDate'] . ' ' . $dataRecord['pc_startTime'];
            $startInstant = UtilsService::getLocalDateAsUTC($concatenatedDate);
            $appt->setStart(new FHIRInstant($startInstant));
        } elseif ($dataRecord['pc_endDate'] != '0000-00-00' && !empty($dataRecord['pc_startTime'])) {
            $concatenatedDate = $dataRecord['pc_endDate'] . ' ' . $dataRecord['pc_startTime'];
            $startInstant = UtilsService::getLocalDateAsUTC($concatenatedDate);
            $appt->setStart(new FHIRInstant($startInstant));
        }

        // if we have a start date and and end time we will use that
        if (!empty($dataRecord['pc_eventDate']) && !empty($dataRecord['pc_endTime'])) {
            $concatenatedDate = $dataRecord['pc_eventDate'] . ' ' . $dataRecord['pc_endTime'];
            $endInstant = UtilsService::getLocalDateAsUTC($concatenatedDate);
            $appt->setEnd(new FHIRInstant($endInstant));
        } elseif (!empty($dataRecord['pc_endDate']) && !empty($dataRecord['pc_endTime'])) {
            $concatenatedDate = $dataRecord['pc_endDate'] . ' ' . $dataRecord['pc_endTime'];
            $endInstant = UtilsService::getLocalDateAsUTC($concatenatedDate);
            $appt->setEnd(new FHIRInstant($endInstant));
        }

        if (!empty($dataRecord['pc_hometext'])) {
            $appt->setComment($dataRecord['pc_hometext']);
        }

        return $appt;
    }


    /**
     * Searches for OpenEMR records using OpenEMR search parameters
     * @param array<string, ISearchField> $openEMRSearchParameters OpenEMR search fields
    * @return ProcessingResult OpenEMR records
     */
    protected function searchForOpenEMRRecords($openEMRSearchParameters): ProcessingResult
    {
        return $this->appointmentService->search($openEMRSearchParameters, true);
    }

    /**
     * Creates the Provenance resource  for the equivalent FHIR Resource
     *
     * @param $dataRecord The source OpenEMR data record
     * @param $encode Indicates if the returned resource is encoded into a string. Defaults to True.
     * @return the FHIR Resource. Returned format is defined using $encode parameter.
     */
    public function createProvenanceResource($dataRecord, $encode = false)
    {
        if (!($dataRecord instanceof FHIRAppointment)) {
            throw new \BadMethodCallException("Data record should be correct instance class");
        }
        $fhirProvenanceService = new FhirProvenanceService();
        // we don't have an individual authorship right now for appointments so we default to billing organization
        $fhirProvenance = $fhirProvenanceService->createProvenanceForDomainResource($dataRecord);
        if ($encode) {
            return json_encode($fhirProvenance);
        } else {
            return $fhirProvenance;
        }
    }

    /**
     * Parses a FHIR Appointment resource, returning the equivalent OpenEMR record.
     *
     * @param FHIRDomainResource $fhirResource The source FHIR resource
     * @return array a mapped OpenEMR data record (array)
     */
    public function parseFhirResource(FHIRDomainResource $fhirResource)
    {
        if (!$fhirResource instanceof FHIRAppointment) {
            throw new \BadMethodCallException("FHIR resource must be of type FHIRAppointment");
        }

        $patientService = new PatientService();
        $pid = null;
        $pc_aid = null;
        $pc_facility = null;
        $pc_billing_location = null;

        foreach ($fhirResource->getParticipant() ?? [] as $participant) {
            $actor = $participant->getActor();
            if (empty($actor) || empty($actor->getReference())) {
                continue;
            }
            $ref = (string) $actor->getReference();
            $parsed = UtilsService::parseReference($actor);
            $uuid = $parsed['uuid'] ?? null;
            $resourceType = $parsed['type'] ?? null;

            if (empty($uuid) && !empty($ref)) {
                $parts = explode('/', $ref);
                if (count($parts) >= 2) {
                    $resourceType = $parts[0] ?? null;
                    $uuid = $parts[1] ?? null;
                }
            }

            if (empty($uuid)) {
                continue;
            }

            $uuidBytes = UuidRegistry::uuidToBytes($uuid);

            if ($resourceType === 'Patient') {
                $pid = $patientService->getPidByUuid($uuid);
            } elseif ($resourceType === 'Practitioner' || $resourceType === 'Person') {
                $pc_aid = \OpenEMR\Services\BaseService::getIdByUuid($uuidBytes, 'users', 'id');
            } elseif ($resourceType === 'Location') {
                $facilityId = QueryUtils::fetchSingleValue(
                    "SELECT f.id FROM facility f
                    INNER JOIN uuid_mapping um ON f.uuid = um.target_uuid AND um.resource = 'Location'
                    WHERE um.uuid = ?",
                    'id',
                    [$uuidBytes]
                );
                if ($facilityId !== null && $facilityId !== false) {
                    $pc_facility = $facilityId;
                    $pc_billing_location = $pc_facility;
                }
            }
        }

        if (empty($pid)) {
            throw new \InvalidArgumentException("Appointment must have a Patient participant");
        }

        if (empty($pc_facility)) {
            $pc_facility = QueryUtils::fetchSingleValue("SELECT id FROM facility ORDER BY id LIMIT 1", 'id', []);
            $pc_billing_location = $pc_facility;
        }

        $start = $fhirResource->getStart();
        $startValue = $start && method_exists($start, 'getValue') ? $start->getValue() : ($start ?? null);
        if (empty($startValue)) {
            throw new \InvalidArgumentException("Appointment must have a start date/time");
        }

        $startDt = new \DateTime($startValue);
        $pc_eventDate = $startDt->format('Y-m-d');
        $pc_startTime = $startDt->format('H:i:s');

        $minutesDuration = 30;
        $minutesEl = $fhirResource->getMinutesDuration();
        if ($minutesEl && method_exists($minutesEl, 'getValue')) {
            $minutesDuration = (int) $minutesEl->getValue();
        } elseif ($fhirResource->getEnd()) {
            $end = $fhirResource->getEnd();
            $endValue = $end && method_exists($end, 'getValue') ? $end->getValue() : null;
            if ($endValue) {
                $endDt = new \DateTime($endValue);
                $minutesDuration = (int) (($endDt->getTimestamp() - $startDt->getTimestamp()) / 60);
            }
        }
        $pc_duration = max(1, $minutesDuration);

        $pc_catid = 1;
        $appointmentType = $fhirResource->getAppointmentType();
        if ($appointmentType && $appointmentType->getCoding()) {
            $coding = $appointmentType->getCoding()[0] ?? null;
            $code = $coding ? (string) $coding->getCode() : null;
            $display = $coding ? (string) $coding->getDisplay() : null;
            $categories = $this->appointmentService->getCalendarCategories();
            foreach ($categories as $cat) {
                if (($code && ($cat['pc_constant_id'] ?? '') === $code)
                    || ($display && stripos($cat['pc_catname'] ?? '', $display) !== false)) {
                    $pc_catid = (int) $cat['pc_catid'];
                    break;
                }
            }
        }

        $pc_apptstatus = '^';
        $statusEl = $fhirResource->getStatus();
        if ($statusEl && method_exists($statusEl, 'getValue')) {
            $status = (string) $statusEl->getValue();
            $pc_apptstatus = match ($status) {
                'booked' => '*',
                'proposed' => '-',
                'pending' => '^',
                'cancelled' => 'x',
                'fulfilled' => '$',
                'arrived' => '@',
                'checked-in' => '+',
                'noshow' => '?',
                'waitlist' => 'CALL',
                default => '^',
            };
        }

        $comment = $fhirResource->getComment();
        $pc_hometext = $comment && method_exists($comment, 'getValue') ? (string) $comment->getValue() : '';
        $description = $fhirResource->getDescription();
        $pc_title = $description && method_exists($description, 'getValue')
            ? (string) $description->getValue()
            : ('Appointment ' . $pc_eventDate);

        if (strlen($pc_title) < 2) {
            $pc_title = 'Appointment';
        }

        return [
            'pid' => $pid,
            'pc_catid' => $pc_catid,
            'pc_title' => $pc_title,
            'pc_duration' => $pc_duration,
            'pc_hometext' => $pc_hometext,
            'pc_apptstatus' => $pc_apptstatus,
            'pc_eventDate' => $pc_eventDate,
            'pc_startTime' => $pc_startTime,
            'pc_facility' => $pc_facility,
            'pc_billing_location' => $pc_billing_location,
            'pc_aid' => $pc_aid,
        ];
    }

    /**
     * Inserts an OpenEMR record into the system.
     *
     * @param array $openEmrRecord OpenEMR appointment record
     * @return ProcessingResult
     */
    protected function insertOpenEMRRecord($openEmrRecord)
    {
        $processingResult = new ProcessingResult();
        $pid = $openEmrRecord['pid'] ?? null;
        if (empty($pid)) {
            $processingResult->addValidationMessage('pid', 'Patient is required');
            return $processingResult;
        }

        unset($openEmrRecord['pid']);
        $pc_eid = $this->appointmentService->insert($pid, $openEmrRecord);

        if (empty($pc_eid)) {
            $processingResult->addInternalError('Failed to insert appointment');
            return $processingResult;
        }

        $created = $this->appointmentService->getAppointment($pc_eid);
        if (!empty($created) && isset($created[0])) {
            $record = $created[0];
            $fhirResource = $this->parseOpenEMRRecord($record);
            $processingResult->addData($fhirResource);
        }

        return $processingResult;
    }
}
