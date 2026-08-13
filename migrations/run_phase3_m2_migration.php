<?php
/**
 * Phase 3 M2 migration runner — verify/skip/apply.
 */
declare(strict_types=1);
require dirname(__DIR__) . '/config.php';

$report = ['added_columns' => [], 'skipped' => [], 'executed' => [], 'errors' => []];

function col_exists(PDO $pdo, string $table, string $column): bool
{
    $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $s->execute([$table, $column]);
    return (int)$s->fetchColumn() > 0;
}

function add_col(PDO $pdo, array &$report, string $table, string $column, string $ddl): void
{
    if (col_exists($pdo, $table, $column)) {
        $report['skipped'][] = "{$table}.{$column}";
        return;
    }
    $pdo->exec($ddl);
    $report['added_columns'][] = "{$table}.{$column}";
    $report['executed'][] = $ddl;
}

try {
    add_col($pdo, $report, 'card_templates', 'layout_version',
        'ALTER TABLE card_templates ADD COLUMN layout_version INT NOT NULL DEFAULT 1 AFTER mirror_print');

    $tf = [
        ['object_type', "ALTER TABLE template_fields ADD COLUMN object_type VARCHAR(32) NOT NULL DEFAULT 'dynamic' AFTER field_key"],
        ['content', 'ALTER TABLE template_fields ADD COLUMN content TEXT NULL AFTER show_label'],
        ['image_path', 'ALTER TABLE template_fields ADD COLUMN image_path VARCHAR(255) NULL AFTER content'],
        ['z_index', 'ALTER TABLE template_fields ADD COLUMN z_index INT NOT NULL DEFAULT 0 AFTER image_path'],
        ['font_weight', 'ALTER TABLE template_fields ADD COLUMN font_weight VARCHAR(16) NULL AFTER font_family'],
        ['font_style', 'ALTER TABLE template_fields ADD COLUMN font_style VARCHAR(16) NULL AFTER font_weight'],
        ['text_decoration', 'ALTER TABLE template_fields ADD COLUMN text_decoration VARCHAR(32) NULL AFTER text_align'],
        ['opacity', 'ALTER TABLE template_fields ADD COLUMN opacity DECIMAL(4,3) NOT NULL DEFAULT 1.000 AFTER text_decoration'],
        ['border_width', 'ALTER TABLE template_fields ADD COLUMN border_width DECIMAL(6,2) NULL DEFAULT NULL AFTER opacity'],
        ['border_color', 'ALTER TABLE template_fields ADD COLUMN border_color VARCHAR(32) NULL DEFAULT NULL AFTER border_width'],
        ['border_style', 'ALTER TABLE template_fields ADD COLUMN border_style VARCHAR(16) NULL DEFAULT NULL AFTER border_color'],
        ['border_radius', 'ALTER TABLE template_fields ADD COLUMN border_radius DECIMAL(6,2) NULL DEFAULT NULL AFTER border_style'],
    ];
    foreach ($tf as [$col, $ddl]) {
        add_col($pdo, $report, 'template_fields', $col, $ddl);
    }

    // Allow NULL field_key
    $pdo->exec('ALTER TABLE template_fields MODIFY field_key VARCHAR(64) NULL DEFAULT NULL');
    $report['executed'][] = 'MODIFY template_fields.field_key NULL';

    $pdo->beginTransaction();
    $n = $pdo->exec("UPDATE template_fields SET object_type = CASE
        WHEN LOWER(COALESCE(field_key,'')) IN ('photo','pic') THEN 'photo'
        WHEN LOWER(COALESCE(field_key,'')) = 'logo' THEN 'logo'
        WHEN LOWER(COALESCE(field_key,'')) IN ('qr','qrcode') THEN 'qr'
        WHEN LOWER(COALESCE(field_key,'')) LIKE '%barcode%' THEN 'barcode'
        WHEN LOWER(COALESCE(field_key,'')) LIKE '%signature%' THEN 'signature'
        ELSE 'dynamic'
    END WHERE object_type = 'dynamic' OR object_type = '' OR object_type IS NULL");
    $report['executed'][] = "Backfill object_type (affected≈{$n})";
    $pdo->commit();

    echo json_encode($report, JSON_PRETTY_PRINT) . PHP_EOL;
    echo "STATUS: SUCCESS\n";
    exit(0);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
