<?php
declare(strict_types=1);

function clevo_api_public_station(mysqli $db,int $id):array
{
    return clevo_api_require_row(clevo_stmt($db,"SELECT StationID,Name,Location,Lat,Lon,Location_URL,PhoneNumber,COALESCE((SELECT AVG(r.Rating) FROM reviews r JOIN booking b ON b.BookingID=r.BookingID WHERE b.StationID=stations.StationID),Rating,0) Rating,Status,Image,OpeningTime,ClosingTime,Has_At_Station,Has_Home_Service,Has_Delivery,BookingCapacity,HomeCapacity,DefaultServiceMinutes,DeliveryBufferMinutes,StationFee,HomeFee,DeliveryFee FROM stations WHERE StationID=? AND admin_status='approved' AND ArchivedAt IS NULL",'i',[$id])->get_result()->fetch_assoc());
}
function clevo_api_catalog(mysqli $db,string $action,array $args):never
{
    $id=(int)($args['id']??0);
    if($action==='stations'){
        $where=["s.admin_status='approved'","s.ArchivedAt IS NULL"];$params=[];$types='';
        foreach(['query'=>'s.Name','location'=>'s.Location'] as $key=>$column){$q=clevo_api_query($key);if($q!==null&&$q!==''){$where[]="$column LIKE ?";$params[]='%'.addcslashes($q,'\\%_').'%';$types.='s';}}
        $mode=clevo_api_enum('mode',['station','home','delivery']);if($mode)$where[]=['station'=>'s.Has_At_Station=1','home'=>'s.Has_Home_Service=1','delivery'=>'s.Has_Delivery=1'][$mode];
        $serviceFilter=$mode?' AND '.['station'=>'is_station','home'=>'is_home','delivery'=>'is_pickup'][$mode].'=1':'';
        $status=clevo_api_enum('status',['Active','Busy','Closed']);if($status){$where[]='s.Status=?';$types.='s';$params[]=$status;}
        $lat=clevo_api_filter_number('lat',-90,90);$lon=clevo_api_filter_number('lng',-180,180);$radius=clevo_api_filter_number('radius_km',0.1,250);
        if(($lat===null)!==($lon===null)||($radius!==null&&$lat===null))clevo_api_invalid('query.location');
        $distance='NULL';$distanceParams=[];$distanceTypes='';
        if($lat!==null){$distance='6371*2*ASIN(SQRT(LEAST(1,POWER(SIN(RADIANS(s.Lat-?)/2),2)+COS(RADIANS(?))*COS(RADIANS(s.Lat))*POWER(SIN(RADIANS(s.Lon-?)/2),2))))';$distanceParams=[$lat,$lat,$lon];$distanceTypes='ddd';if($radius!==null){$where[]='s.Lat BETWEEN ? AND ?';array_push($params,max(-90,$lat-$radius/111),min(90,$lat+$radius/111));$types.='dd';}}
        $base="SELECT s.StationID,s.Name,s.Location,s.Lat,s.Lon,s.Status,s.Image,s.OpeningTime,s.ClosingTime,COALESCE(r.Rating,s.Rating,0) Rating,v.PriceMin,v.PriceMax,$distance DistanceKm FROM stations s LEFT JOIN (SELECT StationID,MIN(Price) PriceMin,MAX(Price) PriceMax FROM services WHERE ArchivedAt IS NULL $serviceFilter GROUP BY StationID) v ON v.StationID=s.StationID LEFT JOIN (SELECT b.StationID,AVG(r.Rating) Rating FROM reviews r JOIN booking b ON b.BookingID=r.BookingID GROUP BY b.StationID) r ON r.StationID=s.StationID WHERE ".implode(' AND ',$where);
        $outer=['1=1'];$extra=[];$extraTypes='';
        if($mode)$outer[]='x.PriceMin IS NOT NULL';
        $min=clevo_api_filter_number('min_price',0,99999999.99);$max=clevo_api_filter_number('max_price',0,99999999.99);if($min!==null&&$max!==null&&$min>$max)clevo_api_invalid('query.price');
        foreach([['x.PriceMin>=?',$min],['x.PriceMin<=?',$max],['x.Rating>=?',clevo_api_filter_number('min_rating',0,5)],['x.DistanceKm<=?',$radius]] as [$condition,$value])if($value!==null){$outer[]=$condition;$extra[]=$value;$extraTypes.='d';}
        $sorts=['id'=>'x.StationID','price'=>'x.PriceMin','rating'=>'x.Rating','name'=>'x.Name'];if($lat!==null)$sorts['distance']='x.DistanceKm';
        $rows=clevo_api_list($db,'x.*',"FROM ($base) x",implode(' AND ',$outer),$distanceTypes.$types.$extraTypes,array_merge($distanceParams,$params,$extra),$sorts,'id','x.StationID');
        foreach($rows as &$row)$row['Image']=clevo_api_image($row['Image'],'',true);unset($row);
        clevo_api_result(['stations'=>$rows]);
    }
    if($action==='plans')clevo_api_result(['plans'=>clevo_api_list($db,'id,name_ar,name_en,price,duration,duration_type,wash_count,description_ar,description_en,included_services_ar,included_services_en','FROM plans','is_active=1','',[],['id'=>'id','price'=>'price'],'id','id')]);
    $station=clevo_api_public_station($db,$id);$station['Image']=clevo_api_image($station['Image'],'',true);
    if($action==='station')clevo_api_result(['station'=>$station]);
    if($action==='services')clevo_api_result(['services'=>clevo_api_list($db,'v.ServiceID,v.Name,v.Name_ar,v.Name_en,v.Description_ar,v.Description_en,v.Price,CASE WHEN v.Duration>0 THEN v.Duration ELSE s.DefaultServiceMinutes END Duration,v.is_station,v.is_home,v.is_pickup','FROM services v JOIN stations s ON s.StationID=v.StationID','v.StationID=? AND v.ArchivedAt IS NULL','i',[$id],['id'=>'v.ServiceID','price'=>'v.Price','name'=>'v.Name'],'id','v.ServiceID')]);
    if($action==='reviews')clevo_api_result(['reviews'=>clevo_api_list($db,'r.ReviewID,r.Rating,r.Comment,r.Owner_Reply','FROM reviews r JOIN booking b ON b.BookingID=r.BookingID','b.StationID=?','i',[$id],['id'=>'r.ReviewID','rating'=>'r.Rating'],'id','r.ReviewID')]);
    if($action==='availability'){
        $zone=new DateTimeZone(clevo_env('BOOKING_TIMEZONE','Asia/Amman'));$now=new DateTimeImmutable('now',$zone);$date=clevo_api_query('date',$now->format('Y-m-d'));
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$zone);if(!$parsed||$parsed->format('Y-m-d')!==$date)clevo_api_invalid('query.date');
        $open=$station['OpeningTime'];$close=$station['ClosingTime'];$clock=$now->format('H:i:s');
        $within=$open&&$close?($open<$close?($clock>=$open&&$clock<$close):($open===$close||$clock>=$open||$clock<$close)):true;
        $appointment=null;
        if(clevo_api_query('time')!==null || clevo_api_query('service_ids')!==null){
            $time=clevo_api_query('time');$raw=clevo_api_query('service_ids','');
            if($time===null||!preg_match('/^[1-9][0-9]*(,[1-9][0-9]*){0,29}$/D',$raw))clevo_api_invalid('query.service_ids');
            $ids=array_values(array_unique(array_map('intval',explode(',',$raw))));foreach($ids as $serviceID)if($serviceID>2147483647)clevo_api_invalid('query.service_ids');
            $appointment=clevo_booking_availability($db,$id,['date'=>$date,'time'=>$time,'mode'=>clevo_api_enum('mode',['station','home','delivery'],'station'),'service_ids'=>$ids]);
        }
        clevo_api_result(['availability'=>['station_id'=>$id,'date'=>$date,'timezone'=>$zone->getName(),'status'=>$station['Status'],'opening_time'=>$open,'closing_time'=>$close,'open_now'=>$station['Status']==='Active'&&$within,'accepting_requests'=>$station['Status']==='Active'&&((int)$station['BookingCapacity']>0||(int)$station['HomeCapacity']>0),'capacity_enforced'=>true,'booking_policy'=>clevo_booking_policy($station),'appointment'=>$appointment]]);
    }
    throw new LogicException('Unimplemented catalog route.');
}
