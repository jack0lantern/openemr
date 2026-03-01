<?php

/*
 * ServerScopeListEntityTest.php
 * @package openemr
 * @link      https://www.open-emr.org
 * @author    OpenEMR Contributors
 * @copyright Copyright (c) 2026 OpenEMR Contributors
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

namespace OpenEMR\Tests\Unit\Common\Auth\OpenIDConnect\Entities;

use OpenEMR\Common\Auth\OpenIDConnect\Entities\ServerScopeListEntity;
use PHPUnit\Framework\TestCase;

class ServerScopeListEntityTest extends TestCase
{
    public function testFhirResourceScopesV1IncludesAppointmentWriteForUserAndSystemWhenEnabled(): void
    {
        $entity = new ServerScopeListEntity();
        $entity->setSystemScopesEnabled(true);

        $scopes = $entity->fhirResourceScopesV1();

        $this->assertContains(
            "user/Appointment.write",
            $scopes,
            "V1 SMART scopes should include user/Appointment.write"
        );
        $this->assertContains(
            "system/Appointment.write",
            $scopes,
            "V1 SMART scopes should include system/Appointment.write when system scopes are enabled"
        );
    }

    public function testFhirResourceScopesV1IncludesAppointmentWriteForUserWhenSystemDisabled(): void
    {
        $entity = new ServerScopeListEntity();
        $entity->setSystemScopesEnabled(false);

        $scopes = $entity->fhirResourceScopesV1();

        $this->assertContains(
            "user/Appointment.write",
            $scopes,
            "V1 SMART scopes should include user/Appointment.write"
        );
        $this->assertNotContains(
            "system/Appointment.write",
            $scopes,
            "V1 SMART scopes should not include system/Appointment.write when system scopes are disabled"
        );
    }
}
