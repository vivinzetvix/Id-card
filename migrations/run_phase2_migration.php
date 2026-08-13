<?php
/**
 * Phase 2 migration runner — verify, skip-if-exists, apply, report.
 * DDL statements auto-commit on MariaDB; DML runs in a transaction.
 */
declare(strict_types=1);

require dirname(__DIR__) . '/config.php';

$report = [
    'executed' => [],
    'skipped' => [],
    'added_columns' => [],
    'added_indexes' => [],
    'backfilled' => [],
    'errors' => [],
];

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?'
    );
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

function add_column_if_missing(PDO $pdo, array &$report, string $table, string $column, string $ddl): void
{
    if (column_exists($pdo, $table, $column)) {
        $report['skipped'][] = "Column {$table}.{$column} already exists — skipped";
        return;
    }
    $pdo->exec($ddl);
    $report['executed'][] = $ddl;
    $report['added_columns'][] = "{$table}.{$column}";
}

function add_index_if_missing(PDO $pdo, array &$report, string $table, string $index, string $ddl): void
{
    if (index_exists($pdo, $table, $index)) {
        $report['skipped'][] = "Index {$table}.{$index} already exists — skipped";
        return;
    }
    $pdo->exec($ddl);
    $report['executed'][] = $ddl;
    $report['added_indexes'][] = "{$table}.{$index}";
}

try {
    echo "=== Phase 2 Migration Runner ===\n";
    echo "Database: id\n";
    echo "Note: ALTER TABLE auto-commits on MariaDB; DML uses a transaction.\n\n";

    // --- DDL: columns ---
    add_column_if_missing(
        $pdo,
        $report,
        'template_fields',
        'text_align',
        "ALTER TABLE `template_fields` ADD COLUMN `text_align` ENUM('left','center','right') NOT NULL DEFAULT 'left' AFTER `color`"
    );

    add_column_if_missing(
        $pdo,
        $report,
        'template_input_fields',
        'default_value',
        'ALTER TABLE `template_input_fields` ADD COLUMN `default_value` TEXT NULL AFTER `placeholder`'
    );

    add_column_if_missing(
        $pdo,
        $report,
        'template_input_fields',
        'archived_at',
        'ALTER TABLE `template_input_fields` ADD COLUMN `archived_at` TIMESTAMP NULL DEFAULT NULL AFTER `is_enabled`'
    );

    add_column_if_missing(
        $pdo,
        $report,
        'template_fields',
        'archived_at',
        'ALTER TABLE `template_fields` ADD COLUMN `archived_at` TIMESTAMP NULL DEFAULT NULL AFTER `visible`'
    );

    add_column_if_missing(
        $pdo,
        $report,
        'audit_log',
        'organization_id',
        'ALTER TABLE `audit_log` ADD COLUMN `organization_id` INT NULL DEFAULT NULL AFTER `user_id`'
    );

    // --- DDL: indexes ---
    add_index_if_missing(
        $pdo,
        $report,
        'audit_log',
        'idx_audit_organization',
        'ALTER TABLE `audit_log` ADD KEY `idx_audit_organization` (`organization_id`)'
    );

    add_index_if_missing(
        $pdo,
        $report,
        'id_members',
        'idx_member_template',
        'ALTER TABLE `id_members` ADD KEY `idx_member_template` (`template_id`)'
    );

    add_index_if_missing(
        $pdo,
        $report,
        'id_members',
        'idx_member_deleted',
        'ALTER TABLE `id_members` ADD KEY `idx_member_deleted` (`deleted_at`)'
    );

    add_index_if_missing(
        $pdo,
        $report,
        'template_input_fields',
        'idx_tif_template_enabled',
        'ALTER TABLE `template_input_fields` ADD KEY `idx_tif_template_enabled` (`template_id`, `is_enabled`, `archived_at`)'
    );

    add_index_if_missing(
        $pdo,
        $report,
        'template_fields',
        'idx_tf_template_side',
        'ALTER TABLE `template_fields` ADD KEY `idx_tf_template_side` (`template_id`, `side`, `archived_at`)'
    );

    add_index_if_missing(
        $pdo,
        $report,
        'card_templates',
        'idx_template_org_status',
        'ALTER TABLE `card_templates` ADD KEY `idx_template_org_status` (`organization_id`, `status`, `deleted_at`)'
    );

    // --- DML in transaction ---
    $pdo->beginTransaction();

    // Audit backfill
    $stmt = $pdo->prepare(
        'UPDATE audit_log a
         INNER JOIN users u ON u.id = a.user_id
         SET a.organization_id = u.organization_id
         WHERE a.organization_id IS NULL AND u.organization_id IS NOT NULL'
    );
    $stmt->execute();
    $auditBackfill = $stmt->rowCount();
    $report['executed'][] = 'UPDATE audit_log SET organization_id FROM users (backfill)';
    $report['backfilled'][] = "audit_log.organization_id ← users: {$auditBackfill} row(s)";

    // generated_cards backfill (column already exists — verify relationship)
    if (!column_exists($pdo, 'generated_cards', 'organization_id')) {
        throw new RuntimeException('generated_cards.organization_id missing — unexpected; aborting backfill');
    }
    $nullBefore = (int)$pdo->query(
        'SELECT COUNT(*) FROM generated_cards WHERE organization_id IS NULL'
    )->fetchColumn();
    $stmt = $pdo->prepare(
        'UPDATE generated_cards g
         INNER JOIN id_members m ON m.id = g.member_id
         SET g.organization_id = m.organization_id
         WHERE g.organization_id IS NULL AND m.organization_id IS NOT NULL'
    );
    $stmt->execute();
    $cardsBackfill = $stmt->rowCount();
    $nullAfter = (int)$pdo->query(
        'SELECT COUNT(*) FROM generated_cards WHERE organization_id IS NULL'
    )->fetchColumn();
    $report['executed'][] = 'UPDATE generated_cards SET organization_id FROM id_members (backfill)';
    $report['backfilled'][] = "generated_cards.organization_id ← id_members: {$cardsBackfill} row(s) (NULL before={$nullBefore}, NULL after={$nullAfter})";

    // role_permissions seed
    $insSa = $pdo->exec(
        "INSERT IGNORE INTO role_permissions (role_id, permission_id)
         SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
         WHERE LOWER(r.role_name) IN ('super admin', 'super_admin')"
    );
    $report['executed'][] = 'INSERT IGNORE role_permissions for Super Admin';
    $report['backfilled'][] = "role_permissions Super Admin inserts attempted (affected≈{$insSa})";

    $insOa = $pdo->exec(
        "INSERT IGNORE INTO role_permissions (role_id, permission_id)
         SELECT r.id, p.id FROM roles r
         JOIN permissions p ON (
              (p.module_name = 'Dashboard' AND p.permission_name IN ('View','Export'))
           OR (p.module_name = 'Organizations' AND p.permission_name IN ('View','Edit','Print','Export'))
           OR (p.module_name = 'Members' AND p.permission_name IN ('View','Create','Edit','Delete','Print','Export','Import'))
           OR (p.module_name = 'Templates' AND p.permission_name IN ('View','Create','Edit','Delete','Print','Export','Import'))
           OR (p.module_name = 'Generate ID' AND p.permission_name IN ('View','Create','Edit','Delete','Print','Export'))
           OR (p.module_name = 'Reports' AND p.permission_name IN ('View','Print','Export'))
         )
         WHERE LOWER(r.role_name) IN ('organization admin', 'organization_admin')"
    );
    $report['executed'][] = 'INSERT IGNORE role_permissions for Organization Admin';
    $report['backfilled'][] = "role_permissions Organization Admin inserts attempted (affected≈{$insOa})";

    $insReg = $pdo->exec(
        "INSERT IGNORE INTO role_permissions (role_id, permission_id)
         SELECT r.id, p.id FROM roles r
         JOIN permissions p ON (
              (p.module_name = 'Dashboard' AND p.permission_name = 'View')
           OR (p.module_name = 'Members' AND p.permission_name IN ('View','Create','Edit','Import','Export','Print'))
           OR (p.module_name = 'Templates' AND p.permission_name = 'View')
           OR (p.module_name = 'Generate ID' AND p.permission_name IN ('View','Print'))
         )
         WHERE LOWER(r.role_name) = 'registrar'"
    );
    $report['executed'][] = 'INSERT IGNORE role_permissions for Registrar';
    $report['backfilled'][] = "role_permissions Registrar inserts attempted (affected≈{$insReg})";

    // Soft-deactivate Finace
    $stmt = $pdo->prepare(
        "UPDATE roles
         SET status = 0,
             description = 'Archived test role (typo of Finance). Deactivated by Phase 2 migration. Safe to delete manually if unused.'
         WHERE id = 7 AND role_name = 'Finace' AND status = 1"
    );
    $stmt->execute();
    $finace = $stmt->rowCount();
    if ($finace > 0) {
        $report['executed'][] = 'UPDATE roles SET status=0 WHERE Finace';
        $report['backfilled'][] = "roles Finace soft-deactivated: {$finace} row(s)";
    } else {
        $report['skipped'][] = 'Finace role already inactive or not found — skipped';
    }

    $pdo->commit();

    // Final column verification
    $verifyCols = [
        'template_fields.text_align',
        'template_input_fields.default_value',
        'template_input_fields.archived_at',
        'template_fields.archived_at',
        'audit_log.organization_id',
        'generated_cards.organization_id',
    ];
    foreach ($verifyCols as $fq) {
        [$t, $c] = explode('.', $fq);
        $ok = column_exists($pdo, $t, $c);
        $report['executed'][] = "VERIFY {$fq}: " . ($ok ? 'OK' : 'MISSING');
        if (!$ok) {
            $report['errors'][] = "Post-check failed: {$fq} missing";
        }
    }

    $verifyIdx = [
        'audit_log.idx_audit_organization',
        'id_members.idx_member_template',
        'id_members.idx_member_deleted',
        'template_input_fields.idx_tif_template_enabled',
        'template_fields.idx_tf_template_side',
        'card_templates.idx_template_org_status',
    ];
    foreach ($verifyIdx as $fq) {
        [$t, $i] = explode('.', $fq);
        $ok = index_exists($pdo, $t, $i);
        $report['executed'][] = "VERIFY index {$fq}: " . ($ok ? 'OK' : 'MISSING');
        if (!$ok) {
            $report['errors'][] = "Post-check failed: index {$fq} missing";
        }
    }

    $reportFile = dirname(__DIR__) . '/backups/phase2_migration_report_' . date('Y-m-d_His') . '.json';
    file_put_contents($reportFile, json_encode($report, JSON_PRETTY_PRINT));

    echo "ADDED_COLUMNS:\n";
    foreach ($report['added_columns'] ?: ['(none)'] as $line) echo "  - {$line}\n";
    echo "ADDED_INDEXES:\n";
    foreach ($report['added_indexes'] ?: ['(none)'] as $line) echo "  - {$line}\n";
    echo "BACKFILLED:\n";
    foreach ($report['backfilled'] as $line) echo "  - {$line}\n";
    echo "SKIPPED:\n";
    foreach ($report['skipped'] ?: ['(none)'] as $line) echo "  - {$line}\n";
    echo "ERRORS:\n";
    foreach ($report['errors'] ?: ['(none)'] as $line) echo "  - {$line}\n";
    echo "\nReport written: {$reportFile}\n";
    echo empty($report['errors']) ? "STATUS: SUCCESS\n" : "STATUS: COMPLETED_WITH_ERRORS\n";
    exit(empty($report['errors']) ? 0 : 1);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'MIGRATION FAILED: ' . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
