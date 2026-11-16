<?php
if(!isset($task,$deliveryTracking)){http_response_code(404);return;}
$stage=$task['DeliveryStage']??null;$stages=clevo_delivery_stages();$position=array_search($stage,$stages,true);$next=$position===false?null:($stages[$position+1]??null);
$ar=['assigned'=>'تم الإسناد','accepted'=>'قبول المهمة','heading_pickup'=>'التوجه للاستلام','arrived_pickup'=>'الوصول للاستلام','vehicle_collected'=>'تأكيد استلام المركبة','arrived_station'=>'الوصول للمحطة','service_completed'=>'اكتمل الغسيل لدى المحطة','returning_vehicle'=>'بدء إرجاع المركبة','delivered'=>'تأكيد التسليم'];
$en=['assigned'=>'Assigned','accepted'=>'Accept job','heading_pickup'=>'Heading to pickup','arrived_pickup'=>'Arrived at pickup','vehicle_collected'=>'Confirm vehicle collection','arrived_station'=>'Arrived at station','service_completed'=>'Service completed by station','returning_vehicle'=>'Returning vehicle','delivered'=>'Confirm delivery'];
?>
<div class="mb-3">
    <p data-delivery-ar="<?= e($ar[$stage]??'يلزم اعتماد المهمة من المالك') ?>" data-delivery-en="<?= e($en[$stage]??'Owner adoption required') ?>"><?= e($ar[$stage]??'يلزم اعتماد المهمة من المالك') ?></p>
    <?php if($next&&$next!=='service_completed'): ?>
    <button type="button" class="btn btn-primary w-100 rounded-pill py-2" data-delivery-next="<?= e($next) ?>" data-delivery-ar="<?= e($ar[$next]) ?>" data-delivery-en="<?= e($en[$next]) ?>"><?= e($ar[$next]) ?></button>
    <?php elseif($next==='service_completed'): ?><p data-delivery-ar="بانتظار تأكيد المحطة لإكمال الخدمة" data-delivery-en="Waiting for station service confirmation">بانتظار تأكيد المحطة لإكمال الخدمة</p><?php endif; ?>
    <small role="status" id="deliveryFeedback"></small>
</div>
<script>window.clevoDelivery=<?= clevo_js(['csrf'=>generate_csrf_token(),'assignment_id'=>$deliveryTracking['assignment_id'],'last_sequence'=>$deliveryTracking['last_sequence'],'sharing_allowed'=>$deliveryTracking['sharing_allowed'],'delivery_stage'=>$stage]) ?>;</script>
