<?php

/*
 * FhirRoutesAppointmentTest.php
 * @package openemr
 * @link      https://www.open-emr.org
 * @author    OpenEMR Contributors
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Tests\Unit\RestControllers\FHIR;

use PHPUnit\Framework\TestCase;

class FhirRoutesAppointmentTest extends TestCase
{
    public function testRouteMapContainsPostAppointmentEndpoint(): void
    {
        $routes = require __DIR__ . '/../../../../../apis/routes/_rest_routes_fhir_r4_us_core_3_1_0.inc.php';

        $this->assertArrayHasKey(
            "POST /fhir/Appointment",
            $routes,
            "FHIR route map should register POST /fhir/Appointment"
        );
    }
}
