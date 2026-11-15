<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__.'/../includes/security/environment.php';
require_once __DIR__.'/../includes/security/http.php';
require_once __DIR__.'/../includes/security/auth.php';
require_once __DIR__.'/../includes/database-preflight.php';
try {
    clevo_load_environment(); clevo_validate_environment(); $conn=clevo_connect_database();
    if (!clevo_stmt($conn,"SELECT Version FROM security_schema_migrations WHERE Version='001_auth_security'")->get_result()->fetch_assoc()) throw new RuntimeException('Apply security migration first.');
    $report=clevo_database_preflight($conn);
    echo json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
    if ($report['blockers']) { fwrite(STDERR,"Preflight blocked. Reconcile data without deleting history.\n"); exit(2); }
    if (!in_array('--apply',$argv,true)) { echo "Read-only preflight complete. Use --apply --maintenance after backup and rehearsal.\n"; exit; }
    if (!in_array('--maintenance',$argv,true)) throw new RuntimeException('Maintenance acknowledgement required.');
    if ((int)$conn->query("SELECT GET_LOCK(CONCAT(DATABASE(), '.security-migration'), 10) n")->fetch_assoc()['n']!==1) throw new RuntimeException('Migration lock unavailable.');
    // All writers must be stopped externally. MySQL DDL commits implicitly.
    $conn->query("CREATE TABLE IF NOT EXISTS database_migration_steps (Version VARCHAR(100) NOT NULL, StepNumber INT NOT NULL, Checksum CHAR(64) NOT NULL, State ENUM('started','applied') NOT NULL, PRIMARY KEY(Version,StepNumber)) ENGINE=InnoDB");
    foreach (['002_database_integrity','003_required_relationships','004_mobile_api','005_booking_workflow','006_commercial_payments'] as $version) {
        $source=file_get_contents(__DIR__.'/../migrations/'.$version.'.sql'); $hash=hash('sha256',$source);
        $done=clevo_stmt($conn,'SELECT Checksum FROM security_schema_migrations WHERE Version=?','s',[$version])->get_result()->fetch_assoc();
        if ($done) { if (!hash_equals($done['Checksum'],$hash)) throw new RuntimeException('Applied migration changed.'); echo "$version already applied.\n"; continue; }
        $sql=preg_replace('/^\s*--[^\r\n]*/m','',$source); $step=0;
        foreach (array_filter(array_map('trim',explode(';',$sql))) as $statement) {
            $step++;
            $row=clevo_stmt($conn,'SELECT Checksum, State FROM database_migration_steps WHERE Version=? AND StepNumber=?','si',[$version,$step])->get_result()->fetch_assoc();
            if ($row) {
                if (!hash_equals($row['Checksum'],$hash) || $row['State']!=='applied') throw new RuntimeException('Partial DDL needs manual reconciliation.');
                continue;
            }
            $skip=false;
            if ($version==='003_required_relationships') {
                if (!preg_match('/^ALTER TABLE `([^`]+)` ADD CONSTRAINT `[^`]+` FOREIGN KEY \(`([^`]+)`\) REFERENCES `([^`]+)` \(`([^`]+)`\) ON DELETE RESTRICT$/D',$statement,$fk)) throw new RuntimeException('Unsupported relationship migration.');
                $skip=(bool)clevo_stmt($conn,'SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? AND REFERENCED_TABLE_NAME=? AND REFERENCED_COLUMN_NAME=?','ssss',[$fk[1],$fk[2],$fk[3],$fk[4]])->get_result()->fetch_assoc();
                if (!$skip) {
                    $orphan=(int)$conn->query("SELECT COUNT(*) n FROM `$fk[1]` c LEFT JOIN `$fk[3]` p ON c.`$fk[2]`=p.`$fk[4]` WHERE c.`$fk[2]` IS NOT NULL AND p.`$fk[4]` IS NULL")->fetch_assoc()['n'];
                    if ($orphan) throw new RuntimeException('Missing relationship has orphan records.');
                }
            }
            clevo_stmt($conn,"INSERT INTO database_migration_steps VALUES (?, ?, ?, 'started')",'sis',[$version,$step,$hash]);
            if (!$skip) $conn->query($statement);
            clevo_stmt($conn,"UPDATE database_migration_steps SET State='applied' WHERE Version=? AND StepNumber=?",'si',[$version,$step]);
        }
        clevo_stmt($conn,'INSERT INTO security_schema_migrations (Version,Checksum) VALUES (?,?)','ss',[$version,$hash]);
        echo "$version applied.\n";
    }
} catch (Throwable $e) { fwrite(STDERR,'Database migration failed ('.get_class($e).', code '.(int)$e->getCode()."). Inspect journal/schema before retry.\n"); exit(1); }
