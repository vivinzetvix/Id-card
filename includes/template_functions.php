<?php
/**
 * Template Functions - Helper functions for ID Card Templates
 */

if (!function_exists('id_card_design_css')) {
    function id_card_design_css(bool $includeHover = true): string
    {
        $hoverCss = $includeHover ? "
        .id-card:hover {
            transform: translateY(-5px) scale(1.02);
            box-shadow: var(--shadow-2xl);
        }
" : '';

        return <<<CSS
        .id-card-wrapper {
            display: flex;
            gap: 2rem;
            flex-wrap: wrap;
            justify-content: center;
            transition: transform 0.3s ease;
        }

        .id-card {
            width: 330px;
            height: 520px;
            background: #ffffff;
            border-radius: 16px;
            overflow: hidden;
            border: 1px solid var(--neutral-200);
            box-shadow: var(--shadow-xl);
            position: relative;
            display: flex;
            flex-direction: column;
            transition: all 0.3s ease;
        }

        .id-card.portrait {
            width: 330px;
            height: 520px;
        }

        .id-card.landscape {
            width: 520px;
            height: 330px;
        }
        {$hoverCss}
        .card-content {
            width: 100%;
            height: 100%;
            display: flex;
            flex-direction: column;
        }

        .front .card-header {
            text-align: center;
            padding: 20px;
            line-height: 4.5;
            padding-top: 30px;
            position: relative;
            color: #fff;
        }

        .front .card-header::after {
            content: "";
            position: absolute;
            left: 0;
            bottom: -40px;
            width: 100%;
            height: 80px;
            background: inherit;
            border-bottom-left-radius: 50% 100%;
            border-bottom-right-radius: 50% 100%;
        }

        .front .school-name {
            font-size: 20px;
            font-weight: 700;
            letter-spacing: 0.5px;
            line-height: 1.3;
        }

        .front .school-slogan {
            font-size: 12px;
            opacity: 0.9;
            margin-top: 5px;
        }

        .photo-container {
            position: relative;
            display: flex;
            justify-content: center;
            margin-top: -20px;
            z-index: 2;
        }

        .photo-frame {
            width: 100px;
            height: 100px;
            border-radius: 50%;
            background: white;
            box-shadow: var(--shadow-md);
            display: flex;
            justify-content: center;
            align-items: center;
            overflow: hidden;
        }

        .student-photo {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .student-info {
            padding: 20px;
            padding-left: 50px;
            flex-grow: 1;
        }

        .info-row {
            display: flex;
            margin: 16px 0;
            align-items: flex-start;
            font-size: 12px;
        }

        .info-label {
            font-weight: 600;
            padding-left: 20px;
            color: #374151;
            min-width: 100px;
            font-size: 11px;
        }

        .info-value {
            color: #4b5563;
            flex: 1;
            padding-left: 20px;
            border-left: 1px solid #e5e7eb;
            font-size: 11px;
            word-break: break-word;
        }

        .front .card-footer {
            text-align: center;
            padding: 12px;
            font-size: 10px;
            letter-spacing: 0.3px;
        }

        .back .card-header {
            text-align: center;
            padding: 15px;
        }

        .back .card-title {
            font-size: 14px;
            font-weight: 600;
            margin: 0;
        }

        .back-content {
            padding: 20px;
            flex-grow: 1;
            box-sizing: border-box;
            width: 100%;
            max-width: 100%;
            overflow: hidden;
            word-wrap: break-word;
        }

        .terms-list {
            font-size: 9px;
            line-height: 1.6;
            color: var(--neutral-500);
            margin-bottom: 15px;
            padding-left: 20px;
        }

        .terms-list li {
            margin-bottom: 5px;
        }

        .barcode-area {
            width: 100%;
            background: var(--neutral-100);
            border-radius: var(--radius-md);
            display: flex;
            justify-content: center;
            align-items: center;
            font-family: 'Libre Barcode 128', cursive;
            border: 1px dashed var(--neutral-300);
            margin: 15px 0;
        }

        .contact-details {
            background: var(--neutral-100);
            border-radius: var(--radius-md);
            padding: 12px;
            margin: 15px 0;
        }

        .contact-row {
            display: flex;
            margin: 6px 0;
            font-size: 10px;
        }

        .date-section {
            display: flex;
            gap: 10px;
            margin: 15px 0;
        }

        .date-box {
            flex: 1;
            text-align: center;
            padding: 8px;
            background: var(--neutral-100);
            border-radius: var(--radius-md);
        }

        .date-label {
            font-weight: 600;
            color: var(--neutral-700);
            font-size: 10px;
            margin-bottom: 4px;
        }

        .date-value {
            color: var(--neutral-600);
            font-size: 10px;
        }

        .school-branding {
            text-align: center;
            margin: 15px 0;
        }

        .school-logo-text {
            font-weight: 500;
            font-size: 6px;
            letter-spacing: 0.5px;
        }

        .school-tagline {
            color: var(--neutral-500);
            font-size: 9px;
            margin-top: 3px;
        }

        .signature-area {
            text-align: center;
            margin-top: 15px;
        }

        .signature-line {
            border-top: 1px solid var(--neutral-300);
            width: 120px;
            margin: 0 auto 5px;
        }

        .signature-text {
            font-size: 10px;
            color: var(--neutral-500);
        }
CSS;
    }
}

if (!function_exists('format_dynamic_field_display_value')) {
    function format_dynamic_field_display_value(array $fieldRecord, array $languages = []): string
    {
        $fieldKey = $fieldRecord['field_key'] ?? '';
        $fieldType = $fieldRecord['field_type'] ?? 'text';
        $fieldValue = $fieldRecord['field_value'] ?? '';
        $fieldValueSecondary = $fieldRecord['field_value_secondary'] ?? '';

        if (empty($fieldValue) && empty($fieldValueSecondary)) {
            return '';
        }

        if ($fieldType === 'date') {
            $timestamp = strtotime($fieldValue);
            return $timestamp ? date('d/m/Y', $timestamp) : $fieldValue;
        }

        if ($fieldType === 'barcode' || $fieldType === 'qr') {
            return $fieldValue;
        }

        if (!empty($languages) && !empty($fieldValueSecondary)) {
            $primaryLang = $languages[0] ?? 'en';
            $secondaryLang = $languages[1] ?? 'ta';
            return $fieldValue . ' / ' . $fieldValueSecondary;
        }

        return $fieldValue;
    }
}

if (!function_exists('get_field_type_badge')) {
    function get_field_type_badge(string $type): string
    {
        $colors = [
            'text' => 'secondary',
            'textarea' => 'info',
            'number' => 'success',
            'date' => 'warning',
            'select' => 'primary',
            'barcode' => 'danger',
            'qr' => 'dark',
            'photo' => 'primary',
            'signature' => 'info',
            'logo' => 'success'
        ];
        $color = $colors[$type] ?? 'secondary';
        return '<span class="badge bg-' . $color . '">' . ucfirst($type) . '</span>';
    }
}

if (!function_exists('get_field_type_icon')) {
    function get_field_type_icon(string $type): string
    {
        $icons = [
            'text' => 'fa-font',
            'textarea' => 'fa-align-left',
            'number' => 'fa-hashtag',
            'date' => 'fa-calendar',
            'select' => 'fa-list',
            'barcode' => 'fa-barcode',
            'qr' => 'fa-qrcode',
            'photo' => 'fa-camera',
            'signature' => 'fa-pen',
            'logo' => 'fa-image'
        ];
        return $icons[$type] ?? 'fa-cog';
    }
}

if (!function_exists('get_orientation_label')) {
    function get_orientation_label(string $orientation): string
    {
        return $orientation === 'landscape' ? 'Landscape' : 'Portrait';
    }
}

if (!function_exists('get_orientation_icon')) {
    function get_orientation_icon(string $orientation): string
    {
        return $orientation === 'landscape' ? 'fa-arrows-alt-h' : 'fa-arrows-alt-v';
    }
}

if (!function_exists('get_side_label')) {
    function get_side_label(string $side): string
    {
        return $side === 'front' ? 'Front' : 'Back';
    }
}

if (!function_exists('get_project_type_label')) {
    function get_project_type_label(?string $type): string
    {
        return $type === 'residence' ? 'Residence' : 'Corporate';
    }
}
?>