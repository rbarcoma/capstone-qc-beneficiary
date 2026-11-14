<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class UsersAccessController extends Controller
{
    /**
     * Render the Admin Users & Access page (Figma frame `2024:2179`).
     *
     * The frame is a reference page: the five operational roles with their
     * proposal status (Existing / Proposed, proposal §6.1 Table 3) and the
     * role-based permission matrix (frame content; the wider matrix in
     * docs/ROLES_AND_PERMISSIONS.md §2 remains proposed and requires City
     * Hall confirmation before it is locked in).
     *
     * User CRUD / role assignment UI is not part of this frame and is not
     * implemented here.
     */
    public function index(Request $request): Response
    {
        return Inertia::render('users-access', [
            'roles' => [
                ['name' => 'Admin', 'status' => 'Existing'],
                ['name' => 'Viewer / Executive', 'status' => 'Existing'],
                ['name' => 'Energy Manager', 'status' => 'Proposed'],
                ['name' => 'Facilities / Budget', 'status' => 'Proposed'],
            ],
            'matrix' => [
                ['capability' => 'Upload monthly data', 'admin' => 'Yes', 'energyManager' => 'Yes', 'facilities' => 'Assigned', 'budgetViewer' => 'No'],
                ['capability' => 'Verify data', 'admin' => 'Yes', 'energyManager' => 'Yes', 'facilities' => 'No', 'budgetViewer' => 'No'],
                ['capability' => 'Run next forecast', 'admin' => 'Yes', 'energyManager' => 'Yes', 'facilities' => 'No', 'budgetViewer' => 'No'],
                ['capability' => 'Decide recommendation', 'admin' => 'No', 'energyManager' => 'Yes', 'facilities' => 'No', 'budgetViewer' => 'No'],
                ['capability' => 'Set targets / tariffs', 'admin' => 'Yes', 'energyManager' => 'Yes', 'facilities' => 'No', 'budgetViewer' => 'No'],
                ['capability' => 'Download reports', 'admin' => 'Yes', 'energyManager' => 'Yes', 'facilities' => 'Own', 'budgetViewer' => 'Yes'],
                ['capability' => 'Audit trail', 'admin' => 'Yes', 'energyManager' => 'No', 'facilities' => 'No', 'budgetViewer' => 'No'],
            ],
        ]);
    }
}
