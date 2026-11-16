<?php
require_once __DIR__.'/../includes/config.php';
$user=clevo_current_user_id();if(!$user)throw new ClevoSecurityException('unauthorized',401);
$id=(int)($_GET['id']??0);$tracking=clevo_delivery_tracking($conn,$user,$id,(int)($_SESSION['driver_booking_id']??0));
$row=clevo_stmt($conn,'SELECT b.Latitude,b.Longitude,b.Status,b.Service_Location,s.Name,s.Location,s.Lat,s.Lon FROM booking b JOIN stations s ON s.StationID=b.StationID WHERE b.BookingID=?','i',[$id])->get_result()->fetch_assoc();
$visible=clevo_current_role()!=='driver'||in_array($row['Status'],clevo_booking_active_states(),true);
$location=$tracking['location'];
clevo_json_response('success',['lat'=>$location['latitude']??null,'lng'=>$location['longitude']??null,'driver'=>$location?['lat'=>$location['latitude'],'lng'=>$location['longitude']]:null,'tracking'=>$tracking,'customer'=>['lat'=>$visible&&$row['Latitude']!==null?(float)$row['Latitude']:null,'lng'=>$visible&&$row['Longitude']!==null?(float)$row['Longitude']:null],'station'=>['name'=>$row['Name'],'location'=>$row['Location'],'lat'=>$row['Lat']!==null?(float)$row['Lat']:null,'lng'=>$row['Lon']!==null?(float)$row['Lon']:null],'serviceLocation'=>$row['Service_Location'],'orderStatus'=>$row['Status'],'hasArrived'=>in_array($tracking['delivery_stage'],['arrived_pickup','vehicle_collected','arrived_station','service_completed','returning_vehicle','delivered'],true)],'ok');
