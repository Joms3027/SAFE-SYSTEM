<?php
/**
 * Choice lists for Google Form 3.1 [Non-Travel] Activity Request Form (WPU).
 * Loaded by ntarf_form_options.php only.
 */
if (!function_exists('ntarf_google_activity_campuses')) {
    function ntarf_google_activity_campuses(): array
    {
        return [
            'ABORLAN MAIN CAMPUS',
            'BUSUANGA CAMPUS',
            'CANIQUE CAMPUS',
            'CULION CAMPUS',
            'EL NIDO CAMPUS',
            'PUERTO PRINCESA CITY CAMPUS',
            'QUEZON CAMPUS',
            'RIO TUBA CAMPUS',
            'OUTSIDE THE CAMPUS',
        ];
    }
}

if (!function_exists('ntarf_google_venue_sites')) {
    /**
     * Single-select venue list (one venue per NTARF). Deduplicated; order follows official form.
     *
     * @return list<string>
     */
    function ntarf_google_venue_sites(): array
    {
        return [
            '(CAFES) CLASSROOM',
            '(CAFES) OFFICE',
            '(CAS) CLASSROOM',
            '(CAS) OFFICE',
            '(CCJE) CLASSROOM',
            '(CCJE) OFFICE',
            '(CCJE) OPEN GROUND AREA',
            '(CED) CLASSROOM',
            '(CED) OFFICE',
            '(CET) CLASSROOM',
            '(CET) OFFICE',
            '(CET) OPEN GROUND AREA',
            '(CFINS) CLASSROOM',
            '(CFINS) OFFICE',
            '(CPAM) CLASSROOM',
            '(CPAM) OFFICE',
            'ABBA BUILDING',
            'ABBA BUILDING CONFERENCE ROOM',
            'ACCOUNTING OFFICE',
            'ADMIN BLDG. CONFERENCE ROOM',
            'ADMISSION AND REGISTRATION SERVICES OFFICE',
            'ASL PARK (AGRITOURISM SHOWCASE AND LEISURE PARK)',
            'INSTRUCTIONAL MEDIA CENTER',
            'BELS / ASHS CLASSROOM',
            'BELS / ASHS OFFICE',
            'BELS / ASHS OPEN GROUND AREA',
            'BUDGET OFFICE',
            'BUSINESS AFFAIRS OFFICE',
            'CAFES AUDIO VISUAL HALL',
            'CAMPUS AUDIO VISUAL HALL',
            'CASH OFFICE',
            'CET AUDIO VISUAL HALL',
            'COLLEGE CONFERENCE ROOM',
            'COLLEGE LABORATORY',
            'COLLEGE LOBBY',
            'COLLEGE MULTIMEDIA HALL',
            'COLLEGE OPEN GROUND AREA',
            'COMPUTER LABORATORY ROOM',
            'COVERED COURT',
            'CPAM AUDIO VISUAL HALL',
            'CURRICULUM AND INSTRUCTION OFFICE',
            'EXECUTIVE HOUSE CONFERENCE ROOM',
            'EXECUTIVE HOUSE FUNCTION HALL',
            'EXECUTIVE HOUSE ROOM',
            'FACEBOOK LIVE',
            'FINNIGAN HALL',
            'FINNIGAN ROOM',
            'FOOD PROCESSING CENTER',
            'GENDER AND DEVELOPMENT OFFICE',
            'GENERAL SERVICES OFFICE',
            'GOOGLE MEET',
            'GRANDSTAND',
            'GYMNASIUM',
            'HATCHERY',
            'HEALTH SERVICES OFFICE',
            'HOLISTIC DEVELOPMENT PROGRAMS OFFICE',
            'HUMAN RESOURCE MANAGEMENT AND DEVELOPMENT OFFICE',
            'INFORMATION AND COMMUNICATION TECHNOLOGY OFFICE',
            'INTELLECTUAL PROPERTY OFFICE',
            'INTERNAL AUDIT OFFICE',
            'INTERNALIZATION AND EXTERNAL AFFAIRS OFFICE',
            'LIBRARY',
            'MANUEL BACOSA AMPHITHEATER',
            "MEN'S DORMITORY",
            'MULTIMEDIA HALL',
            'NAPO CANTEEN',
            'NATIONAL SERVICE TRAINING PROGRAM OFFICE',
            'OFFICE CONFERENCE ROOM',
            'OFFICE FOR CULTURAL INCLUSIVITY',
            'OFFICE OF THE CHIEF ADMINISTRATIVE OFFICER',
            'OFFICE OF THE PRESIDENTIAL ASSISTANT FOR SECURITY',
            'OFFICE OF THE PRESIDENTIAL ASSISTANT FOR SPECIAL PROJECTS',
            'OFFICE OF THE UNIVERSITY BOARD SECRETARY',
            'OFFICE OF THE UNIVERSITY PRESIDENT',
            'OFFICE OF THE VP FOR ACADEMIC AFFAIRS',
            'OFFICE OF THE VP FOR ADMINISTRATION AND FINANCE',
            'OFFICE OF THE VP FOR RESEARCH INNOVATION DEVELOPMENT AND EXTENSION',
            'OFFICE OF THE VP FOR STUDENT AFFAIRS AND SERVICES',
            'ONLINE',
            'OPEN GROUND AREA',
            'PALAO PARK',
            'PLANNING OFFICE',
            'PROCUREMENT OFFICE',
            'PROJECT MANAGEMENT OFFICE',
            'PUBLIC INFORMATION OFFICE',
            'QUADRANGLE SOCIAL WORK BUILDING',
            'QUALITY ASSURANCE OFFICE',
            'RECORDS OFFICE',
            'RESEARCH AND DEVELOPMENT OFFICE',
            'RESEARCH ETHICS OFFICE',
            'SPORTS COMPLEX',
            'SSC OFFICE',
            'STUDENT DEVELOPMENT SERVICES OFFICE',
            'STUDENT INSTITUTIONAL PROGRAMS SERVICES OFFICE',
            'STUDENT WELFARE SERVICES OFFICE',
            'SUPPLY AND PROPERTY MANAGEMENT OFFICE',
            'TECHNOLOGY MANAGEMENT AND COMMERCIALIZATION OFFICE',
            'TENTACLES PUBLICATION OFFICE',
            'TOWN HOUSE ROOM',
            'TRAINING CENTER',
            'UNIVERSITY LEGAL OFFICE',
            "WOMEN'S DORMITORY",
            "WOMEN'S DORMITORY FUNCTION HALL",
            'ZOOM',
        ];
    }
}

if (!function_exists('ntarf_google_endorser_venue_availability')) {
    /** @return list<string> */
    function ntarf_google_endorser_venue_availability(): array
    {
        return [
            'N/A',
            'WPU Gymnasium In-Charge',
            'WPU Training Center In-Charge',
            'WPU Sports Complex In-Charge',
            'Finnigan Room In-Charge',
            'Finnigan Hall In-Charge',
            'PPC Campus Director',
            'PPC Conference room In-Charge',
            'PPC Guest House In-Charge',
            'PPC Function Hall In-Charge',
            'QC Guest House In-Charge',
        ];
    }
}

if (!function_exists('ntarf_google_endorser_electricity')) {
    /** @return list<string> */
    function ntarf_google_endorser_electricity(): array
    {
        return [
            'N/A',
            'PMES Supervisor',
        ];
    }
}
