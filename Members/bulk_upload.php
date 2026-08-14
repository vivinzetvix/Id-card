<?php
// Keep binary sample downloads free of incidental PHP output.
ob_start();
ini_set('display_errors', '0');
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../vendor/autoload.php';

$page_title = 'Bulk Upload Members';
require_login();

// Get current user info
$currentUser = $_SESSION['user'] ?? [];
$userOrgId = $_SESSION['organization_id'] ?? 0;
$userRole = $_SESSION['role'] ?? '';
$isSuperAdmin = ($userRole === 'Super Admin' || $userRole === 'super_admin' || $userRole === 'admin');
$selectedOrgId = $isSuperAdmin ? (int) ($_GET['organization_id'] ?? $_POST['organization_id'] ?? 0) : (int) $userOrgId;

// Get organizations (for super admin)
$organizations = [];
if ($isSuperAdmin) {
    $organizations = $pdo->query("SELECT id, organization_name, project_type, status FROM organizations WHERE deleted_at IS NULL AND status = 1 ORDER BY organization_name")->fetchAll(PDO::FETCH_ASSOC);
}

// Get templates
$templates = [];
$templateSql = "SELECT id, name, orientation, is_default FROM card_templates WHERE status = 1 AND deleted_at IS NULL ";
$params = [];

if ($selectedOrgId > 0) {
    $templateSql .= " AND organization_id = ?";
    $params[] = $selectedOrgId;
} else {
    $templateSql .= " AND 1 = 0";
}

$templateSql .= " ORDER BY is_default DESC, name";
$stmt = $pdo->prepare($templateSql);
$stmt->execute($params);
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Default member kind stays compatible with the legacy column, but imports now rely on the chosen template.
$memberTypes = ['student', 'employee', 'staff', 'faculty', 'visitor', 'office'];

// Template-specific Excel sample. The selected organization and template are
// required so one file can never be used to import across organizations.
if (isset($_GET['download_sample'])) {
    $sampleOrgId = $selectedOrgId;
    $sampleTemplateId = (int) ($_GET['template_id'] ?? 0);
    $check = $pdo->prepare('SELECT id, name FROM card_templates WHERE id = ? AND organization_id = ? AND status = 1 AND deleted_at IS NULL');
    $check->execute([$sampleTemplateId, $sampleOrgId]);
    $sampleTemplate = $check->fetch(PDO::FETCH_ASSOC);
    if ($sampleOrgId <= 0 || !$sampleTemplate) {
        http_response_code(422);
        exit('Select a valid organization and one of its templates before downloading the sample Excel file.');
    }

    require_once __DIR__ . '/../template/template_mgmt_helpers.php';
    $activeKeys = template_get_active_field_keys($pdo, $sampleTemplateId);
    $headers = [];
    $secondary = get_active_languages($pdo, 2)[1]['language_code'] ?? 'secondary';

    // Core minimum fields required for member creation
    $coreBase = ['unique_id', 'name'];
    foreach ($coreBase as $cb) {
        if (!in_array($cb, $headers, true)) {
            $headers[] = $cb;
        }
    }

    // Add all active fields for the selected template
// Core fields required for every member
    $headers = ['unique_id', 'name'];

    // System-managed fields must NEVER appear in Excel
    $systemFields = [
        'member_type',
        'organization_id',
        'organization_name',
        'template_id',
        'language'
    ];

    // Add only active template fields
    foreach ($activeKeys as $key) {
        $key = (string) $key;

        if (
            $key === '' ||
            in_array($key, $headers, true) ||
            in_array($key, $systemFields, true)
        ) {
            continue;
        }

        $headers[] = $key;
    }
    // Also include template dynamic input fields if active
// Add only fields actually placed and visible on the selected template
    foreach ($activeKeys as $key) {
        $key = trim((string) $key);

        if (
            $key === '' ||
            in_array($key, $headers, true) ||
            in_array($key, $systemFields, true)
        ) {
            continue;
        }

        $headers[] = $key;
    }
    /*
     * Professional Excel sample:
     * - Date columns use DD-MM-YYYY formatting and Excel date validation.
     * - Photo is a dedicated column. Excel cells cannot contain a native
     *   local-file upload button, so the web form provides multi-photo upload.
     */
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle('Members');

    // Always expose Photo as a standard import column.
// Add Photo only if it exists in the selected template
    if (in_array('photo', $activeKeys, true) && !in_array('photo', $headers, true)) {
        $headers[] = 'photo';
    }

    $sheet->fromArray($headers, null, 'A1');

    $dateFieldKeys = ['dob', 'joined_date', 'expiry_date'];
    foreach (get_template_input_fields($pdo, $sampleTemplateId) as $field) {
        if (strtolower((string) ($field['field_type'] ?? '')) === 'date') {
            $dateFieldKeys[] = (string) $field['field_key'];
        }
    }
    $dateFieldKeys = array_values(array_unique($dateFieldKeys));

    $headerColumnMap = [];
    foreach ($headers as $index => $header) {
        $headerColumnMap[strtolower((string) $header)] = $index + 1;
    }

    // One clearly marked example row. Users can replace it or delete it.
    $exampleRow = array_fill(0, count($headers), '');
    if (isset($headerColumnMap['unique_id'])) {
        $exampleRow[$headerColumnMap['unique_id'] - 1] = 'MEM20260001';
    }
    if (isset($headerColumnMap['name'])) {
        $exampleRow[$headerColumnMap['name'] - 1] = 'Example Member';
    }
    if (isset($headerColumnMap['dob'])) {
        $exampleRow[$headerColumnMap['dob'] - 1] = '15-08-2000';
    }
    if (isset($headerColumnMap['joined_date'])) {
        $exampleRow[$headerColumnMap['joined_date'] - 1] = '01-08-2026';
    }
    if (isset($headerColumnMap['photo'])) {
        $exampleRow[$headerColumnMap['photo'] - 1] = 'MEM20260001.jpg';
    }
    $sheet->fromArray($exampleRow, null, 'A2');

    $sheet->getStyle('A1:' . $sheet->getHighestColumn() . '1')->getFont()->setBold(true);
    $sheet->freezePane('A2');
    $sheet->setAutoFilter('A1:' . $sheet->getHighestColumn() . '1');

    // Date fields: DD-MM-YYYY + validation for the first 500 rows.
    foreach ($dateFieldKeys as $dateKey) {
        $columnNumber = $headerColumnMap[strtolower($dateKey)] ?? null;
        if (!$columnNumber) {
            continue;
        }

        $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnNumber);

        $sheet->getStyle($columnLetter . '2:' . $columnLetter . '501')
            ->getNumberFormat()
            ->setFormatCode('dd-mm-yyyy');

        $validation = new \PhpOffice\PhpSpreadsheet\Cell\DataValidation();
        $validation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_DATE);
        $validation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
        $validation->setAllowBlank(true);
        $validation->setShowInputMessage(true);
        $validation->setShowErrorMessage(true);
        $validation->setErrorTitle('Invalid date');
        $validation->setError('Please enter a valid date in DD-MM-YYYY format.');
        $validation->setPromptTitle('Date');
        $validation->setPrompt('Enter a date in DD-MM-YYYY format.');
        $validation->setOperator(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::OPERATOR_BETWEEN);
        $validation->setFormula1('DATE(1900,1,1)');
        $validation->setFormula2('DATE(2100,12,31)');

        for ($row = 2; $row <= 501; $row++) {
            $sheet->getCell($columnLetter . $row)->setDataValidation(clone $validation);
        }
    }

    // Photo column instruction.
    if (isset($headerColumnMap['photo'])) {
        $photoColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($headerColumnMap['photo']);
        $sheet->getComment($photoColumn . '1')->getText()->createText(
            'Upload photos from the Bulk Upload page. Recommended filename: same as Unique ID (example: MEM20260001.jpg).'
        );
        $sheet->getStyle($photoColumn . '2:' . $photoColumn . '501')
            ->getNumberFormat()
            ->setFormatCode('@');
    }

    foreach ($headers as $columnIndex => $header) {
        $columnLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($columnIndex + 1);
        $sheet->getColumnDimension($columnLetter)->setAutoSize(true);
    }

    // Separate instructions sheet keeps the import workflow clear.
    $instructions = $spreadsheet->createSheet();
    $instructions->setTitle('Instructions');
    $instructions->fromArray([
        ['Bulk Member Import – Instructions'],
        ['1', 'Fill one member per row in the Members sheet.'],
        ['2', 'Do not change the column names.'],
        ['3', 'Date fields use DD-MM-YYYY. Validation is enabled for the first 500 rows.'],
        ['4', 'Excel cells cannot provide a native local-file upload button. Use the Photo Upload section on the Bulk Upload page.'],
        ['5', 'Recommended photo filename: exactly the same as unique_id, for example MEM20260001.jpg.'],
        ['6', 'Supported photo formats: JPG, JPEG, PNG, GIF and WEBP.'],
        ['7', 'Leave optional fields blank when not applicable.'],
        ['8', 'This sample belongs only to the selected Organization + Template.']
    ], null, 'A1');
    $instructions->getStyle('A1:B1')->getFont()->setBold(true);
    $instructions->getColumnDimension('A')->setWidth(6);
    $instructions->getColumnDimension('B')->setWidth(100);
    $instructions->getStyle('A1:B9')->getAlignment()->setWrapText(true);

    $spreadsheet->setActiveSheetIndex(0);
    // XLSX is a binary ZIP stream. Any warning, BOM, or buffered markup before
    // it corrupts the file and makes Excel report an invalid format.
    ini_set('display_errors', '0');
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="members_' . $sampleTemplateId . '_sample.xlsx"');
    header('Cache-Control: max-age=0, must-revalidate');
    (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
    exit();
}

// Handle form submission - Step 5 Import
$errors = [];
$success = false;
$uploadedCount = 0;
$failedCount = 0;
$failedRows = [];
$importedData = [];
$previewData = [];
$showPreview = false;
$importStep = 'form'; // form, preview, import
$bulkPhotoUploads = $_SESSION['bulk_photo_uploads'] ?? [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF check
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Invalid security token. Please refresh the page.';
    } else {
        // Check if this is the preview step or import step
        $action = $_POST['action'] ?? 'preview';

        // Get form data
        $orgId = $isSuperAdmin
            ? (int) ($_POST['organization_id'] ?? 0)
            : (int) $userOrgId;
        $templateId = (int) ($_POST['template_id'] ?? 0);
        $memberType = $_POST['member_type'] ?? 'student';
        $defaultExpiry = $_POST['default_expiry'] ?? null;
        $skipDuplicates = isset($_POST['skip_duplicates']) ? true : false;
        $sendNotifications = isset($_POST['send_notifications']) ? true : false;

        if ($orgId <= 0) {
            $errors[] = 'Select an organization before importing.';
        }
        if ($templateId <= 0) {
            $errors[] = 'Select a template before importing.';
        } elseif ($orgId > 0) {
            $templateCheck = $pdo->prepare('SELECT id FROM card_templates WHERE id = ? AND organization_id = ? AND status = 1 AND deleted_at IS NULL');
            $templateCheck->execute([$templateId, $orgId]);
            if (!$templateCheck->fetchColumn()) {
                $errors[] = 'The selected template does not belong to the selected organization.';
            }
        }

        // The Excel file is required only for the PREVIEW step.
        // The CONFIRM IMPORT form intentionally does not re-upload the file;
        // its parsed data is already stored in $_SESSION['bulk_import_data'].
        if ($action === 'preview') {
            if (!isset($_FILES['csv_file'])) {
                $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
                $postMaxSize = ini_get('post_max_size');

                if ($contentLength > 0 && empty($_POST) && empty($_FILES)) {
                    $errors[] = 'Upload data was rejected by PHP. Current post_max_size is ' . $postMaxSize . '. Increase post_max_size/upload_max_filesize on the server.';
                } else {
                    $errors[] = 'Excel file was not received by the server.';
                }
            } elseif ($_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
                $uploadError = $_FILES['csv_file']['error'];

                $uploadErrors = [
                    UPLOAD_ERR_INI_SIZE   => 'The uploaded file exceeds the PHP upload_max_filesize limit.',
                    UPLOAD_ERR_FORM_SIZE  => 'The uploaded file exceeds the form size limit.',
                    UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded.',
                    UPLOAD_ERR_NO_FILE    => 'No Excel or CSV file was selected.',
                    UPLOAD_ERR_NO_TMP_DIR => 'PHP temporary upload folder is missing.',
                    UPLOAD_ERR_CANT_WRITE => 'PHP could not write the uploaded file.',
                    UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the file upload.'
                ];

                $errors[] = $uploadErrors[$uploadError]
                    ?? 'Unknown file upload error. Error code: ' . $uploadError;
            } else {
                $file = $_FILES['csv_file'];
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

                $allowed = ['csv', 'xlsx', 'xls'];
                if (!in_array($ext, $allowed, true)) {
                    $errors[] = 'Please upload a CSV, XLSX, or XLS file.';
                }

                if ((int) $file['size'] > 10 * 1024 * 1024) {
                    $errors[] = 'File size exceeds 10MB limit.';
                }
            }

            if (empty($errors)) {
                try {
                    $filePath = $file['tmp_name'];
                    $data = $ext === 'csv' ? parseCSVFile($filePath) : parseExcelFile($filePath);
                } catch (Throwable $e) {
                    $errors[] = 'Error reading file: ' . $e->getMessage();
                }
            }
        }

        // Optional photo batch upload. Files are stored temporarily until
        // the user confirms the import after preview.
        if (empty($errors) && $action === 'preview' && !empty($_FILES['photo_files']['name'][0])) {
            try {
                $bulkPhotoUploads = save_bulk_photo_uploads();
                if (!empty($bulkPhotoUploads)) {
                    $_SESSION['bulk_photo_uploads'] = $bulkPhotoUploads;
                }
            } catch (Throwable $e) {
                $errors[] = 'Photo upload error: ' . $e->getMessage();
            }
        }

        // Process data if no errors
        if (empty($errors) && !empty($data)) {
            $headers = array_map(static fn($header) => strtolower(trim((string) $header)), array_shift($data));

            // Validate required core headers
            $requiredHeaders = ['unique_id', 'name'];

            // Also check template-specific required fields
            if ($templateId > 0) {
                $tplFields = get_template_input_fields($pdo, $templateId);
                foreach ($tplFields as $tf) {
                    if ((int) ($tf['is_required'] ?? 0)) {
                        $fKey = strtolower((string) $tf['field_key']);
                        if (!in_array($fKey, $requiredHeaders, true)) {
                            $requiredHeaders[] = $fKey;
                        }
                    }
                }
            }

            $missingHeaders = array_diff($requiredHeaders, $headers);
            if (!empty($missingHeaders)) {
                $errors[] = 'Missing required columns for selected template: ' . implode(', ', $missingHeaders);
            }
        }

        // If action is preview, show preview
        if ($action === 'preview' && empty($errors) && !empty($data) && !empty($headers)) {
            $showPreview = true;
            $importStep = 'preview';

            // Store data in session for import step
            $_SESSION['bulk_import_data'] = [
                'data' => $data,
                'headers' => $headers,
                'orgId' => $orgId,
                'templateId' => $templateId,
                'memberType' => $memberType,
                'defaultExpiry' => $defaultExpiry,
                'skipDuplicates' => $skipDuplicates,
                'sendNotifications' => $sendNotifications,
                'photoUploads' => $_SESSION['bulk_photo_uploads'] ?? []
            ];

            // Pre-fetch existing unique IDs for duplicate check
            $dupStmt = $pdo->prepare('SELECT unique_id FROM id_members WHERE deleted_at IS NULL');
            $dupStmt->execute();
            $existingIds = array_flip($dupStmt->fetchAll(PDO::FETCH_COLUMN));

            // Generate preview data (first 10 rows)
// Generate preview data (first 10 rows)
$columnMap = array_flip($headers);

/*
 * Preview should show ONLY fields belonging to the selected template.
 * Core fields are always allowed.
 */
$previewHeaders = [
    'unique_id' => 'Unique ID',
    'name'      => 'Name'
];

// Add active template fields
require_once __DIR__ . '/../template/template_mgmt_helpers.php';

$activeKeys = template_get_active_field_keys($pdo, $templateId);

foreach ($activeKeys as $key) {
    $key = strtolower(trim((string)$key));

    if ($key === '') {
        continue;
    }

    if (!isset($previewHeaders[$key]) && isset($columnMap[$key])) {
        $previewHeaders[$key] = ucwords(str_replace('_', ' ', $key));
    }
}

// Add dynamic template fields
$tplFields = get_template_input_fields($pdo, $templateId);

foreach ($tplFields as $field) {
    $key = strtolower(trim((string)($field['field_key'] ?? '')));

    if ($key === '') {
        continue;
    }

    if (
        strtolower((string)($field['bilingual_mode'] ?? 'single')) === 'bilingual'
    ) {
        $enKey = $key . '_en';
        $secondaryCode = get_active_languages($pdo, 2)[1]['language_code'] ?? 'secondary';
        $secondaryKey = $key . '_' . $secondaryCode;

        if (isset($columnMap[$enKey])) {
            $previewHeaders[$enKey] = ucwords(str_replace('_', ' ', $enKey));
        }

        if (isset($columnMap[$secondaryKey])) {
            $previewHeaders[$secondaryKey] = ucwords(str_replace('_', ' ', $secondaryKey));
        }
    } else {
        if (isset($columnMap[$key])) {
            $previewHeaders[$key] = ucwords(str_replace('_', ' ', $key));
        }
    }
}

$previewData = [];
$rowCount = 0;

foreach ($data as $rowIndex => $row) {

    if (empty(array_filter($row))) {
        continue;
    }

    if ($rowCount >= 10) {
        break;
    }

    $uid = getColumnValue($row, $columnMap, 'unique_id', '');
    $isDup = !empty($uid) && isset($existingIds[$uid]);

    $previewRow = [
        'row' => $rowIndex + 2,
        'status' => $isDup ? 'Duplicate ⚠️' : 'Ready ✅'
    ];

    // Add ONLY selected template fields
    foreach ($previewHeaders as $key => $label) {
        $previewRow[$key] = getColumnValue($row, $columnMap, $key, '');
    }

    $previewData[] = $previewRow;
    $rowCount++;
}
        }

        // If action is import, process the import
        if ($action === 'import' && empty($errors)) {
            // Get data from session
            $importData = $_SESSION['bulk_import_data'] ?? null;
            if (!$importData) {
                $errors[] = 'Import data not found. Please start over.';
            } else {
                $data = $importData['data'];
                $headers = $importData['headers'];
                $orgId = $importData['orgId'];
                $templateId = $importData['templateId'];
                $memberType = $importData['memberType'];
                $defaultExpiry = $importData['defaultExpiry'];
                $skipDuplicates = $importData['skipDuplicates'];
                $sendNotifications = $importData['sendNotifications'];
                $bulkPhotoUploads = $importData['photoUploads'] ?? [];

                // Process each row
                if (!empty($data) && !empty($headers)) {
                    $columnMap = array_flip($headers);
                    $bulkFieldDefs = get_template_input_fields($pdo, $templateId);
                    $processed = 0;
                    $failed = 0;

                    foreach ($data as $rowIndex => $row) {
                        // Skip empty rows
                        if (empty(array_filter($row))) {
                            continue;
                        }

                        try {
                            // Extract data
                            $rowData = [
                                'organization_id' => $orgId,
                                'template_id' => $templateId,
                                'member_type' => $memberType,
                                'unique_id' => getColumnValue($row, $columnMap, 'unique_id', ''),
                                'name' => getColumnValue($row, $columnMap, 'name', ''),
                                'guardian_name' => getColumnValue($row, $columnMap, 'guardian_name', ''),
                                'email' => getColumnValue($row, $columnMap, 'email', ''),
                                'emergency_contact' => getColumnValue($row, $columnMap, 'phone', getColumnValue($row, $columnMap, 'emergency_contact', '')),
                                'department' => getColumnValue($row, $columnMap, 'department', ''),
                                'class' => getColumnValue($row, $columnMap, 'class', ''),
                                'designation' => getColumnValue($row, $columnMap, 'designation', ''),
                                'company' => getColumnValue($row, $columnMap, 'company', ''),
                                'purpose' => getColumnValue($row, $columnMap, 'purpose', ''),
                                'dob' => normalize_import_date(getColumnValue($row, $columnMap, 'dob', null), true),
                                'address' => getColumnValue($row, $columnMap, 'address', ''),
                                'joined_date' => normalize_import_date(getColumnValue($row, $columnMap, 'joined_date', date('Y-m-d')), false),
                                'expiry_date' => normalize_import_date(getColumnValue($row, $columnMap, 'expiry_date', $defaultExpiry), true),
                                'language' => getColumnValue($row, $columnMap, 'language', 'en'),
                                'photo' => getColumnValue($row, $columnMap, 'photo', null),
                                'signature' => getColumnValue($row, $columnMap, 'signature', null)
                            ];

                            // Validate required fields
                            if (empty($rowData['name'])) {
                                $failed++;
                                $failedRows[] = ['row' => $rowIndex + 2, 'error' => 'Name is required'];
                                continue;
                            }

                            if (!in_array($rowData['member_type'], $memberTypes, true)) {
                                $rowData['member_type'] = $memberType;
                            }

                            // Generate unique ID if not provided
                            if (empty($rowData['unique_id'])) {
                                $rowData['unique_id'] = generate_unique_id($pdo, 'MEM');
                            }

                            // Check for duplicate
                            if ($skipDuplicates) {
                                $stmt = $pdo->prepare("SELECT id FROM id_members WHERE unique_id = ?");
                                $stmt->execute([$rowData['unique_id']]);
                                if ($stmt->fetch()) {
                                    $failed++;
                                    $failedRows[] = ['row' => $rowIndex + 2, 'error' => 'Duplicate unique_id: ' . $rowData['unique_id']];
                                    continue;
                                }
                            }

                            // Save member
                            $result = save_member_bulk($pdo, $rowData);

                            if ($result['success']) {
                                $dynamicValues = [];
                                $secondaryCode = get_active_languages($pdo, 2)[1]['language_code'] ?? 'secondary';
                                foreach ($bulkFieldDefs as $field) {
                                    $key = (string) $field['field_key'];
                                    if (strtolower((string) ($field['bilingual_mode'] ?? 'single')) === 'bilingual') {
                                        $dynamicValues[$key] = [
                                            'translations' => [
                                                'en' => getColumnValue($row, $columnMap, $key . '_en', ''),
                                                $secondaryCode => getColumnValue($row, $columnMap, $key . '_' . $secondaryCode, ''),
                                            ]
                                        ];
                                    } else {
                                        $dynamicValue = getColumnValue($row, $columnMap, $key, '');
                                        if (strtolower((string) ($field['field_type'] ?? '')) === 'date') {
                                            $dynamicValue = normalize_import_date($dynamicValue, true) ?? '';
                                        }
                                        $dynamicValues[$key] = $dynamicValue;
                                    }
                                }
                                if ($dynamicValues) {
                                    save_member_dynamic_values($pdo, (int) $result['id'], $templateId, $dynamicValues);
                                }
                                $processed++;

                                // Handle photo from the Photo column:
                                // 1) uploaded batch photo filename, or
                                // 2) remote image URL (legacy compatibility).
$photoAttached = false;

if (!empty($rowData['photo'])) {

    if (filter_var($rowData['photo'], FILTER_VALIDATE_URL)) {

        $photoAttached = download_and_save_photo(
            $pdo,
            $result['id'],
            $rowData['photo']
        );

    } elseif (!empty($bulkPhotoUploads['files'])) {

        $photoAttached = attach_bulk_photo(
            $pdo,
            $result['id'],
            $rowData['photo'],
            $bulkPhotoUploads
        );
    }

} elseif (!empty($bulkPhotoUploads['files'])) {

    // Photo column blank என்றால் Unique ID வைத்து match
    $photoAttached = attach_bulk_photo(
        $pdo,
        $result['id'],
        $rowData['unique_id'],
        $bulkPhotoUploads
    );
}
                            } else {
    $failed++;

    $errorMessage = $result['error'] ?? '';

    if (
        stripos($errorMessage, 'Duplicate entry') !== false &&
        stripos($errorMessage, 'uk_member_unique_id') !== false
    ) {
        $errorMessage = 'Member ID already exists. Please use a unique Member ID.';
    } else {
        $errorMessage = 'Unable to import this member. Please check the data.';
    }

    $failedRows[] = [
        'row' => $rowIndex + 2,
        'error' => $errorMessage
    ];
}
                       } catch (Exception $e) {
    $failed++;

    $errorMessage = $e->getMessage();

    if (
        stripos($errorMessage, 'Duplicate entry') !== false &&
        stripos($errorMessage, 'uk_member_unique_id') !== false
    ) {
        $errorMessage = 'Member ID already exists. Please use a unique Member ID.';
    } else {
        $errorMessage = 'Unable to import this member. Please check the data.';
    }

    $failedRows[] = [
        'row' => $rowIndex + 2,
        'error' => $errorMessage
    ];
}
                    }

                    $uploadedCount = $processed;
                    $failedCount = $failed;
                    $importStep = 'complete';

                    // Clear temporary bulk-photo files after import.
                    cleanup_bulk_photo_uploads($bulkPhotoUploads);
                    unset($_SESSION['bulk_import_data'], $_SESSION['bulk_photo_uploads']);

                    if ($uploadedCount > 0) {
                        $_SESSION['member_message'] = "Successfully imported $uploadedCount members.";
                        if ($failedCount > 0) {
                            $_SESSION['member_message'] .= " Failed: $failedCount rows.";
                        }
                        $success = true;
                    } else {
                        $errors[] = 'No members were imported. Please check your data.';
                    }
                }
            }
        }
    }
}

// Helper functions
function getColumnValue($row, $columnMap, $key, $default = '')
{
    $index = $columnMap[$key] ?? -1;
    if ($index >= 0 && isset($row[$index])) {
        $value = trim($row[$index]);
        return !empty($value) ? $value : $default;
    }
    return $default;
}

function normalize_import_date($value, $allowEmpty = true)
{
    if ($value === null || trim((string) $value) === '') {
        return $allowEmpty ? null : date('Y-m-d');
    }

    // PhpSpreadsheet can return Excel serial numbers for date-formatted cells.
    if (is_numeric($value) && (float) $value > 0 && (float) $value < 100000) {
        try {
            $date = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $value);
            return $date->format('Y-m-d');
        } catch (Throwable $e) {
            // Fall through to normal string parsing.
        }
    }

    $raw = trim((string) $value);
    $formats = ['d-m-Y', 'd/m/Y', 'Y-m-d', 'm-d-Y', 'm/d/Y', 'd.m.Y'];

    foreach ($formats as $format) {
        $date = \DateTime::createFromFormat('!' . $format, $raw);
        if ($date && $date->format($format) === $raw) {
            return $date->format('Y-m-d');
        }
    }

    $timestamp = strtotime($raw);
    return $timestamp !== false ? date('Y-m-d', $timestamp) : null;
}

function save_bulk_photo_uploads()
{
    if (empty($_FILES['photo_files']['name']) || !is_array($_FILES['photo_files']['name'])) {
        return [];
    }

        // Use a reliable path for bulk temporary uploads. Using DIRECTORY_SEPARATOR improves cross‑OS compatibility.
    $baseDir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'bulk_temp' . DIRECTORY_SEPARATOR;
    // Ensure the bulk temp directory exists.
    if (!is_dir($baseDir)) {
        if (!mkdir($baseDir, 0755, true) && !is_dir($baseDir)) {
            // If creation fails, throw an exception to surface the problem immediately.
            throw new RuntimeException('Unable to create temporary photo upload folder at ' . $baseDir);
        }
    }
    if (!is_dir($baseDir) && !mkdir($baseDir, 0755, true)) {
        throw new RuntimeException('Unable to create temporary photo upload folder.');
    }

    $token = bin2hex(random_bytes(16));
    $tempDir = $baseDir . $token . '/';
    if (!mkdir($tempDir, 0755, true)) {
        throw new RuntimeException('Unable to create temporary photo folder.');
    }

    $allowedMime = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    $files = [];
    $count = count($_FILES['photo_files']['name']);

    if ($count > 200) {
        throw new RuntimeException('You can upload a maximum of 200 photos at once.');
    }

    for ($i = 0; $i < $count; $i++) {
        $error = $_FILES['photo_files']['error'][$i] ?? UPLOAD_ERR_NO_FILE;
        if ($error === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('One or more photos could not be uploaded.');
        }

        $size = (int) ($_FILES['photo_files']['size'][$i] ?? 0);
        if ($size <= 0 || $size > 5 * 1024 * 1024) {
            throw new RuntimeException('Each photo must be between 1 byte and 5MB.');
        }

        $tmp = $_FILES['photo_files']['tmp_name'][$i] ?? '';
        $original = basename((string) ($_FILES['photo_files']['name'][$i] ?? ''));
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);

        if (!isset($allowedMime[$mime])) {
            throw new RuntimeException('Only JPG, PNG, GIF and WEBP photos are allowed.');
        }

        // Verify that the uploaded file is actually an image.
        if (@getimagesize($tmp) === false) {
            throw new RuntimeException('One of the uploaded files is not a valid image.');
        }

        $safeOriginal = preg_replace('/[^A-Za-z0-9._-]/', '_', $original);
        $storedName = bin2hex(random_bytes(8)) . '_' . $safeOriginal;
        $target = $tempDir . $storedName;

        if (!move_uploaded_file($tmp, $target)) {
            throw new RuntimeException('Failed to save uploaded photo.');
        }

        $files[strtolower($original)] = [
            'path' => $target,
            'original' => $original,
            'stored' => $storedName,
            'size' => $size,
        ];
    }

    if (empty($files)) {
        @rmdir($tempDir);
        return [];
    }

    return [
        'dir' => $tempDir,
        'files' => $files,
    ];
}

function attach_bulk_photo($pdo, $memberId, $photoReference, $photoUploads = [])
{
    $photoReference = trim((string)$photoReference);
    $photoReference = trim($photoReference, "\"'");

    if (empty($photoUploads['files']) || !is_array($photoUploads['files'])) {
        return false;
    }

    $reference = str_replace('\\', '/', $photoReference);
    $referenceFilename = basename($reference);
    $referenceFilename = trim($referenceFilename, "\"'");
    $referenceKey = strtolower($referenceFilename);
    $referenceBase = pathinfo($referenceFilename, PATHINFO_FILENAME);

    $source = null;
    $extension = 'jpg';

    // Pass 1: exact filename match (case-insensitive, with extension)
    foreach ($photoUploads['files'] as $photo) {
        $original = trim((string)($photo['original'] ?? ''));
        $original = str_replace('\\', '/', $original);
        $originalFilename = trim(basename($original), "\"'");

        if (strcasecmp($originalFilename, $referenceFilename) === 0 && !empty($photo['path']) && is_file($photo['path'])) {
            $source = $photo['path'];
            $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
            break;
        }
    }

    // Pass 2: fallback — match by filename WITHOUT extension
    if (!$source) {
        foreach ($photoUploads['files'] as $photo) {
            $original = trim((string)($photo['original'] ?? ''));
            $original = str_replace('\\', '/', $original);
            $originalFilename = trim(basename($original), "\"'");
            $originalBase = pathinfo($originalFilename, PATHINFO_FILENAME);

            if (strcasecmp($originalBase, $referenceBase) === 0 && !empty($photo['path']) && is_file($photo['path'])) {
                $source = $photo['path'];
                $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
                break;
            }
        }
    }

    if (!$source || !is_file($source)) {
        return false;
    }

    if (!in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) {
        $extension = 'jpg';
    }

    $uploadDir = __DIR__ . '/../images/uploads/';
    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            return false;
        }
    }

    $filename = 'member_' . (int)$memberId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $extension;
    $destination = $uploadDir . $filename;

    if (!copy($source, $destination)) {
        return false;
    }

    $stmt = $pdo->prepare("UPDATE id_members SET photo = ? WHERE id = ?");
    $stmt->execute([$filename, (int)$memberId]);

    return $stmt->rowCount() >= 0;
}

function cleanup_bulk_photo_uploads($photoUploads)
{
    $dir = $photoUploads['dir'] ?? '';
    if ($dir === '' || !is_dir($dir)) {
        return;
    }

    foreach (glob($dir . '*') ?: [] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    @rmdir($dir);
}

function parseCSVFile($filePath)
{
    $data = [];
    if (($handle = fopen($filePath, 'r')) !== false) {
        while (($row = fgetcsv($handle, 0, ',')) !== false) {
            $data[] = $row;
        }
        fclose($handle);
    }
    return $data;
}

function parseExcelFile($filePath)
{
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
    return $spreadsheet->getActiveSheet()->toArray('', true, true, false);
}

function save_member_bulk($pdo, $data)
{
    try {
        $pdo->beginTransaction();

        $sql = "INSERT INTO id_members (
                    organization_id, member_type, template_id, unique_id, 
                    name, guardian_name, email, emergency_contact,
                    department, class, designation, company, purpose,
                    dob, address, joined_date, expiry_date, language,
                    created_at, updated_at
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW()
                )";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $data['organization_id'] ?? null,
            $data['member_type'] ?? 'student',
            $data['template_id'] ?? null,
            $data['unique_id'],
            $data['name'],
            $data['guardian_name'] ?? null,
            $data['email'] ?? null,
            $data['emergency_contact'] ?? null,
            $data['department'] ?? null,
            $data['class'] ?? null,
            $data['designation'] ?? null,
            $data['company'] ?? null,
            $data['purpose'] ?? null,
            $data['dob'] ?? null,
            $data['address'] ?? null,
            $data['joined_date'] ?? date('Y-m-d'),
            $data['expiry_date'] ?? null,
            $data['language'] ?? 'en'
        ]);

        $memberId = $pdo->lastInsertId();
        $pdo->commit();
        return ['success' => true, 'id' => $memberId];
    } catch (Exception $e) {
        $pdo->rollBack();
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function download_and_save_photo($pdo, $memberId, $url)
{
    try {
        $upload_dir = __DIR__ . '/../images/uploads/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'jpg';
        $filename = 'member_' . $memberId . '_' . time() . '.' . $ext;
        $filepath = $upload_dir . $filename;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $imageData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && !empty($imageData)) {
            file_put_contents($filepath, $imageData);

            $stmt = $pdo->prepare("UPDATE id_members SET photo = ? WHERE id = ?");
            $stmt->execute([$filename, $memberId]);
            return true;
        }
        return false;
    } catch (Exception $e) {
        return false;
    }
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Sample CSV template
function getSampleCSV()
{
    return "name,unique_id,member_type,email,phone,department,class,designation,company,purpose,dob,address,joined_date,expiry_date,language\n" .
        "John Doe,EMP2024001,employee,john@example.com,9876543210,Engineering,10-A,Manager,Acme Corp,Employee ID,1990-01-15,123 Main St,2024-01-01,2025-12-31,en\n" .
        "Jane Smith,STU2024002,student,jane@example.com,9876543211,Science,10-B,,,Student ID,2005-05-20,456 School Rd,2024-06-01,2026-05-31,en";
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>Bulk Upload · ID Card Generator</title>

    <!-- Fonts -->
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600;14..32,700&family=Poppins:wght@500;600;700&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <style>
        :root {
            --primary: #0a1a2f;
            --primary-light: #1e3a5f;
            --primary-soft: #e8f0fe;
            --accent: #e53e3e;
            --success: #0e9f6e;
            --success-soft: #e3f9ee;
            --warning: #f4b740;
            --warning-soft: #fef5e0;
            --danger: #dc2626;
            --danger-soft: #fee2e2;
            --info: #3b82f6;
            --info-soft: #dbeafe;
            --neutral-50: #f9fafb;
            --neutral-100: #f3f4f6;
            --neutral-200: #e5e7eb;
            --neutral-300: #d1d5db;
            --neutral-400: #9ca3af;
            --neutral-500: #6b7280;
            --neutral-600: #4b5563;
            --neutral-700: #374151;
            --neutral-800: #1f2937;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1);
            --radius-sm: 0.375rem;
            --radius-md: 0.5rem;
            --radius-lg: 0.75rem;
            --radius-xl: 1rem;
            --radius-2xl: 1.5rem;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: var(--neutral-50);
            color: var(--neutral-800);
            margin: 0;
            padding: 0;
        }

        .dashboard-wrapper {
            display: flex;
            min-height: 100vh;
        }

        .main-content {
            flex: 1;
            margin-left: 280px;
            min-height: 100vh;
            background: var(--neutral-50);
        }

        .dashboard-content {
            padding: 2rem;
            max-width: 1600px;
            margin: 0 auto;
        }

        /* Main Card */
        .main-card {
            background: white;
            border-radius: var(--radius-2xl);
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
            overflow: hidden;
        }

        .card-header-custom {
            padding: 1.25rem 1.5rem;
            border-bottom: 1px solid var(--neutral-200);
            background: var(--neutral-50);
        }

        .card-body-custom {
            padding: 1.5rem;
        }

        /* Form */
        .form-label {
            font-weight: 500;
            font-size: 0.813rem;
            color: var(--neutral-700);
            margin-bottom: 0.25rem;
        }

        .form-label .required {
            color: var(--danger);
        }

        .form-control,
        .form-select {
            border-radius: var(--radius-lg);
            border: 1px solid var(--neutral-300);
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            transition: all 0.2s;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(10, 26, 47, 0.1);
            outline: none;
        }

        .form-text {
            font-size: 0.75rem;
            color: var(--neutral-500);
        }

        .section-title {
            font-size: 0.875rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid var(--primary-soft);
        }

        .section-title i {
            margin-right: 0.5rem;
        }

        /* Alert */
        .alert {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.75rem 1rem;
            border-radius: var(--radius-lg);
            margin-bottom: 1rem;
        }

        .alert-danger {
            background: var(--danger-soft);
            color: var(--danger);
        }

        .alert-success {
            background: var(--success-soft);
            color: var(--success);
        }

        .alert-info {
            background: var(--info-soft);
            color: var(--info);
        }

        .alert .btn-close-custom {
            cursor: pointer;
            background: none;
            border: none;
            font-size: 1.25rem;
            color: inherit;
            opacity: 0.5;
            padding: 0 0.25rem;
        }

        .alert .btn-close-custom:hover {
            opacity: 1;
        }

        /* Buttons */
        .btn {
            border-radius: var(--radius-lg);
            padding: 0.5rem 1rem;
            font-size: 0.875rem;
            font-weight: 500;
            transition: all 0.2s;
        }

        .btn-primary {
            background: var(--primary);
            border-color: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-light);
            border-color: var(--primary-light);
        }

        .btn-outline-secondary {
            border-color: var(--neutral-300);
            color: var(--neutral-600);
        }

        .btn-outline-secondary:hover {
            background: var(--neutral-100);
        }

        .btn-success {
            background: var(--success);
            border-color: var(--success);
            color: white;
        }

        .btn-success:hover {
            background: #0d8b5e;
            border-color: #0d8b5e;
        }

        .btn-warning {
            background: var(--warning);
            border-color: var(--warning);
            color: var(--neutral-800);
        }

        .btn-warning:hover {
            background: #e0a830;
            border-color: #e0a830;
        }

        /* Upload Zone */
        .upload-zone {
            border: 3px dashed var(--neutral-300);
            border-radius: var(--radius-2xl);
            padding: 3rem 2rem;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
            background: var(--neutral-50);
        }

        .upload-zone:hover {
            border-color: var(--primary);
            background: var(--primary-soft);
        }

        .upload-zone.dragover {
            border-color: var(--success);
            background: var(--success-soft);
        }

        .upload-zone i {
            font-size: 4rem;
            color: var(--neutral-400);
            margin-bottom: 1rem;
        }

        .upload-zone h4 {
            font-weight: 600;
            color: var(--neutral-700);
        }

        .upload-zone p {
            color: var(--neutral-500);
            font-size: 0.875rem;
        }

        /* Breadcrumb */
        .breadcrumb {
            display: flex;
            gap: 0.5rem;
            list-style: none;
            padding: 0;
            margin: 0 0 1.5rem 0;
            font-size: 0.875rem;
        }

        .breadcrumb a {
            color: var(--primary);
            text-decoration: none;
        }

        .breadcrumb .active {
            color: var(--neutral-500);
        }

        /* Stats */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }

        .stat-card {
            background: white;
            border-radius: var(--radius-2xl);
            padding: 1.25rem;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--neutral-200);
            text-align: center;
        }

        .stat-card .stat-number {
            font-size: 1.75rem;
            font-weight: 700;
        }

        .stat-card .stat-label {
            font-size: 0.7rem;
            font-weight: 500;
            color: var(--neutral-500);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .stat-card .stat-icon {
            font-size: 1.5rem;
            margin-bottom: 0.25rem;
        }

        .text-primary {
            color: var(--primary);
        }

        .text-success {
            color: var(--success);
        }

        .text-danger {
            color: var(--danger);
        }

        .text-warning {
            color: var(--warning);
        }

        /* Failed rows table */
        .failed-table {
            font-size: 0.813rem;
        }

        .failed-table th {
            font-size: 0.688rem;
            text-transform: uppercase;
            color: var(--neutral-500);
        }

        /* Progress Steps */
        .step-indicators {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            position: relative;
            padding: 0 1rem;
        }

        .step-indicators::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 10%;
            right: 10%;
            height: 2px;
            background: var(--neutral-200);
            transform: translateY(-50%);
            z-index: 0;
        }

        .step-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            z-index: 1;
            background: var(--neutral-50);
            padding: 0 0.5rem;
        }

        .step-circle {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.875rem;
            background: var(--neutral-200);
            color: var(--neutral-500);
            border: 2px solid var(--neutral-300);
            transition: all 0.3s;
        }

        .step-circle.active {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .step-circle.completed {
            background: var(--success);
            color: white;
            border-color: var(--success);
        }

        .step-label {
            font-size: 0.688rem;
            margin-top: 0.25rem;
            color: var(--neutral-500);
            text-align: center;
        }

        .step-label.active {
            color: var(--primary);
            font-weight: 600;
        }

        .step-label.completed {
            color: var(--success);
        }

        /* Preview Table */
        .preview-table {
            font-size: 0.813rem;
        }

        .preview-table th {
            background: var(--neutral-50);
            font-weight: 600;
            font-size: 0.688rem;
            text-transform: uppercase;
            color: var(--neutral-500);
            white-space: nowrap;
        }

        .preview-table td {
            vertical-align: middle;
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .main-content {
                margin-left: 0;
            }

            .step-indicators {
                flex-wrap: wrap;
                gap: 0.5rem;
            }

            .step-indicators::before {
                display: none;
            }

            .step-item {
                flex: 1;
                min-width: 60px;
            }
        }

        @media (max-width: 768px) {
            .dashboard-content {
                padding: 1rem;
            }

            .upload-zone {
                padding: 2rem 1rem;
            }

            .upload-zone i {
                font-size: 3rem;
            }

            .step-circle {
                width: 32px;
                height: 32px;
                font-size: 0.75rem;
            }

            .step-label {
                font-size: 0.6rem;
            }
        }

        @media (max-width: 480px) {
            .card-header-custom {
                padding: 1rem;
            }

            .card-body-custom {
                padding: 1rem;
            }

            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
        }
    </style>
</head>

<body>
    <div class="dashboard-wrapper">
        <!-- Sidebar -->
        <?php include __DIR__ . '/../includes/sidebar.php'; ?>

        <!-- Main Content -->
        <main class="main-content">
            <!-- Top Header -->
            <?php include __DIR__ . '/../includes/header.php'; ?>

            <!-- Content Area -->
            <div class="dashboard-content">
                <!-- Breadcrumb -->
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../dashboard.php"><i
                                    class="fas fa-home me-1"></i>Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="view_members.php">Members</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Bulk Upload</li>
                    </ol>
                </nav>

                <!-- Step Indicators -->
                <div class="step-indicators">
                    <div class="step-item">
                        <div class="step-circle <?= $importStep !== 'form' ? 'completed' : 'active' ?>">1</div>
                        <span class="step-label <?= $importStep !== 'form' ? 'completed' : 'active' ?>">Select
                            Org</span>
                    </div>
                    <div class="step-item">
                        <div class="step-circle <?= $importStep !== 'form' ? 'completed' : 'active' ?>">2</div>
                        <span class="step-label <?= $importStep !== 'form' ? 'completed' : 'active' ?>">Select
                            Template</span>
                    </div>
                    <div class="step-item">
                        <div class="step-circle <?= $importStep !== 'form' ? 'completed' : 'active' ?>">3</div>
                        <span class="step-label <?= $importStep !== 'form' ? 'completed' : 'active' ?>">Download
                            Sample</span>
                    </div>
                    <div class="step-item">
                        <div
                            class="step-circle <?= $importStep === 'preview' || $importStep === 'complete' ? 'completed' : ($importStep === 'form' ? 'active' : '') ?>">
                            4</div>
                        <span
                            class="step-label <?= $importStep === 'preview' || $importStep === 'complete' ? 'completed' : ($importStep === 'form' ? 'active' : '') ?>">Upload
                            Excel</span>
                    </div>
                    <div class="step-item">
                        <div
                            class="step-circle <?= $importStep === 'complete' ? 'completed' : ($importStep === 'preview' ? 'active' : '') ?>">
                            5</div>
                        <span
                            class="step-label <?= $importStep === 'complete' ? 'completed' : ($importStep === 'preview' ? 'active' : '') ?>">Import
                            Members</span>
                    </div>
                </div>

                <!-- Alerts -->
                <?php if (!empty($_SESSION['member_message'])): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i>
                        <div class="flex-1"><?= htmlspecialchars($_SESSION['member_message']) ?></div>
                        <button type="button" class="btn-close-custom"
                            onclick="this.parentElement.remove()">&times;</button>
                    </div>
                    <?php unset($_SESSION['member_message']); ?>
                <?php endif; ?>

                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <i class="fas fa-exclamation-circle"></i>
                        <div>
                            <strong>Please fix the following errors:</strong>
                            <ul class="mb-0 mt-1">
                                <?php foreach ($errors as $error): ?>
                                    <li><?= htmlspecialchars($error) ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <button type="button" class="btn-close-custom"
                            onclick="this.parentElement.remove()">&times;</button>
                    </div>
                <?php endif; ?>

                <!-- Stats if uploaded -->
                <?php if ($uploadedCount > 0 || $failedCount > 0): ?>
                    <div class="stats-grid">
                        <div class="stat-card">
                            <div class="stat-icon text-success"><i class="fas fa-check-circle"></i></div>
                            <div class="stat-label">Successfully Imported</div>
                            <div class="stat-number text-success"><?= $uploadedCount ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon text-danger"><i class="fas fa-times-circle"></i></div>
                            <div class="stat-label">Failed</div>
                            <div class="stat-number text-danger"><?= $failedCount ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-icon text-primary"><i class="fas fa-users"></i></div>
                            <div class="stat-label">Total Processed</div>
                            <div class="stat-number text-primary"><?= $uploadedCount + $failedCount ?></div>
                        </div>
                    </div>

                    <?php if (!empty($failedRows)): ?>
                        <div class="main-card mb-4">
                            <div class="card-header-custom">
                                <h6 class="mb-0"><i class="fas fa-exclamation-triangle text-danger me-2"></i>Failed Rows</h6>
                            </div>
                            <div class="card-body-custom">
                                <div class="table-responsive">
                                    <table class="table failed-table">
                                        <thead>
                                            <tr>
                                                <th>Row</th>
                                                <th>Error</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($failedRows as $row): ?>
                                                <tr>
                                                    <td><?= $row['row'] ?></td>
                                                    <td class="text-danger"><?= htmlspecialchars($row['error']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($importStep === 'complete'): ?>
                        <div class="text-center mb-4">
                            <a href="view_members.php" class="btn btn-primary">
                                <i class="fas fa-arrow-left me-1"></i>View Members
                            </a>
                            <a href="bulk_upload.php" class="btn btn-outline-secondary ms-2">
                                <i class="fas fa-plus me-1"></i>Import More
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <!-- Main Form -->
                <div class="main-card">
                    <div class="card-header-custom">
                        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                            <div>
                                <h5 style="font-weight:600;color:var(--neutral-800);margin:0;">
                                    <i class="fas fa-upload text-primary me-2"></i>Bulk Upload Members
                                </h5>
                                <p style="color:var(--neutral-500);font-size:0.813rem;margin:0;">
                                    Select Organization → Template → Download Sample Excel → Upload Excel →
                                    <strong>Import Members</strong>
                                </p>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-outline-secondary btn-sm" id="downloadSampleBtn"
                                    <?= $selectedOrgId > 0 && !empty($templates) ? '' : 'disabled' ?>>
                                    <i class="fas fa-download me-1"></i>Download Sample
                                </button>
                                <a href="view_members.php" class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-arrow-left me-1"></i>Back to List
                                </a>
                            </div>
                        </div>
                    </div>

                    <div class="card-body-custom">
                        <?php if ($importStep === 'preview'): ?>
                            <!-- Step 5: Preview & Import -->
                            <form method="post" enctype="multipart/form-data" id="importForm">
                                <input type="hidden" name="csrf_token"
                                    value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="import">
                                <input type="hidden" name="organization_id"
                                    value="<?= htmlspecialchars($_SESSION['bulk_import_data']['orgId'] ?? '') ?>">
                                <input type="hidden" name="template_id"
                                    value="<?= htmlspecialchars($_SESSION['bulk_import_data']['templateId'] ?? '') ?>">
                                <input type="hidden" name="member_type"
                                    value="<?= htmlspecialchars($_SESSION['bulk_import_data']['memberType'] ?? '') ?>">
                                <input type="hidden" name="default_expiry"
                                    value="<?= htmlspecialchars($_SESSION['bulk_import_data']['defaultExpiry'] ?? '') ?>">
                                <input type="hidden" name="skip_duplicates"
                                    value="<?= ($_SESSION['bulk_import_data']['skipDuplicates'] ?? false) ? '1' : '0' ?>">
                                <input type="hidden" name="send_notifications"
                                    value="<?= ($_SESSION['bulk_import_data']['sendNotifications'] ?? false) ? '1' : '0' ?>">

                                <div class="alert alert-info">
                                    <i class="fas fa-info-circle"></i>
                                    <div>
                                        <strong>Review your data before importing</strong>
                                        <p class="mb-0 text-muted small">Showing first 10 rows. Total
                                            <?= count($_SESSION['bulk_import_data']['data'] ?? []) ?> rows ready to import.
                                        </p>
                                    </div>
                                </div>

                                <div class="table-responsive">
                                <div class="table-responsive">
    <table class="table table-bordered preview-table">
        <thead>
            <tr>
                <th>#</th>

                <?php foreach ($previewHeaders as $key => $label): ?>
                    <th><?= htmlspecialchars($label) ?></th>
                <?php endforeach; ?>

                <th>Status</th>
            </tr>
        </thead>

        <tbody>
            <?php foreach ($previewData as $row): ?>
                <tr>
                    <td><?= (int)$row['row'] ?></td>

                    <?php foreach ($previewHeaders as $key => $label): ?>
                        <td>
                            <?php
                            $value = $row[$key] ?? '';

                            if ($value === '') {
                                echo '<span class="text-muted">—</span>';
                            } else {
                                echo htmlspecialchars((string)$value);
                            }
                            ?>
                        </td>
                    <?php endforeach; ?>

                    <td>
                        <?php if (str_starts_with($row['status'], 'Duplicate')): ?>
                            <span class="badge bg-warning text-dark">
                                <?= htmlspecialchars($row['status']) ?>
                            </span>
                        <?php else: ?>
                            <span class="badge bg-success">
                                Ready ✅
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
                                </div>

                                <div class="d-flex flex-wrap gap-2 mt-3">
                                    <button type="submit" class="btn btn-success" id="confirmImportBtn">
                                        <i class="fas fa-check me-1"></i>Confirm Import
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary"
                                        onclick="window.location.href='bulk_upload.php'">
                                        <i class="fas fa-times me-1"></i>Cancel
                                    </button>
                                    <span class="text-muted small ms-3 d-flex align-items-center">
                                        <i class="fas fa-shield-alt me-1"></i>
                                        This will import <?= count($_SESSION['bulk_import_data']['data'] ?? []) ?> members
                                    </span>
                                </div>
                            </form>

                            <script>
                                document.getElementById('confirmImportBtn').addEventListener('click', function (e) {
                                    if (!confirm('Are you sure you want to import these members?')) {
                                        e.preventDefault();
                                    }
                                });
                            </script>

                        <?php elseif ($importStep === 'complete'): ?>
                            <!-- Import Complete - Already shown above -->

                        <?php else: ?>
                            <!-- Step 1-4: Original Form -->
                            <form method="post" enctype="multipart/form-data" id="uploadForm">
                                <input type="hidden" name="csrf_token"
                                    value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                <input type="hidden" name="action" value="preview">
                                <input type="hidden" name="MAX_FILE_SIZE" value="10485760">

                                <div class="row g-4">
                                    <!-- File Upload -->
                                    <div class="col-12" id="uploadStep" style="order:2;">
                                        <h6 class="section-title"><i class="fas fa-file-upload"></i>Upload File</h6>

                                        <div class="upload-zone" id="uploadZone">
                                            <i class="fas fa-cloud-upload-alt"></i>
                                            <h4>Drop your Excel file here</h4>
                                            <p>or click to browse</p>
                                            <p class="text-muted small">Supported formats: CSV, XLSX, XLS (Max 10MB)</p>
                                            <input type="file" name="csv_file" id="fileInput" class="d-none"
                                                accept=".csv,.xlsx,.xls">
                                            <button type="button" class="btn btn-primary btn-sm"
                                                onclick="document.getElementById('fileInput').click()">
                                                <i class="fas fa-folder-open me-1"></i>Browse Files
                                            </button>
                                        </div>
                                        <div id="fileNameDisplay" class="mt-2 text-center" style="display:none;">
                                            <span class="badge bg-success"><i class="fas fa-file me-1"></i> <span
                                                    id="fileName"></span></span>
                                            <button type="button" class="btn btn-sm btn-outline-danger ms-2"
                                                onclick="clearFile()">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Optional Photo Upload -->
                                    <div class="col-12" id="photoUploadStep">
                                        <h6 class="section-title"><i class="fas fa-camera"></i>Member Photos <span
                                                class="text-muted fw-normal">(Optional)</span></h6>
                                        <div class="border rounded-4 p-3" style="background:#f8f9ff;border-color:#c7d2fe!important;">
                                            <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-3">
                                                <div>
                                                    <div class="fw-semibold" style="color:#1e3a5f;"><i class="fas fa-images me-2 text-primary"></i>Upload Member Photos</div>
                                                    <div class="form-text mt-1">
                                                        Select multiple JPG, PNG, GIF or WEBP files. Recommended filename:
                                                        <strong>same as Unique ID</strong> (example: MEM20260001.jpg).
                                                        Max 200 photos · 5MB each.
                                                    </div>
                                                </div>
                                                <div class="d-flex gap-2">
                                                    <input type="file" name="photo_files[]" id="photoFiles" class="d-none"
                                                        accept="image/jpeg,image/png,image/gif,image/webp" multiple>
                                                    <button type="button" class="btn btn-outline-primary btn-sm" id="selectPhotosBtn"
                                                        onclick="document.getElementById('photoFiles').click()">
                                                        <i class="fas fa-images me-1"></i>Select Photos
                                                    </button>
                                                    <button type="button" class="btn btn-outline-danger btn-sm" id="clearPhotosBtn" style="display:none;"
                                                        onclick="clearPhotos()">
                                                        <i class="fas fa-trash me-1"></i>Clear All
                                                    </button>
                                                </div>
                                            </div>
                                            <!-- Photo drop zone -->
                                            <div id="photoDrop" style="margin-top:0.75rem;border:2px dashed #a5b4fc;border-radius:0.75rem;padding:1rem;text-align:center;cursor:pointer;transition:all 0.2s;background:white;">
                                                <span class="text-muted small"><i class="fas fa-hand-holding-image me-1"></i>Drag &amp; drop photos here or click "Select Photos" above</span>
                                            </div>
                                            <!-- Thumbnail grid -->
                                            <div id="photoFilesDisplay" class="mt-3" style="display:none;">
                                                <div class="d-flex align-items-center justify-content-between mb-2">
                                                    <span class="fw-semibold small" style="color:#1e3a5f;"><i class="fas fa-check-circle text-success me-1"></i><span id="photoCount">0</span> photo(s) selected</span>
                                                </div>
                                                <div id="photoThumbGrid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(80px,1fr));gap:0.5rem;"></div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Options -->
                                    <div class="col-12" id="importOptions" style="order:1;">
                                        <h6 class="section-title"><i class="fas fa-cog"></i>Import Options</h6>

                                        <div class="row g-3">
                                            <?php if ($isSuperAdmin): ?>
                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <label class="form-label">Organization</label>
                                                        <select name="organization_id" class="form-select"
                                                            id="organizationSelect" required>
                                                            <option value="">Select Organization</option>
                                                            <?php foreach ($organizations as $org): ?>
                                                                <option value="<?= (int) $org['id'] ?>"
                                                                    <?= (($_POST['organization_id'] ?? $_GET['organization_id'] ?? '') == $org['id']) ? 'selected' : '' ?>>
                                                                    <?= htmlspecialchars($org['organization_name']) ?>
                                                                    <?php if ($org['project_type']): ?>
                                                                        (<?= ucfirst($org['project_type']) ?>)
                                                                    <?php endif; ?>
                                                                </option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <input type="hidden" name="organization_id" id="organizationSelect"
                                                    value="<?= (int) $userOrgId ?>">
                                            <?php endif; ?>

                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Template</label>
                                                    <select name="template_id" class="form-select" id="templateSelect"
                                                        required <?= $selectedOrgId > 0 ? '' : 'disabled' ?>>
                                                        <option value="">Select Template</option>
                                                        <?php foreach ($templates as $tpl): ?>
                                                            <option value="<?= (int) $tpl['id'] ?>"
                                                                <?= isset($_POST['template_id']) && $_POST['template_id'] == $tpl['id'] ? 'selected' : '' ?>>
                                                                <?= htmlspecialchars($tpl['name']) ?>
                                                                (<?= ucfirst($tpl['orientation']) ?>)
                                                                <?php if ($tpl['is_default']): ?>⭐<?php endif; ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="col-md-6">
                                                <div class="mb-3">
                                                    <label class="form-label">Default Expiry Date</label>
                                                    <input type="date" name="default_expiry" class="form-control"
                                                        value="<?= htmlspecialchars($_POST['default_expiry'] ?? date('Y-m-d', strtotime('+1 year'))) ?>">
                                                    <div class="form-text">Used if expiry_date column is missing or empty
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="col-md-6">


                                                <div class="col-md-6">
                                                    <div class="mb-3">
                                                        <div class="form-check">
                                                            <input type="checkbox" name="skip_duplicates"
                                                                class="form-check-input" id="skipDuplicates"
                                                                <?= isset($_POST['skip_duplicates']) ? 'checked' : '' ?>>
                                                            <label class="form-check-label" for="skipDuplicates">Skip
                                                                duplicate unique IDs</label>
                                                        </div>
                                                        <div class="form-text">Skip records where unique_id already exists
                                                            in database</div>
                                                    </div>
                                                </div>




                                            </div>




                                        </div>
                                    </div>
                                    <!-- Submit -->



                                </div>
                                <div class="col-12">
                                    <hr>
                                    <div class="d-flex flex-wrap gap-2">
                                        <button type="submit" class="btn btn-primary" id="previewBtn">
                                            <i class="fas fa-eye me-1"></i>Preview & Continue
                                        </button>
                                        <a href="view_members.php" class="btn btn-outline-secondary">
                                            <i class="fas fa-times me-1"></i>Cancel
                                        </a>
                                    </div>
                                </div>

                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php include __DIR__ . '/../includes/footer.php'; ?>
        </main>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <script>
        // File upload zone
        const uploadZone = document.getElementById('uploadZone');
        const fileInput = document.getElementById('fileInput');
        const fileNameDisplay = document.getElementById('fileNameDisplay');
        const fileName = document.getElementById('fileName');
        const previewBtn = document.getElementById('previewBtn');
        const organizationSelect = document.getElementById('organizationSelect');
        const templateSelect = document.getElementById('templateSelect');
        const downloadSampleBtn = document.getElementById('downloadSampleBtn');
        const photoFiles = document.getElementById('photoFiles');
        const photoFilesDisplay = document.getElementById('photoFilesDisplay');

        function updateSampleButton() {
            downloadSampleBtn.disabled = !(organizationSelect?.value && templateSelect?.value);
        }
        organizationSelect?.addEventListener('change', function () {
            if (this.value) window.location.href = 'bulk_upload.php?organization_id=' + encodeURIComponent(this.value);
        });
        templateSelect?.addEventListener('change', updateSampleButton);
        updateSampleButton();

        // Click to browse
        if (uploadZone) {
            uploadZone.addEventListener('click', function () {
                fileInput.click();
            });
        }

        // File selected
        if (fileInput) {
            fileInput.addEventListener('change', function () {
                if (this.files && this.files[0]) {
                    fileName.textContent = this.files[0].name;
                    fileNameDisplay.style.display = 'block';
                    uploadZone.style.borderColor = 'var(--success)';
                    if (previewBtn) previewBtn.disabled = false;
                }
            });
        }

        // Drag and drop
        if (uploadZone) {
            uploadZone.addEventListener('dragover', function (e) {
                e.preventDefault();
                this.classList.add('dragover');
            });

            uploadZone.addEventListener('dragleave', function (e) {
                e.preventDefault();
                this.classList.remove('dragover');
            });

            uploadZone.addEventListener('drop', function (e) {
                e.preventDefault();
                this.classList.remove('dragover');

                if (e.dataTransfer.files && e.dataTransfer.files[0]) {
                    fileInput.files = e.dataTransfer.files;
                    fileName.textContent = e.dataTransfer.files[0].name;
                    fileNameDisplay.style.display = 'block';
                    uploadZone.style.borderColor = 'var(--success)';
                    if (previewBtn) previewBtn.disabled = false;
                }
            });
        }

        // Clear file
        function clearFile() {
            fileInput.value = '';
            fileNameDisplay.style.display = 'none';
            uploadZone.style.borderColor = 'var(--neutral-300)';
            if (previewBtn) previewBtn.disabled = true;
        }

        // Photo drag-and-drop zone
        const photoDrop = document.getElementById('photoDrop');
        const clearPhotosBtn = document.getElementById('clearPhotosBtn');

        function clearPhotos() {
            if (photoFiles) photoFiles.value = '';
            const display = document.getElementById('photoFilesDisplay');
            const grid = document.getElementById('photoThumbGrid');
            const photoCount = document.getElementById('photoCount');
            if (display) display.style.display = 'none';
            if (grid) grid.innerHTML = '';
            if (photoCount) photoCount.textContent = '0';
            if (clearPhotosBtn) clearPhotosBtn.style.display = 'none';
            if (photoDrop) photoDrop.style.display = '';
        }

        function renderPhotoThumbs(files) {
            const grid = document.getElementById('photoThumbGrid');
            const display = document.getElementById('photoFilesDisplay');
            const photoCount = document.getElementById('photoCount');
            if (!grid || !display) return;

            const invalid = Array.from(files).find(file => {
                const ext = file.name.split('.').pop().toLowerCase();
                return !['jpg', 'jpeg', 'png', 'gif', 'webp'].includes(ext) || file.size > 5 * 1024 * 1024;
            });

            if (invalid) {
                alert('Photos must be JPG, PNG, GIF or WEBP and each file must be 5MB or smaller.');
                if (photoFiles) photoFiles.value = '';
                return;
            }

            grid.innerHTML = '';
            Array.from(files).forEach(function(file) {
                const reader = new FileReader();
                reader.onload = function(e) {
                    const wrapper = document.createElement('div');
                    wrapper.style.cssText = 'position:relative;border-radius:0.5rem;overflow:hidden;aspect-ratio:1;background:#e5e7eb;';

                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.alt = file.name;
                    img.title = file.name;
                    img.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block;';

                    const label = document.createElement('div');
                    label.style.cssText = 'position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,0.55);color:#fff;font-size:0.55rem;padding:2px 4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;';
                    label.textContent = file.name;

                    wrapper.appendChild(img);
                    wrapper.appendChild(label);
                    grid.appendChild(wrapper);
                };
                reader.readAsDataURL(file);
            });

            if (photoCount) photoCount.textContent = files.length;
            display.style.display = '';
            if (photoDrop) photoDrop.style.display = 'none';
            if (clearPhotosBtn) clearPhotosBtn.style.display = '';
        }

        // Optional photo selection
if (photoFiles) {
    photoFiles.addEventListener('change', function () {
        renderPhotoThumbs(this.files);
    });
}
        // Photo drop zone events
        if (photoDrop) {
            photoDrop.addEventListener('click', function() {
                if (photoFiles) photoFiles.click();
            });
            photoDrop.addEventListener('dragover', function(e) {
                e.preventDefault();
                this.style.borderColor = 'var(--success)';
                this.style.background = 'var(--success-soft)';
            });
            photoDrop.addEventListener('dragleave', function(e) {
                e.preventDefault();
                this.style.borderColor = '#a5b4fc';
                this.style.background = 'white';
            });
            photoDrop.addEventListener('drop', function(e) {
                e.preventDefault();
                this.style.borderColor = '#a5b4fc';
                this.style.background = 'white';
                const files = e.dataTransfer.files;
                if (files && files.length) {
                    // Transfer dropped files to the file input
                    const dt = new DataTransfer();
                    Array.from(files).forEach(f => dt.items.add(f));
                    photoFiles.files = dt.files;
                    renderPhotoThumbs(files);
                }
            });
        }

        // Download the template-specific Excel sample only after both selections.
        function downloadSample() {
            if (!organizationSelect?.value || !templateSelect?.value) {
                alert('Select Organization and Template first.');
                return;
            }
            window.location.href = 'bulk_upload.php?download_sample=1&organization_id='
                + encodeURIComponent(organizationSelect.value) + '&template_id=' + encodeURIComponent(templateSelect.value);
        }
        if (downloadSampleBtn) {
            downloadSampleBtn.addEventListener('click', downloadSample);
        }

        // Form validation
        const uploadForm = document.getElementById('uploadForm');
        if (uploadForm) {
            uploadForm.addEventListener('submit', function (e) {
                if (!organizationSelect?.value || !templateSelect?.value) {
                    e.preventDefault();
                    alert('Select Organization and Template before importing.');
                    return false;
                }
                if (!fileInput.files || !fileInput.files[0]) {
                    e.preventDefault();
                    alert('Please select an Excel or CSV file to upload.');
                    return false;
                }

                const file = fileInput.files[0];
                const ext = file.name.split('.').pop().toLowerCase();
                if (!['csv', 'xlsx', 'xls'].includes(ext)) {
                    e.preventDefault();
                    alert('Please upload a CSV, XLSX, or XLS file.');
                    return false;
                }

                if (file.size > 10 * 1024 * 1024) {
                    e.preventDefault();
                    alert('File size exceeds 10MB limit.');
                    return false;
                }

                return true;
            });
        }

        // Keyboard shortcut: Ctrl+Enter to submit
        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                const btn = document.getElementById('previewBtn') || document.getElementById('confirmImportBtn');
                if (btn) btn.click();
            }
        });

        // Touch-friendly
        if ('ontouchstart' in window) {
            document.querySelectorAll('.btn, .form-control, .form-select').forEach(el => {
                el.addEventListener('touchstart', function () {
                    this.style.opacity = '0.8';
                });
                el.addEventListener('touchend', function () {
                    this.style.opacity = '1';
                });
            });
        }
    </script>
</body>

</html>