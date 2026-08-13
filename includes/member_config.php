<?php

function getMemberTypeConfig(): array
{
    return [
        'student' => [
            'label' => 'Student',
            'id_label' => 'Registration Number',
            'guardian_label' => "Father's Name",
            'joined_label' => 'Joined Date',
            'fields' => ['guardian_name', 'class'],
            'required' => ['guardian_name', 'class']
        ],
        'employee' => [
            'label' => 'Employee',
            'id_label' => 'Employee ID',
            'guardian_label' => 'Guardian Name',
            'joined_label' => 'Joined Date',
            'fields' => ['department', 'designation'],
            'required' => ['department', 'designation']
        ],
        'staff' => [
            'label' => 'Staff',
            'id_label' => 'Staff ID',
            'guardian_label' => 'Guardian Name',
            'joined_label' => 'Joined Date',
            'fields' => ['department', 'designation'],
            'required' => ['department', 'designation']
        ],
        'faculty' => [
            'label' => 'Faculty',
            'id_label' => 'Faculty ID',
            'guardian_label' => 'Guardian Name',
            'joined_label' => 'Joined Date',
            'fields' => ['department', 'designation'],
            'required' => ['department', 'designation']
        ],
        'visitor' => [
            'label' => 'Visitor',
            'id_label' => 'Visitor ID',
            'guardian_label' => 'Contact Person',
            'joined_label' => 'Visit Date',
            'fields' => ['company', 'purpose'],
            'required' => ['company', 'purpose']
        ],
        'office' => [
            'label' => 'Office Member',
            'id_label' => 'Office ID',
            'guardian_label' => 'Guardian Name',
            'joined_label' => 'Joined Date',
            'fields' => ['department', 'designation'],
            'required' => ['department', 'designation']
        ]
    ];
}

function normalizeMemberType(string $memberType): string
{
    $config = getMemberTypeConfig();
    return array_key_exists($memberType, $config) ? $memberType : 'student';
}

function getMemberTypeOptions(): array
{
    $options = [];
    foreach (getMemberTypeConfig() as $key => $value) {
        $options[$key] = $value['label'];
    }
    return $options;
}

function getMemberRequiredFields(string $memberType): array
{
    $config = getMemberTypeConfig();
    return $config[$memberType]['required'] ?? [];
}
