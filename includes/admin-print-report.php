<?php
if(!defined('CLEVO_PRINT_REPORT')){http_response_code(404);exit;}
if(!isset($report,$lang)||clevo_current_role()!=='admin')throw new ClevoSecurityException('forbidden',403);
$labels=$lang==='en'
    ?['Financial report','Book','Period','Recognized gross','Net recognized commission','Refunds','Recognized bookings','Date','Print / Save as PDF','Cash amounts are owner attestations. Sandbox amounts are test money. Bank settlements remain unverified.']
    :['التقرير المالي','الدفتر','الفترة','القيمة الإجمالية المثبتة','صافي العمولة المثبتة','الاسترجاعات','الحجوزات المثبتة','التاريخ','طباعة / حفظ PDF','النقد إقرار المالك والتجريبي أموال اختبار. التسويات البنكية غير موثقة.'];
?>
<!DOCTYPE html><html lang="<?= e($lang) ?>" dir="<?= $lang==='ar'?'rtl':'ltr' ?>"><head><meta charset="UTF-8"><title>CLEVO — <?= e($labels[0]) ?></title>
<style>@page{size:A4;margin:16mm}body{font:14px system-ui,sans-serif;color:#111}table{width:100%;border-collapse:collapse;margin:20px 0}th,td{border:1px solid #bbb;padding:8px;text-align:start}thead{display:table-header-group}tr{break-inside:avoid}@media print{button{display:none}}</style></head><body>
<h1>CLEVO · <?= e($labels[0]) ?></h1><p><?= e($labels[1]) ?>: <?= e($book) ?> · JOD (1 JOD = 1000 fils)</p><p><?= e($labels[2]) ?>: <?= e($from) ?> — <?= e($to) ?> · <?= e($report['timezone']) ?></p>
<p><?= e($labels[9]) ?></p><button type="button" onclick="window.print()"><?= e($labels[8]) ?></button>
<table><tbody><?php foreach(['recognized_gross_minor'=>3,'commission_minor'=>4,'refunded_minor'=>5,'recognized_bookings'=>6] as $metric=>$labelIndex): ?><tr><th><?= e($labels[$labelIndex]) ?></th><td><?= $metric==='recognized_bookings'?(int)$report['totals'][$metric]:number_format($report['totals'][$metric]/1000,3).' JOD' ?></td></tr><?php endforeach; ?></tbody></table>
<table><thead><tr><th><?= e($labels[7]) ?></th><th><?= e($labels[3]) ?> JOD</th><th><?= e($labels[4]) ?> JOD</th><th><?= e($labels[5]) ?> JOD</th></tr></thead><tbody>
<?php foreach($report['daily'] as $day): ?><tr><td><?= e($day['date']) ?></td><td><?= number_format($day['gross_minor']/1000,3) ?></td><td><?= number_format($day['commission_minor']/1000,3) ?></td><td><?= number_format($day['refund_minor']/1000,3) ?></td></tr><?php endforeach; ?>
</tbody></table></body></html>
