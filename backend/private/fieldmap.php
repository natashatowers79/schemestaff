<?php
/**
 * Scheme Staff — form label → database column mapping.
 *
 * script.js derives its JSON keys from the visible label text (see fieldKey()
 * in script.js): the asterisk and "(optional)"-style suffixes are stripped,
 * whitespace collapsed, and fields inside a certificate or compliance block get
 * their block title prefixed with an em dash. Radio groups and named toggles use
 * the input's `name` attribute with the first letter capitalised instead — which
 * is why "ppra_required" arrives as "Ppra required", odd capitals and all.
 *
 * THIS IS THE FRAGILE JOINT. Editing a label on the website silently renames the
 * key arriving here. When that happens the projected column goes null — the raw
 * submission is still complete in `submissions.payload`, so nothing is lost, but
 * matching stops seeing the field. If you change a label, change it here too.
 */

/** Which table each form's projection lands in. Keys are script.js FORM_TYPES values. */
const FORM_TABLES = [
    'Candidates'            => 'candidates',
    'Employers'             => 'employers',
    'Job postings'          => 'job_postings',
    'Availability postings' => 'availability_postings',
    'Contact messages'      => 'contact_messages',
];

/** Columns to coerce to a DATE (empty string becomes NULL rather than 0000-00-00). */
const DATE_COLUMNS = [
    'date_of_birth', 'available_from', 'available_to', 'start_date', 'end_date',
];

/** Columns to coerce to 0/1 from the "Yes"/"No" that toggles send. */
const BOOL_COLUMNS = [
    'ppra_required', 'credit_required', 'csos_required',
];

const FIELD_MAP = [

    'Candidates' => [
        'Full name'                       => 'full_name',
        'ID / passport number'            => 'id_number',
        'Date of birth'                   => 'date_of_birth',
        'Contact number'                  => 'phone',
        'Email address'                   => 'email',
        'Address'                         => 'address',
        'Highest level of education'      => 'education',
        'Current / most recent job title' => 'current_title',
        'Employer name'                   => 'current_employer',
        'Years of industry experience'    => 'years_experience',
        'Reason for leaving'              => 'reason_for_leaving',
        'Employment type sought'          => 'employment_types',
        'Work arrangement preference'     => 'work_arrangements',
        'Open to'                         => 'open_to',
        'Own transport'                   => 'own_transport',
        'Primary role / trade'            => 'primary_role',
        'Additional skills'               => 'skills',
        'Specialisations'                 => 'specialisations',
        'PPRA registration number'        => 'ppra_number',
        'Current / most recent salary'    => 'current_salary',
        'Salary expectation (monthly band)' => 'salary_expectation',
        'Available from'                  => 'available_from',
        'Recurring availability'          => 'recurring_availability',
        'Notice period'                   => 'notice_period',
        'Notice period type'              => 'notice_period_type',
        'Availability status'             => 'availability_status',
        'Preferred work location(s)'      => 'locations',
        'Maximum travel radius'           => 'travel_radius',
        'Willing to relocate'             => 'willing_to_relocate',
        'Login email address'             => 'login_email',
    ],

    'Employers' => [
        'Company name'              => 'company_name',
        'CIPC registration number'  => 'cipc_number',
        'VAT number'                => 'vat_number',
        'Website'                   => 'website',
        'Facebook'                  => 'facebook',
        'LinkedIn'                  => 'linkedin',
        'Full name'                 => 'contact_name',
        'Job title'                 => 'contact_title',
        'Email address'             => 'contact_email',
        'Phone number'              => 'contact_phone',
        'Address'                   => 'address',
        'Sectional Title units managed' => 'st_units',
        'HOA properties managed'    => 'hoa_properties',
        'Shareblock units managed'  => 'shareblock_units',
        'Portfolio type'            => 'portfolio_type',
        // The PPRA number sits inside a compliance block, so it arrives prefixed.
        // Both spellings are mapped in case the block is ever restructured.
        'PPRA registration — PPRA registration number' => 'ppra_number',
        'PPRA registration number'  => 'ppra_number',
        'Types of roles you typically hire for' => 'roles_hired',
        'Typical rate ranges'       => 'rate_ranges',
        'Subscription'              => 'subscription',
        'Login email address'       => 'login_email',
    ],

    'Job postings' => [
        'Job title'                  => 'job_title',
        'Province'                   => 'province',
        'Location (city / suburb)'   => 'location',
        'Minimum experience level'   => 'min_experience',
        'Employment type'            => 'employment_type',
        'Work arrangement'           => 'work_arrangement',
        'Hours'                      => 'hours',
        'Schedule details'           => 'schedule_details',
        'Start date'                 => 'start_date',
        'End date'                   => 'end_date',
        'Rate offered'               => 'rate_offered',
        'Monthly salary band (for ongoing roles)' => 'salary_band',
        'Requirements for this role' => 'requirements',
        'Skills required'            => 'skills_required',
        'Software / systems experience required' => 'software_required',
        'Legislation knowledge required' => 'legislation_required',
        'Role description'           => 'role_description',
        'Specific certifications or requirements' => 'certifications',
        'Ppra required'              => 'ppra_required',
        'Credit required'            => 'credit_required',
        'Csos required'              => 'csos_required',
        'Working environment'        => 'offer_environment',
        'Growth & development opportunities' => 'offer_growth',
        'Benefits'                   => 'offer_benefits',
    ],

    'Availability postings' => [
        'Available from'             => 'available_from',
        'Available to (leave blank if ongoing)' => 'available_to',
        'Available to'               => 'available_to',
        'Work type'                  => 'work_type',
        'Recurring schedule'         => 'recurring_schedule',
        'Preferred work location(s)' => 'locations',
        'Maximum travel radius'      => 'travel_radius',
        'Travel further'             => 'travel_further',
        'Preferred role types'       => 'preferred_roles',
        'Open to other'              => 'open_to_other',
        'Rate expectation'           => 'rate_expectation',
        'Negotiable'                 => 'negotiable',
    ],

    'Contact messages' => [
        'Your name'     => 'name',
        'Email address' => 'email',
        'Phone number'  => 'phone',
        'I am...'       => 'i_am',
        'Message'       => 'message',
    ],
];

/**
 * Project a submission's fields onto the columns of its form's table.
 * Unmapped keys are simply skipped — they remain in submissions.payload.
 */
function project_fields(string $formType, array $fields): array {
    // array_key_exists, not isset() — isset() accepts only variables, and calling
    // it on a constant array is a fatal error rather than a false.
    if (!array_key_exists($formType, FIELD_MAP)) {
        return [];
    }
    $map = FIELD_MAP[$formType];
    $row = [];

    foreach ($fields as $label => $value) {
        if (!isset($map[$label])) {
            continue;
        }
        $column = $map[$label];
        $value  = is_string($value) ? trim($value) : $value;

        if (in_array($column, BOOL_COLUMNS, true)) {
            $row[$column] = (strcasecmp((string) $value, 'yes') === 0) ? 1 : 0;
            continue;
        }
        if (in_array($column, DATE_COLUMNS, true)) {
            // Date inputs send YYYY-MM-DD; anything else (including blank) is null.
            $row[$column] = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value)
                ? $value
                : null;
            continue;
        }
        // Don't let an earlier blank overwrite a later real value (e.g. "Address"
        // appears in more than one section on some forms).
        if (isset($row[$column]) && $row[$column] !== '' && $value === '') {
            continue;
        }
        $row[$column] = ($value === '') ? null : $value;
    }

    return $row;
}
