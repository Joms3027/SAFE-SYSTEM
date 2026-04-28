<?php
/**
 * Options for the Travel Activity Request Form (Google Form 3.2 / DISAPP 2.2D).
 */
if (!function_exists('tarf_get_form_options')) {
    function tarf_get_form_options(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $colleges = [
            'Office of the University President',
            'Office of the VP for Administration and Finance',
            'Office of the VP for Academic Affairs',
            'Office of the VP for Research, Innovation, Development, and Extension',
            'Office of the VP for Student Affairs and Services',
            'Office of the University Board Secretary',
            'Public Information Office',
            'University Legal Office',
            'Planning Office',
            'Quality Assurance Office',
            'Internal Audit Office',
            'Internalization and External Affairs Office',
            'Project Management Office',
            'Gender and Development Office',
            'Office of the Presidential Assistant for Special Projects',
            'Office of the Presidential Assistant for Security',
            'College of Arts and Sciences',
            'College of Agriculture, Forestry, and Environmental Sciences',
            'College of Criminal Justice Education',
            'College of Engineering and Technology',
            'College of Education',
            'College of Fisheries and Natural Sciences',
            'College of Public Administration and Management',
            'Curriculum and Instruction Office',
            'University Library',
            'National Service Training Program Office',
            'Office of the Chief Administrative Officer',
            'Business Affairs Office',
            'Accounting Office',
            'Budget Office',
            'Cash Office',
            'Records Office',
            'Human Resource Management and Development Office',
            'Procurement Office',
            'Supply and Property Management Office',
            'General Services Office',
            'Information and Communication Technology Office',
            'Research and Development Office',
            'Extension Services',
            'Intellectual Property Office',
            'Technology Management and Commercialization Office',
            'Research Ethics Office',
            'Student Welfare Services Office',
            'Student Development Services Office',
            'Student Institutional Programs Services Office',
            'Admission and Registration Services Office',
            'Holistic Development Programs Office',
            'Health Services Office',
            'Office for Cultural Inclusivity',
            'Puerto Princesa City Campus',
            'El Nido Campus',
            'Canique Campus',
            'Quezon Campus',
            'Rio Tuba Campus',
            'Culion Campus',
            'Busuanga Campus',
            'Agricultural Science High School',
            'BELS / ASHS',
            'Office of Business Affairs',
            'Malampaya String of Pearls Project',
            'Inspectorate Team',
            'WPU-Intellectual Property Technology and Business Management',
            'NIHR Global CFaH-Philippines',
            'WPU-ATBI',
            'Agritourism Showcase and Leisure Park',
            'NAPO',
            'WPU Housing Committee',
            'WPU Multi Campus Faculty Association',
            'CEPHPal PROJECT',
            'PMES',
            'NBreed Project',
            'WPU Banana TechComm Project',
            'MARINA-Pal Project',
            'EMERGE-IP Project',
            'PANACEA Project',
            'WPU-IPTBM',
            'ICEN-AQUA (Travelling expenses Charged to RIDE)',
            'ICEN-AQUA',
            'BAC',
            'ASHS',
            'The Palawan Scientist',
            'MCFA',
            'HATCH TBI',
            'GleanPhil Program (CFINS Project)',
            'SUPREME STUDENT COUNCIL - MAIN CAMPUS',
            'SUPREME STUDENT COUNCIL',
            'WPU - CPD Research Project',
            'Commission on Student Election',
            'Infrastructure Planning and Design unit',
        ];

        $travelPurposeTypes = [
            'Extension',
            'Representation of University in Committee/Council/Board/Technical Working Group',
            'Attendance in Conference/Symposium/Seminar',
            'Invited as Resource Speaker in Academic Fora/Thesis Panels, etc.',
            'Tasks as Part of Duties of the Position/Office/Project',
            'Travel Involving Students',
        ];

        $endorsers = [
            'Dr. Romeo R. Lerom, VPAA',
            'Dr. Ria S. Sariego, VPAF',
            'Dr. Lota A. Creencia, VPRIDE',
            'Dr. Lita B. Sopsop, VPSAS',
            'Dr. Amabel S. Liao, University President',
            'Jomari B. Recalde',
        ];

        $fundingCharged = [
            'Fund 101',
            'Fund 164',
            'Fund 184',
        ];

        $fundingSpecifiers = [
            'GASS',
            'Auxiliary',
            'Higher Ed',
            'Advanced Ed',
            'Research',
            'Extension',
            'Fiduciary',
            'CAFES OJT Fund',
            'CPAM TRAINING FUND',
            'CPAM OJT FUND MAIN CAMPUS',
            'NIHR Global CFaH-Philippines',
            'EMERGE-IP',
            'PANACEA-Project',
            'Fund 164',
            'CEPHPal Project',
            'CAS OJT Fund (PPC)',
            'CED OJT Fund',
            'WPU-ATBI',
            'ICTO',
        ];

        $publicitySupport = [
            'na' => 'N/A',
            'activity_coverage' => 'Activity Coverage and Publication (Please include 1 Info Staff in Travel Arrangements)',
            'publication_web' => 'Publication in the University Website and Social Media',
            'president_message' => 'Presence/Message from the President',
        ];

        $travelSupport = [
            'approval_travel' => 'Approval of Travel',
            'traveling_expenses' => 'Traveling Expenses',
            'per_diem' => 'Per Diem',
            'vehicle_free' => 'Free - Use of University Vehicle',
            'vehicle_fees' => 'With Fees - Use of University Vehicle',
            'registration_fees' => 'Registration Fees',
            'other_budgetary' => 'Other Budgetary Requirements',
            'fuel_project' => 'Fuel charged to project',
            'office_order' => 'Office Order',
            'catering' => 'Catering Services',
        ];

        $fundEndorserRole = [
            'budget_101_164' => 'Budget (Fund 101, Fund 164)',
            'accounting_184' => 'Accounting (Fund 184)',
        ];

        $cache = [
            'colleges' => $colleges,
            'travel_purpose_types' => $travelPurposeTypes,
            'endorsers' => $endorsers,
            'funding_charged' => $fundingCharged,
            'funding_specifiers' => $fundingSpecifiers,
            'publicity_support' => $publicitySupport,
            'travel_support' => $travelSupport,
            'fund_endorser_role' => $fundEndorserRole,
            'cos_jo_options' => [
                'no' => 'No',
                'yes_certify' => 'Yes, and I hereby certify that this task requiring travel cannot be accomplished by other personnel/this travel is part of the duties under the contract of this personnel',
            ],
            'travel_request_type' => [
                'official_business' => 'Official Business',
                'official_time' => 'Official Time',
            ],
            'university_funding' => [
                'yes' => 'Yes',
                'no' => 'No',
            ],
        ];

        return $cache;
    }
}

if (!function_exists('tarf_travel_person_role_label')) {
    /**
     * Position / designation text for TARF “person/s to travel (position)”.
     *
     * @param array<string, mixed> $row faculty_profiles + users (include user_type for fallback)
     */
    function tarf_travel_person_role_label(array $row): string
    {
        $d = trim((string) ($row['designation'] ?? ''));
        $p = trim((string) ($row['position'] ?? ''));
        if ($d !== '') {
            return $d;
        }
        if ($p !== '') {
            return $p;
        }
        $ut = strtolower(trim((string) ($row['user_type'] ?? '')));
        if ($ut === 'faculty') {
            return 'Faculty';
        }
        if ($ut === 'staff') {
            return 'Staff';
        }
        if ($ut !== '') {
            return ucfirst($ut);
        }

        return 'Employee';
    }
}

if (!function_exists('tarf_travel_person_display_line')) {
    /**
     * One line for stored persons_to_travel (name + role in parentheses).
     *
     * @param array<string, mixed> $row
     */
    function tarf_travel_person_display_line(array $row): string
    {
        $fn = trim((string) ($row['first_name'] ?? ''));
        $ln = trim((string) ($row['last_name'] ?? ''));
        $name = trim($fn . ' ' . $ln);
        if ($name === '') {
            $name = 'User #' . (int) ($row['user_id'] ?? 0);
        }
        return $name . ' (' . tarf_travel_person_role_label($row) . ')';
    }
}
