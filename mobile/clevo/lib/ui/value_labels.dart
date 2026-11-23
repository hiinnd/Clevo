/// Display labels only; original server values remain unchanged in API requests.
const valueLabels = <String, (String, String)>{
  'pending': ('قيد الانتظار', 'Pending'),
  'prepared': ('جاهز للدفع', 'Prepared'),
  'creating': ('جارٍ إنشاء الجلسة', 'Creating'),
  'unknown': ('غير مؤكّد', 'Unconfirmed'),
  'succeeded': ('تم الدفع بعد التحقق', 'Verified payment'),
  'failed': ('فشل', 'Failed'),
  'cancelled': ('ملغي', 'Cancelled'),
  'expired': ('منتهي', 'Expired'),
  'refund_due': ('استرداد مستحق', 'Refund due'),
  'refunded': ('مسترد', 'Refunded'),
  'requested': ('تم تقديم الطلب', 'Requested'),
  'processing': ('قيد المعالجة', 'Processing'),
  'completed': ('مكتمل', 'Completed'),
  'accepted': ('مقبول', 'Accepted'),
  'rejected': ('مرفوض', 'Rejected'),
  'approved': ('معتمد', 'Approved'),
  'blocked': ('محظور', 'Blocked'),
  'active': ('نشط', 'Active'),
  'reserved': ('محجوز', 'Reserved'),
  'released': ('أُعيد الرصيد', 'Released'),
  'recorded': ('مسجّل', 'Recorded'),
  'assigned': ('تم التعيين', 'Assigned'),
  'heading_pickup': ('في الطريق للاستلام', 'Heading to pickup'),
  'arrived_pickup': ('وصل لموقع الاستلام', 'Arrived at pickup'),
  'vehicle_collected': ('تم استلام السيارة', 'Vehicle collected'),
  'arrived_station': ('وصل إلى المحطة', 'Arrived at station'),
  'service_completed': ('اكتملت الخدمة', 'Service completed'),
  'returning_vehicle': ('جارٍ إعادة السيارة', 'Returning vehicle'),
  'delivered': ('تم التسليم', 'Delivered'),
  'Pending': ('بانتظار القبول', 'Pending'),
  'Accepted': ('مقبول', 'Accepted'),
  'In Progress': ('في الطريق للاستلام', 'Heading to pickup'),
  'Arrived': ('وصل السائق', 'Driver arrived'),
  'Pickup': ('تم استلام السيارة', 'Vehicle collected'),
  'Washing': ('جارٍ تقديم الخدمة', 'Service in progress'),
  'Returning': ('جارٍ إعادة السيارة', 'Returning vehicle'),
  'Completed': ('مكتمل', 'Completed'),
  'Cancelled': ('ملغي', 'Cancelled'),
  'Rejected': ('مرفوض', 'Rejected'),
  'Cancelled_Timeout': ('انتهت المهلة', 'Expired request'),
  'Active': ('متاحة', 'Available'),
  'Busy': ('مشغولة', 'Busy'),
  'Closed': ('مغلقة', 'Closed'),
  'New': ('جديد', 'New'),
  'Replied': ('تم الرد', 'Replied'),
  'booking': ('حجز', 'Booking'),
  'subscription': ('اشتراك', 'Subscription'),
  'credit': ('إضافة', 'Credit'),
  'debit': ('خصم', 'Debit'),
  'customer': ('زبون', 'Customer'),
  'owner': ('صاحب محطة', 'Station owner'),
  'driver': ('سائق', 'Driver'),
  'admin': ('مسؤول', 'Admin'),
  'cash': ('نقدي', 'Cash'),
  'sandbox': ('بيئة اختبار', 'Sandbox'),
  'cash_attested': ('نقد مُقرّ من المحطة', 'Station-attested cash'),
  'verified_sandbox': ('دفع موثّق في الاختبار', 'Verified sandbox payment'),
  'signed_webhook': ('تأكيد مزود موقّع', 'Signed provider confirmation'),
};

String valueLabel(dynamic value, String language) {
  if (value == null || '$value'.isEmpty) return '—';
  final labels = valueLabels['$value'];
  // Unknown values remain visible for diagnosis, rather than inventing a successful state.
  return labels == null
      ? '$value'
      : language == 'ar'
      ? labels.$1
      : labels.$2;
}
