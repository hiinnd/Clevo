<?php
declare(strict_types=1);
foreach(['ledger','provider','subscriptions','recognition','orders','refunds','reconciliation'] as $module)require_once __DIR__.'/finance/'.$module.'.php';
