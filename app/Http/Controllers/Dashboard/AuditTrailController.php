<?php

namespace App\Http\Controllers\Dashboard;

use App\Crud\AuditTrailCrud;
use App\Http\Controllers\DashboardController;

/**
 * ตรวจสอบการใช้งานย้อนหลัง.
 *
 * Its own page and its own controller rather than a tab on the users screen: it is read by a
 * different person for a different reason — the owner checking what happened, not an administrator
 * managing accounts — and it is guarded by its own permission.
 */
class AuditTrailController extends DashboardController
{
    public function index()
    {
        /**
         * Guarded here as well as by the CRUD's read permission. The page route is what somebody
         * types or bookmarks, and every other dashboard page in this installation that relies on
         * the menu alone to hide itself is reachable by URL.
         */
        ns()->restrict( 'nexopos.read.audit-trail' );

        return AuditTrailCrud::table();
    }
}
