<?php
declare(strict_types=1);

function clevo_api_contracts():array
{
    $s=fn(int $max=255,int $min=1)=>['type'=>'string','minLength'=>$min,'maxLength'=>$max];
    $id=['type'=>'integer','minimum'=>1,'maximum'=>2147483647];$b=['type'=>'boolean'];
    $money=['type'=>['string','number'],'pattern'=>'^(0|[1-9][0-9]{0,7})(\.[0-9]{1,2})?$','minimum'=>0,'maximum'=>99999999.99,'multipleOf'=>0.01];
    $lat=['type'=>'number','minimum'=>-90,'maximum'=>90];$lon=['type'=>'number','minimum'=>-180,'maximum'=>180];
    $email=$s(100)+['format'=>'email'];$phone=$s(10)+['pattern'=>'^07[0-9]{8}$'];
    $profile=['full_name'=>$s(100),'phone'=>$phone,'car_name'=>$s(100,0),'email'=>$email];
    $service=['name_ar'=>$s(100),'name_en'=>$s(100),'description_ar'=>$s(5000,0),'description_en'=>$s(5000,0),'price'=>$money,'duration'=>['type'=>'integer','minimum'=>1,'maximum'=>1440],'is_station'=>$b,'is_home'=>$b,'is_pickup'=>$b];
    $plan=['name_ar'=>$s(255),'name_en'=>$s(255),'price'=>$money,'duration'=>['type'=>'integer','minimum'=>1,'maximum'=>120],'duration_type'=>['type'=>'string','enum'=>['day','month','year']],'wash_count'=>['type'=>'integer','minimum'=>1,'maximum'=>10000],'description_ar'=>$s(5000,0),'description_en'=>$s(5000,0),'included_services_ar'=>$s(5000,0),'included_services_en'=>$s(5000,0),'is_active'=>$b];
    $empty=clevo_api_schema([]);
    return [
        'Empty'=>$empty,
        'Avatar'=>clevo_api_schema(['avatar'=>['type'=>'string','format'=>'binary']],['avatar']),
        'StationImage'=>clevo_api_schema(['image'=>['type'=>'string','format'=>'binary']],['image']),
        'StationPatch'=>clevo_api_schema(['name'=>$s(100),'location'=>$s(255),'location_url'=>$s(255),'phone'=>$s(20)+['pattern'=>'^\+?[0-9]{7,20}$'],'latitude'=>$lat,'longitude'=>$lon])+['minProperties'=>1],
        'Bank'=>clevo_api_schema(['account_holder_name'=>$s(150),'bank_name'=>$s(150),'iban'=>$s(34)+['minLength'=>15,'pattern'=>'^[A-Z]{2}[0-9]{2}[A-Z0-9]+$'],'account_number'=>$s(50,0),'swift_code'=>$s(20,0),'notes'=>$s(255,0)],['account_holder_name','bank_name','iban']),
        'Booking'=>array_replace(clevo_api_schema(['station_id'=>$id,'service_ids'=>['type'=>'array','items'=>$id,'minItems'=>1,'maxItems'=>30,'uniqueItems'=>true],'service_location'=>['type'=>'string','enum'=>['station','home','delivery']],'booking_date'=>$s(10)+['pattern'=>'^[0-9]{4}-[0-9]{2}-[0-9]{2}$'],'booking_time'=>$s(8)+['pattern'=>'^[0-9]{2}:[0-9]{2}(:[0-9]{2})?$'],'latitude'=>$lat,'longitude'=>$lon,'address_details'=>$s(2000,0),'use_plan'=>$b],['station_id','service_ids','service_location','booking_date','booking_time']),['additionalProperties'=>true]),
        'Payment'=>clevo_api_schema(['method'=>['type'=>'string','enum'=>['wallet','cash','plan','visa']],'use_points'=>$b],['method']),
        'PaymentSession'=>clevo_api_schema(['booking_id'=>$id,'method'=>['type'=>'string','enum'=>['wallet','cash','plan','visa']],'use_points'=>$b],['booking_id','method']),
        'Profile'=>clevo_api_schema($profile)+['minProperties'=>1],
        'Address'=>clevo_api_schema(['label'=>$s(80),'address'=>$s(2000),'latitude'=>$lat,'longitude'=>$lon,'is_default'=>$b],['label','address','latitude','longitude']),
        'ServiceCreate'=>clevo_api_schema($service,['name_ar','name_en','price']),
        'ServicePatch'=>clevo_api_schema($service)+['minProperties'=>1],
        'Availability'=>clevo_api_schema(['status'=>['type'=>'string','enum'=>['Active','Busy','Closed']],'opening_time'=>$s(8)+['pattern'=>'^[0-9]{2}:[0-9]{2}(:[0-9]{2})?$'],'closing_time'=>$s(8)+['pattern'=>'^[0-9]{2}:[0-9]{2}(:[0-9]{2})?$'],'has_at_station'=>$b,'has_home_service'=>$b,'has_delivery'=>$b])+['minProperties'=>1],
        'DriverCreate'=>clevo_api_schema(['station_id'=>$id,'name'=>$s(100),'phone'=>$phone,'email'=>$email],['station_id','name','phone']),
        'DriverInvitation'=>clevo_api_schema(['email'=>$email],['email']),
        'DriverPatch'=>clevo_api_schema(['name'=>$s(100),'phone'=>$phone,'active'=>$b])+['minProperties'=>1],
        'Assignment'=>clevo_api_schema(['driver_id'=>$id],['driver_id']),
        'DriverStatus'=>clevo_api_schema(['status'=>['type'=>'string','enum'=>['In Progress','Arrived','Pickup','Washing','Returning','Completed']]],['status']),
        'Location'=>clevo_api_schema(['latitude'=>$lat,'longitude'=>$lon],['latitude','longitude']),
        'Refund'=>clevo_api_schema(['booking_id'=>$id,'reason'=>$s(2000)],['booking_id','reason']),
        'Decision'=>clevo_api_schema(['status'=>['type'=>'string','enum'=>['approved','rejected']],'note'=>$s(2000)],['status','note']),
        'Purchase'=>clevo_api_schema(['plan_id'=>$id,'method'=>['type'=>'string','enum'=>['wallet','visa']]],['plan_id','method']),
        'Review'=>clevo_api_schema(['rating'=>['type'=>'integer','minimum'=>1,'maximum'=>5],'comment'=>$s(2000,0)],['rating']),
        'Reply'=>clevo_api_schema(['message'=>$s(4000)],['message']),
        'Support'=>clevo_api_schema(['subject'=>$s(255),'message'=>$s(4000),'booking_id'=>$id,'category'=>['type'=>'string','enum'=>['support','dispute']]],['subject','message']),
        'TicketStatus'=>clevo_api_schema(['status'=>['type'=>'string','enum'=>['New','Replied','Closed']]],['status']),
        'AccountStatus'=>clevo_api_schema(['status'=>['type'=>'string','enum'=>['active','pending','rejected']]],['status']),
        'StationStatus'=>clevo_api_schema(['admin_status'=>['type'=>'string','enum'=>['approved','pending','blocked']]],['admin_status']),
        'PlanCreate'=>clevo_api_schema($plan,['name_ar','name_en','price','duration','duration_type','wash_count']),
        'PlanPatch'=>clevo_api_schema($plan)+['minProperties'=>1],
        'SubscriptionStatus'=>clevo_api_schema(['status'=>['type'=>'string','enum'=>['cancelled']]],['status']),
        'Reschedule'=>clevo_api_schema(['booking_date'=>$s(10)+['pattern'=>'^[0-9]{4}-[0-9]{2}-[0-9]{2}$'],'booking_time'=>$s(8)+['pattern'=>'^[0-9]{2}:[0-9]{2}(:[0-9]{2})?$']],['booking_date','booking_time']),
        'WithdrawalStatus'=>clevo_api_schema(['status'=>['type'=>'string','enum'=>['paid','rejected']]],['status']),
    ];
}
