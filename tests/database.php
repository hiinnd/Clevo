<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require_once __DIR__.'/../includes/database-preflight.php';
if(clevo_env('APP_ENV')!=='testing'||clevo_env('DB_HOST')!=='127.0.0.1'||!preg_match('/^clevo_security_test_[a-f0-9]{12}$/D',clevo_env('DB_DATABASE','')??'')) { fwrite(STDERR,"Use tests/run-isolated.ps1.\n");exit(2); }
$setup=clevo_connect_database();
$data=$setup->query('SELECT @@datadir n')->fetch_assoc()['n'];
if(!clevo_path_within(realpath($data)?:$data,realpath(__DIR__.'/../.security-test')?:''))throw new RuntimeException('Disposable datadir required.');
function db_command(string $script,array $arguments=[]):array {
    $p=proc_open(array_merge([PHP_BINARY,__DIR__.'/../scripts/'.$script],$arguments),[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);return [proc_close($p),$out,$error];
}
function database_copy(mysqli $setup,bool $seed):mysqli {
    $name='clevo_database_test_'.bin2hex(random_bytes(6)); $setup->query("CREATE DATABASE `$name` CHARACTER SET utf8mb4"); putenv('DB_DATABASE='.$name); $db=clevo_connect_database();
    $source=file_get_contents(__DIR__.'/../migrations/000_legacy_schema.sql');preg_match_all('/CREATE TABLE(?: IF NOT EXISTS)?[\s\S]*?;/i',$source,$tables);
    foreach($tables[0] as $statement)$db->query($statement);
    if($seed) {
        $source=file_get_contents(__DIR__.'/../database/seeds/development.sql');$db->multi_query($source);
        do{if($r=$db->store_result())$r->free();}while($db->more_results()&&$db->next_result());
    }
    [$code]=db_command('migrate-security.php');check($code===0,'Security prerequisite applies to disposable '.($seed?'populated':'empty').' schema');
    return $db;
}
function db_snapshot(mysqli $db):array {
    $snapshot=[];$tables=$db->query('SHOW TABLES')->fetch_all(MYSQLI_NUM);
    foreach($tables as [$table]){
        if(in_array($table,['security_schema_migrations','security_migration_steps','database_migration_steps'],true))continue;
        $columns=array_column($db->query("SHOW COLUMNS FROM `$table`")->fetch_all(MYSQLI_ASSOC),'Field');
        $pk=$db->query("SHOW INDEX FROM `$table` WHERE Key_name='PRIMARY'")->fetch_all(MYSQLI_ASSOC);usort($pk,fn($a,$b)=>(int)$a['Seq_in_index']<=>(int)$b['Seq_in_index']);
        $order=implode(',',array_map(fn($r)=>'`'.$r['Column_name'].'`',$pk));
        $rows=$db->query("SELECT * FROM `$table`".($order?' ORDER BY '.$order:''))->fetch_all(MYSQLI_ASSOC);
        $snapshot[$table]=['columns'=>$columns,'hash'=>hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR)),'order'=>$order];
    }return $snapshot;
}
function assert_snapshot(mysqli $db,array $snapshot):void {
    foreach($snapshot as $table=>$before){$columns=implode(',',array_map(fn($c)=>'`'.$c.'`',$before['columns']));$rows=$db->query("SELECT $columns FROM `$table`".($before['order']?' ORDER BY '.$before['order']:''))->fetch_all(MYSQLI_ASSOC);check(hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR))===$before['hash'],'Migration preserves every legacy value: '.$table);}
}
function db_distinct_body(array $body):array {
    static $sequence=0;$sequence++;
    return array_replace($body,['booking_date'=>(new DateTimeImmutable('+'.(2+$sequence).' days'))->format('Y-m-d')]);
}
function db_race(array $left,array $right):array {
    $start=microtime(true)+0.6;$jobs=[];
    foreach([$left,$right]as $input){$p=proc_open([PHP_BINARY,__DIR__.'/database-race.php'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,dirname(__DIR__));fwrite($pipes[0],json_encode($input+['start'=>$start]));fclose($pipes[0]);$jobs[]=[$p,$pipes];}
    $results=[];foreach($jobs as [$p,$pipes]){$out=stream_get_contents($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($p);$results[]=json_decode($out,true)??['ok'=>false,'reason'=>'worker_failed'];check($code===0,'Concurrent worker exits cleanly');}return $results;
}

// Populated original source copy, without resetting any existing database.
$conn=database_copy($setup,true);$before=db_snapshot($conn);$report=clevo_database_preflight($conn);
check(!$report['blockers'],'Original populated snapshot passes non-destructive preflight');
check(($report['warnings']['cross_station_drivers']??0)===6,'Six original cross-station driver anomalies are reported, not rewritten');
[$code,$output]=db_command('migrate-database.php',['--apply','--maintenance']);check($code===0,'Versioned migrations apply to populated copy');
assert_snapshot($conn,$before);
[$code,$output]=db_command('migrate-database.php',['--apply','--maintenance']);check($code===0&&str_contains($output,'already applied'),'Migrations rerun without changing data');
assert_snapshot($conn,$before);
[$code]=db_command('initialize-database.php',['--empty-only']);check($code!==0,'Initializer refuses existing database');
rejects(fn()=>clevo_stmt($conn,'INSERT INTO wallet (UserID,Balance) VALUES (3,1)'),'Unique wallet rejects duplicate owners');
rejects(fn()=>$conn->query("INSERT INTO booking (Order_Number) SELECT Order_Number FROM booking WHERE Order_Number IS NOT NULL LIMIT 1"),'Unique order number rejects duplicate public references');
rejects(fn()=>$conn->query('INSERT INTO support_messages (TicketID,SenderRole,SenderID,Message) VALUES (1,\'admin\',2147483647,\'synthetic\')'),'Missing support sender relationship is now enforced');
check((bool)$conn->query("SELECT 1 FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='user' AND COLUMN_NAME='StationID' AND REFERENCED_TABLE_NAME='stations'")->fetch_assoc(),'Missing legacy user/station FK is added');

// Failed migration cannot silently trim/merge a second wallet or an orphan sender.
$anomaly=database_copy($setup,false);
$anomaly->query("INSERT INTO user (UserID,UserName,PhoneNumber,Password) VALUES (1,'Synthetic anomaly','0710000000','!disabled')");
$anomaly->query('INSERT INTO wallet (UserID,Balance) VALUES (1,5),(1,7)');
$anomaly->query("INSERT INTO support_tickets (subject) VALUES ('Synthetic')");
$anomaly->query("INSERT INTO support_messages (TicketID,SenderRole,SenderID,Message) VALUES (1,'admin',999,'Synthetic')");
[$code,$output]=db_command('migrate-database.php',['--apply','--maintenance']);
check($code===2&&str_contains($output,'duplicate_wallet_users')&&str_contains($output,'orphan_message_senders'),'Preflight blocks duplicate wallets and orphan senders before DDL');
check((int)$anomaly->query('SELECT SUM(Balance) n FROM wallet')->fetch_assoc()['n']===12,'Blocked preflight preserves both balances');
check(!(bool)$anomaly->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='request_idempotency'")->fetch_assoc(),'Blocked preflight creates no target tables');

// Empty-schema path, then synthetic transaction/concurrency scenarios.
$conn=database_copy($setup,false);[$code]=db_command('migrate-database.php',['--apply','--maintenance']);check($code===0,'Migrations apply to empty legacy copy');
$dbName=clevo_env('DB_DATABASE');
$conn->query("INSERT INTO user (UserID,UserName,PhoneNumber,Password,Role,AccountStatus) VALUES (1,'Customer','0710000001','!disabled','customer','active'),(2,'Owner','0710000002','!disabled','owner','active'),(3,'Driver','0710000003','!disabled','driver','active')");
$conn->query("INSERT INTO stations (UserID,Name,admin_status,Has_At_Station,Has_Home_Service,Has_Delivery) VALUES (2,'Synthetic','approved',1,1,1)");
$conn->query("UPDATE user SET StationID=1 WHERE UserID=3");
$conn->query('UPDATE stations SET BookingCapacity=1000,HomeCapacity=1000');
$conn->query("INSERT INTO services (StationID,Name,Price,is_station,is_home,is_pickup) VALUES (1,'Synthetic',10,1,1,1)");
$conn->query('INSERT INTO wallet (UserID,Balance) VALUES (1,100),(2,0)');
$conn->query('INSERT INTO loyalty (UserID,PointsBalance) VALUES (1,200),(1,200)');
$date=(new DateTimeImmutable('+2 days'))->format('Y-m-d');$body=['service_ids'=>[1],'service_location'=>'station','booking_date'=>$date,'booking_time'=>'10:00','use_plan'=>false];
$key=bin2hex(random_bytes(16));$r=clevo_create_booking($conn,1,1,$body,$key);$id=$r['booking_id'];
check(clevo_create_booking($conn,1,1,$body,$key)===$r,'Booking retry returns identical committed response');
rejects(fn()=>clevo_create_booking($conn,1,1,array_replace($body,['booking_time'=>'11:00']),$key),'Booking key reuse with changed request conflicts','idempotency_conflict');
check((int)$conn->query('SELECT COUNT(*) n FROM booking')->fetch_assoc()['n']===1,'Idempotent booking creates one booking');
check((int)$conn->query('SELECT COUNT(*) n FROM booking_services')->fetch_assoc()['n']===1,'Service snapshot is atomic and singular');
check($conn->query("SELECT ScheduledAtUtc FROM booking WHERE BookingID=$id")->fetch_assoc()['ScheduledAtUtc']==="$date 07:00:00",'Amman civil appointment is stored alongside explicit UTC instant');
$job=['op'=>'booking','user'=>1,'station'=>1,'body'=>$body,'key'=>bin2hex(random_bytes(16))];$race=db_race($job,$job);
check($race[0]['ok']&&$race[1]['ok']&&$race[0]['result']===$race[1]['result'],'Concurrent duplicate booking requests create one result');
$conn->query("UPDATE booking SET Status='Accepted' WHERE BookingID=$id");
$job=['op'=>'payment','user'=>1,'booking'=>$id,'key'=>bin2hex(random_bytes(16))];$race=db_race($job,$job);
check($race[0]['ok']&&$race[1]['ok'],'Concurrent same-key payment retries both return committed response');
check($conn->query('SELECT Balance FROM wallet WHERE UserID=1')->fetch_assoc()['Balance']==='90.00','Customer wallet debited exactly once');
check($conn->query('SELECT Balance FROM wallet WHERE UserID=2')->fetch_assoc()['Balance']==='9.50','Synthetic legacy owner credit reflects the newly approved five percent commission');
check((int)$conn->query("SELECT COUNT(*) n FROM payments WHERE BookingID=$id")->fetch_assoc()['n']===1,'One typed payment record exists');
rejects(fn()=>clevo_pay_booking($conn,1,$id,'wallet',false,bin2hex(random_bytes(16))),'A different payment key cannot debit a paid booking');
clevoRefundBookingPayment($conn,$id);clevoRefundBookingPayment($conn,$id);
check($conn->query('SELECT Balance FROM wallet WHERE UserID=1')->fetch_assoc()['Balance']==='100.00','Refund replay restores customer balance only once');
check($conn->query('SELECT Balance FROM wallet WHERE UserID=2')->fetch_assoc()['Balance']==='0.00','Refund reverses owner payout once');
rejects(fn()=>clevo_complete_owner_booking($conn,2,$id),'Refunded booking cannot be completed','payment_refunded');
$sqlMode=$conn->query('SELECT @@session.sql_mode n')->fetch_assoc()['n'];
check(str_contains($sqlMode,'STRICT_TRANS_TABLES'),'Application connections enforce strict transactional SQL mode');
$conn->query("SET SESSION sql_mode=''");$conn->query('UPDATE wallet SET Balance=99999999.99 WHERE UserID=2');
$ownerWallet=(int)$conn->query('SELECT WalletID FROM wallet WHERE UserID=2')->fetch_assoc()['WalletID'];
rejects(fn()=>clevo_db_atomic($conn,fn()=>clevo_wallet_entry($conn,$ownerWallet,100,'credit','synthetic:overflow',$id,'Synthetic overflow')),'Credit cannot silently truncate at DECIMAL limit even in permissive SQL mode','wallet_balance_limit');
check($conn->query('SELECT Balance FROM wallet WHERE UserID=2')->fetch_assoc()['Balance']==='99999999.99','Overflow rejection preserves balance exactly');
$conn->query('UPDATE wallet SET Balance=0 WHERE UserID=2');clevo_stmt($conn,'SET SESSION sql_mode=?','s',[$sqlMode]);

// Points and wallet failure must roll back together, including records and payment markers.
$b=clevo_create_booking($conn,1,1,db_distinct_body($body),bin2hex(random_bytes(16)));$failId=$b['booking_id'];$conn->query("UPDATE booking SET Status='Accepted' WHERE BookingID=$failId");
$conn->query('UPDATE wallet SET Balance=0 WHERE UserID=1');$conn->query('UPDATE loyalty SET PointsBalance=100 WHERE UserID=1');
rejects(fn()=>clevo_pay_booking($conn,1,$failId,'wallet',true,bin2hex(random_bytes(16))),'Insufficient wallet aborts combined points/payment operation','insufficient');
check(clevoGetLoyaltyPoints($conn,1)===200.0,'Failed payment restores all loyalty buckets');
check(empty($conn->query("SELECT Payment_Method FROM booking WHERE BookingID=$failId")->fetch_assoc()['Payment_Method']),'Failed payment leaves booking unpaid');
check(!(bool)$conn->query("SELECT 1 FROM payments WHERE BookingID=$failId")->fetch_assoc(),'Failed payment leaves no payment row');

// Distinct concurrent requests cannot overspend a wallet or reuse the final plan wash.
$conn->query('UPDATE wallet SET Balance=10 WHERE UserID=1');
$second=clevo_create_booking($conn,1,1,db_distinct_body($body),bin2hex(random_bytes(16)))['booking_id'];$conn->query("UPDATE booking SET Status='Accepted' WHERE BookingID=$second");
$left=['op'=>'payment','user'=>1,'booking'=>$failId,'key'=>bin2hex(random_bytes(16))];$right=['op'=>'payment','user'=>1,'booking'=>$second,'key'=>bin2hex(random_bytes(16))];$race=db_race($left,$right);
check(count(array_filter($race,fn($r)=>$r['ok']))===1,'Two bookings competing for one wallet balance yield one payment');
check($conn->query('SELECT Balance FROM wallet WHERE UserID=1')->fetch_assoc()['Balance']==='0.00','Concurrent wallet balance never becomes negative');
$conn->query("INSERT INTO plans (name_ar,name_en,price,wash_count) VALUES ('Synthetic','Synthetic',10,1)");
$conn->query("INSERT INTO user_plans (user_id,plan_id,start_date,end_date,remaining_washes) VALUES (1,1,CURDATE(),CURDATE()+INTERVAL 5 DAY,1)");
$planBody=array_replace($body,['use_plan'=>true,'booking_time'=>'12:00']);$left=['op'=>'plan','user'=>1,'station'=>1,'body'=>$planBody,'key'=>bin2hex(random_bytes(16))];$right=array_replace($left,['key'=>bin2hex(random_bytes(16)),'body'=>array_replace($planBody,['booking_time'=>'13:00'])]);$race=db_race($left,$right);
check(count(array_filter($race,fn($r)=>$r['ok']))===1,'Only one concurrent booking consumes final plan wash');
$planBooking=$race[0]['ok']?$race[0]['result']['booking_id']:$race[1]['result']['booking_id'];
clevoRestorePlanWashForBooking($conn,$planBooking);clevoRestorePlanWashForBooking($conn,$planBooking);
check((int)$conn->query('SELECT remaining_washes FROM user_plans WHERE id=1')->fetch_assoc()['remaining_washes']===1,'Plan restoration is single-use');

clevo_db_begin($conn);clevo_stmt($conn,'UPDATE wallet SET Balance=50 WHERE UserID=1');
$nested=clevo_create_booking($conn,1,1,db_distinct_body($body),bin2hex(random_bytes(16)))['booking_id'];clevo_db_rollback($conn);
check(!(bool)$conn->query("SELECT 1 FROM booking WHERE BookingID=$nested")->fetch_assoc(),'Inner service cannot commit outer transaction');
check($conn->query('SELECT Balance FROM wallet WHERE UserID=1')->fetch_assoc()['Balance']==='0.00','Outer rollback restores preceding wallet mutation');

$conn->query('UPDATE wallet SET Balance=20 WHERE UserID=1');
$failure=clevo_create_booking($conn,1,1,db_distinct_body($body),bin2hex(random_bytes(16)))['booking_id'];
$conn->query("UPDATE booking SET Status='Accepted' WHERE BookingID=$failure");
$walletID=(int)$conn->query('SELECT WalletID FROM wallet WHERE UserID=1')->fetch_assoc()['WalletID'];
clevo_stmt($conn,"INSERT INTO wallet_transactions (WalletID,Amount,TransactionType,OperationKey,BookingID) VALUES (?,0,'debit',?,?)",'isi',[$walletID,"booking:$failure:payment",$failure]);
rejects(fn()=>clevo_pay_booking($conn,1,$failure,'wallet',true,bin2hex(random_bytes(16))),'Injected ledger constraint failure aborts settlement');
check($conn->query('SELECT Balance FROM wallet WHERE UserID=1')->fetch_assoc()['Balance']==='20.00'&&clevoGetLoyaltyPoints($conn,1)===200.0,'Ledger failure rolls back wallet debit and points together');
check(!(bool)$conn->query("SELECT 1 FROM payments WHERE BookingID=$failure")->fetch_assoc(),'Injected failure leaves no typed payment');

$conn->query('UPDATE loyalty SET PointsBalance=0 WHERE UserID=1');$conn->query('UPDATE loyalty SET PointsBalance=1 WHERE LoyaltyID=(SELECT n FROM (SELECT MIN(LoyaltyID) n FROM loyalty WHERE UserID=1) x)');
$quote=clevoPreviewPointsDiscount($conn,1,10,true);
check(clevo_db_money($quote['discount'])+clevo_db_money($quote['payable'])===1000&&$quote['discount']===0.03,'Half-cent points rounding preserves total = discount + payable');
$conn->query('UPDATE loyalty SET PointsBalance=100 WHERE UserID=1');
$cash=clevo_create_booking($conn,1,1,db_distinct_body($body),bin2hex(random_bytes(16)))['booking_id'];clevo_accept_owner_booking($conn,2,$cash);clevo_accept_owner_booking($conn,2,$cash);
clevo_pay_booking($conn,1,$cash,'cash',false,bin2hex(random_bytes(16)));$pointsBefore=clevoGetLoyaltyPoints($conn,1);
clevo_complete_owner_booking($conn,2,$cash);clevo_complete_owner_booking($conn,2,$cash);
check(clevoGetLoyaltyPoints($conn,1)===$pointsBefore+10,'Repeated owner completion awards loyalty only once');
check($conn->query("SELECT Status FROM payments WHERE BookingID=$cash")->fetch_assoc()['Status']==='recorded','Cash declaration stays pending until authorized completion');

$conn->query('UPDATE wallet SET Balance=100 WHERE UserID=1');$purchaseKey=bin2hex(random_bytes(16));$subscription=clevo_purchase_plan($conn,1,1,$purchaseKey);
$conn->query('UPDATE plans SET price=15,wash_count=3 WHERE id=1');
check(clevo_purchase_plan($conn,1,1,$purchaseKey)===$subscription,'Plan purchase replay does not purchase again after catalog price changes');
$purchase=$conn->query('SELECT PurchasePrice,PurchasedWashes FROM user_plans WHERE id='.$subscription['subscription_id'])->fetch_assoc();
check($purchase['PurchasePrice']==='10.00'&&(int)$purchase['PurchasedWashes']===1,'Subscription retains immutable price/wash snapshot');
check($conn->query('SELECT Balance FROM wallet WHERE UserID=1')->fetch_assoc()['Balance']==='90.00','Subscription debit occurs once');
$conn->query('UPDATE wallet SET Balance=20 WHERE UserID=2');
clevoSaveOwnerBankAccount($conn,2,['account_holder_name'=>'Synthetic','bank_name'=>'Synthetic','iban'=>'JO00TEST00000000000000000000000']);
$withdrawKey=bin2hex(random_bytes(16));$withdrawal=clevo_request_withdrawal($conn,2,$withdrawKey);
check(clevo_request_withdrawal($conn,2,$withdrawKey)===$withdrawal,'Withdrawal retry returns same reserve/request');
check($conn->query('SELECT Balance FROM wallet WHERE UserID=2')->fetch_assoc()['Balance']==='0.00','Withdrawal reserve is debited once');

$deliveryBody=array_replace($body,['service_location'=>'delivery','latitude'=>31.95,'longitude'=>35.91]);
$delivery=clevo_create_booking($conn,1,1,$deliveryBody,bin2hex(random_bytes(16)))['booking_id'];
$conn->query("UPDATE booking SET DriverID=3,Status='Accepted' WHERE BookingID=$delivery");
clevo_stmt($conn,'INSERT INTO driver_assignments (BookingID,DriverID,StationID,AssignedBy) VALUES (?,3,1,2)','i',[$delivery]);
rejects(fn()=>clevo_stmt($conn,'INSERT INTO driver_assignments (BookingID,DriverID,StationID,AssignedBy) VALUES (?,3,1,2)','i',[$delivery]),'Only one active assignment is allowed per booking');
clevo_pay_booking($conn,1,$delivery,'wallet',false,bin2hex(random_bytes(16)));clevo_delivery_adopt($conn,2,$delivery);clevo_driver_transition($conn,3,$delivery,'In Progress');
check(clevo_record_driver_location($conn,3,$delivery,31.95,35.91),'Authorized driver location updates cache and history');
check(clevo_record_driver_location($conn,3,$delivery,31.95,35.91),'Stationary GPS samples remain valid');
check((int)$conn->query("SELECT COUNT(*) n FROM tracking_records WHERE BookingID=$delivery")->fetch_assoc()['n']===2,'Both GPS samples are preserved');
check(!clevo_record_driver_location($conn,3,$cash,31,35),'Driver cannot append GPS to unassigned job');

$bookingsBefore=(int)$conn->query('SELECT COUNT(*) n FROM booking')->fetch_assoc()['n'];
rejects(fn()=>clevo_archive_catalog($conn,1,'service',1),'Customer cannot archive station services','forbidden');
clevo_archive_catalog($conn,2,'service',1);
check((int)$conn->query('SELECT COUNT(*) n FROM booking')->fetch_assoc()['n']===$bookingsBefore,'Service archiving preserves every booking');
check((bool)$conn->query('SELECT ServiceName FROM booking_services LIMIT 1')->fetch_assoc(),'Historical service snapshots survive archiving');
rejects(fn()=>clevo_create_booking($conn,1,1,db_distinct_body($body),bin2hex(random_bytes(16))),'Archived service cannot receive new bookings','service_unavailable');
$conn->query("INSERT INTO user (UserID,UserName,PhoneNumber,Password,Role,AccountStatus) VALUES (4,'Synthetic admin','0710000004','!disabled','Admin','active')");
clevo_archive_catalog($conn,4,'station',1);clevo_archive_catalog($conn,4,'plan',1);
check((int)$conn->query('SELECT COUNT(*) n FROM booking')->fetch_assoc()['n']===$bookingsBefore,'Station archiving preserves booking history');
check((int)$conn->query('SELECT COUNT(*) n FROM user_plans')->fetch_assoc()['n']===2,'Plan retirement preserves active/historical subscriptions');

// Refuse checksum drift and ambiguous partial-DDL state; never retry blindly.
$checksum=$conn->query("SELECT Checksum FROM security_schema_migrations WHERE Version='002_database_integrity'")->fetch_assoc()['Checksum'];
$conn->query("UPDATE security_schema_migrations SET Checksum=REPEAT('0',64) WHERE Version='002_database_integrity'");
[$code]=db_command('migrate-database.php',['--apply','--maintenance']);check($code!==0,'Applied migration checksum drift is rejected');
clevo_stmt($conn,"UPDATE security_schema_migrations SET Checksum=? WHERE Version='002_database_integrity'",'s',[$checksum]);
$partial=database_copy($setup,false);
$partial->query("CREATE TABLE database_migration_steps (Version VARCHAR(100),StepNumber INT,Checksum CHAR(64),State ENUM('started','applied'),PRIMARY KEY(Version,StepNumber)) ENGINE=InnoDB");
clevo_stmt($partial,"INSERT INTO database_migration_steps VALUES ('002_database_integrity',1,?,'started')",'s',[hash_file('sha256',__DIR__.'/../migrations/002_database_integrity.sql')]);
[$code]=db_command('migrate-database.php',['--apply','--maintenance']);check($code!==0,'Ambiguous started DDL step requires operator reconciliation');
check(!(bool)$partial->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments'")->fetch_assoc(),'Partial-step refusal adds no financial tables');
putenv('DB_DATABASE='.$dbName);

// Non-expiring accepted station bookings must not starve the bounded expiry batch.
for($i=0;$i<201;$i++)$conn->query("INSERT INTO booking (UserID,StationID,ServiceID,Status,Service_Location,Payment_Method,created_at) VALUES (1,1,1,'Accepted','station','cash',UTC_TIMESTAMP()-INTERVAL 1 HOUR)");
$conn->query("INSERT INTO booking (UserID,StationID,ServiceID,Status,created_at) VALUES (1,1,1,'Pending',UTC_TIMESTAMP()-INTERVAL 11 MINUTE)");$expiredBooking=(int)$conn->insert_id;
cancelExpiredBookings($conn,1);
check($conn->query("SELECT Status FROM booking WHERE BookingID=$expiredBooking")->fetch_assoc()['Status']==='Cancelled_Timeout','Expiry batch cannot be starved by 201 non-expiring accepted station bookings');

// Metadata/source compatibility: prepare static DML without executing writes.
$prepared=0;$prepareFailures=[];
$iterator=new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator(dirname(__DIR__),FilesystemIterator::SKIP_DOTS),
    static fn($file)=>!$file->isDir()||!in_array($file->getFilename(),['.git','vendor','.security-test','tests','migrations','scripts'],true)));
foreach($iterator as $file){$path=str_replace('\\','/',$file->getPathname());if($file->getExtension()!=='php'||preg_match('~/(vendor|tests|migrations|scripts|\.git|\.security-test)/~',$path))continue;
    foreach(token_get_all(file_get_contents($path)) as $token){if(!is_array($token)||$token[0]!==T_CONSTANT_ENCAPSED_STRING)continue;$literal=$token[1];$sql=substr($literal,1,-1);$sql=$literal[0]==="'"?str_replace(["\\'","\\\\"],["'","\\"],$sql):stripcslashes($sql);if(!preg_match('/^\s*(SELECT|INSERT INTO|UPDATE|DELETE FROM)\b/i',$sql)||str_contains($sql,'$')||str_contains($sql,'?\'')||str_contains($sql,'<'))continue;
        // SQL fragments are not complete commands; only statements used directly by prepare/query are checked here.
        if(str_ends_with(trim($sql),'=')||str_ends_with(trim($sql),'WHERE')||str_ends_with(trim($sql),'LIMIT 1')&&str_contains($sql,'information_schema'))continue;
        try{$stmt=$conn->prepare($sql);$stmt->close();$prepared++;}catch(Throwable $e){$prepareFailures[]=basename($path).':'.$token[2];}
    }
}
check($prepared>100,'Over 100 existing static PHP SQL statements prepare against migrated schema');
check(count($prepareFailures)===0,'All extracted complete static PHP DML statements prepare successfully');
// Fragments are reported separately below, not silently asserted to be working queries.
echo 'SQL compatibility preparation: '.$prepared.' statements; '.count($prepareFailures)." candidates need fragment review.\n";
if($prepareFailures)echo 'Fragment review locations: '.implode(', ',array_unique($prepareFailures))."\n";

// Protected logical backup, checksum and real restore rehearsal on another empty database.
putenv('MYSQLDUMP_BINARY=C:/xampp/mysql/bin/mysqldump.exe');putenv('MYSQL_BINARY=C:/xampp/mysql/bin/mysql.exe');
[$code,$backupOutput]=db_command('backup-database.php');check($code===0,'Single-transaction protected backup completes');
preg_match('/Backup completed: (clevo_[A-Za-z0-9_]+\.sql);/',$backupOutput,$backupMatch);
$backup=rtrim(clevo_env('PRIVATE_STORAGE_PATH'),'/\\').'/backups/'.($backupMatch[1]??'missing-backup.sql');
$restore='clevo_restore_test_'.bin2hex(random_bytes(6));$setup->query("CREATE DATABASE `$restore` CHARACTER SET utf8mb4");putenv('DB_DATABASE='.$restore);
[$code]=db_command('restore-disposable.php',[$backup]);check($code===0,'Backup restores into guarded disposable target');$restored=clevo_connect_database();assert_snapshot($restored,db_snapshot($conn));
[$code]=db_command('restore-disposable.php',[$backup]);check($code!==0,'Restore refuses nonempty database');
putenv('DB_DATABASE='.$dbName);[$code]=db_command('restore-disposable.php',[$backup]);check($code!==0,'Restore refuses non-restore database names');
[$code,$inventory]=db_command('database-inventory.php');check($code===0&&isset(json_decode($inventory,true)['relationships']),'Full metadata inventory is available without row values');
file_put_contents(__DIR__.'/../.security-test/database-inventory.json',$inventory);
echo 'Test database server: '.$conn->server_info."; no live production database was contacted.\n";
finish_tests('Database migration/concurrency/recovery suite');
