import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../core/state.dart';
import '../ui/common.dart';

String bookingState(BuildContext context, dynamic status) =>
    Localizations.localeOf(context).languageCode == 'en'
    ? text(status)
    : switch ('$status') {
        'Pending' => 'بانتظار القبول',
        'Accepted' => 'مقبول',
        'In Progress' => 'في الطريق للاستلام',
        'Arrived' => 'وصل السائق',
        'Pickup' => 'تم استلام السيارة',
        'Washing' => 'جارٍ تقديم الخدمة',
        'Returning' => 'جارٍ إرجاع السيارة',
        'Completed' => 'مكتمل',
        'Cancelled' => 'ملغي',
        'Rejected' => 'مرفوض',
        'Cancelled_Timeout' => 'انتهت مهلة القبول',
        _ => text(status),
      };

class BookingsScreen extends ConsumerWidget {
  const BookingsScreen({super.key, this.history = false});
  final bool history;
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final owner = ref.watch(sessionProvider).asData?.value?.role == 'owner';
    return ClevoPage(
      title: tr(context, 'الحجوزات', 'Bookings'),
      actions: [
        if (!owner)
          IconButton(
            onPressed: () => context.push('/bookings/history'),
            icon: const Icon(Icons.history),
          ),
      ],
      child: ResourceList(
        path: owner
            ? 'owner/bookings'
            : history
            ? 'bookings/history'
            : 'bookings',
        field: 'bookings',
        itemBuilder: (context, row, reload) => DataCard(
          title: '#${text(row['Order_Number'] ?? row['BookingID'])}',
          subtitle:
              '${text(row['Booking_Date'])} ${text(row['Booking_Time'])}\n${bookingState(context, row['Status'])} · ${money(row['Payable_Amount'] ?? row['Total_Price'])}',
          trailing: const Icon(Icons.chevron_right),
          onTap: () => context.push('/booking/${row['BookingID']}'),
        ),
      ),
    );
  }
}

class BookingScreen extends ConsumerStatefulWidget {
  const BookingScreen({super.key, required this.id});
  final String id;
  @override
  ConsumerState<BookingScreen> createState() => _BookingScreenState();
}

class _BookingScreenState extends ConsumerState<BookingScreen> {
  late Future<Map<String, dynamic>> _future;
  @override
  void initState() {
    super.initState();
    reload();
  }

  void reload() {
    setState(
      () => _future =
          Future.wait([
            ref.read(apiProvider).request('bookings/${widget.id}'),
            ref
                .read(apiProvider)
                .request('bookings/${widget.id}/price-breakdown'),
          ]).then(
            (data) => {
              ...data[0],
              'price_breakdown': data[1]['price_breakdown'],
            },
          ),
    );
  }

  Future<void> action(
    String suffix, {
    Map<String, dynamic> body = const {},
  }) async {
    final owner = ref.read(sessionProvider).asData?.value?.role == 'owner';
    if (await command(
      context,
      ref,
      '${owner ? 'owner/' : ''}bookings/${widget.id}/$suffix',
      body: body,
    )) {
      reload();
    }
  }

  @override
  Widget build(BuildContext context) => ClevoPage(
    title: tr(context, 'تفاصيل الحجز', 'Booking details'),
    actions: [IconButton(onPressed: reload, icon: const Icon(Icons.refresh))],
    child: FutureBuilder(
      future: _future,
      builder: (context, snapshot) {
        if (snapshot.hasError) return ErrorPanel(snapshot.error!, reload);
        if (!snapshot.hasData) {
          return const Center(child: CircularProgressIndicator());
        }
        final booking = Map<String, dynamic>.from(
          snapshot.data!['booking'] as Map,
        );
        final services =
            (booking['services'] ?? snapshot.data!['services']) as List? ?? [];
        final price = snapshot.data!['price_breakdown'] as Map? ?? {};
        final status = '${booking['Status']}',
            mode = '${booking['Service_Location']}';
        final owner = ref.watch(sessionProvider).asData?.value?.role == 'owner';
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            DataCard(
              title:
                  '#${text(booking['Order_Number'] ?? booking['BookingID'])}',
              subtitle:
                  '${bookingState(context, status)}\n${text(booking['Booking_Date'])} ${text(booking['Booking_Time'])}\n${text(booking['StationName'] ?? booking['Station_Name'])}',
              children: [
                const SizedBox(height: 12),
                Text(
                  '${tr(context, 'المبلغ المستحق', 'Payable')}: ${money(price['payable'])}',
                  style: Theme.of(context).textTheme.titleLarge,
                ),
                Text(
                  '${tr(context, 'الخدمات', 'Services')}: ${money(booking['ServicesSubtotal'])}',
                ),
                Text(
                  '${tr(context, 'رسوم إضافية', 'Additional fees')}: ${money(booking['AdditionalFee'])}',
                ),
                Text(
                  '${tr(context, 'الخصم', 'Discount')}: ${money(price['discount'])}',
                ),
                Text(
                  '${tr(context, 'المتبقي', 'Outstanding')}: ${money(price['outstanding'])}',
                ),
                Text(
                  '${tr(context, 'حالة التسوية', 'Settlement state')}: ${text(price['settlement_state'])}',
                ),
                Text('${tr(context, 'طريقة الخدمة', 'Service mode')}: $mode'),
                Text(
                  '${tr(context, 'الدفع', 'Payment')}: ${text(booking['Payment_Method'])}',
                ),
                if (booking['DeliveryStage'] != null)
                  Text(
                    '${tr(context, 'مرحلة التوصيل', 'Delivery stage')}: ${text(booking['DeliveryStage'])}',
                  ),
              ],
            ),
            for (final service in services.whereType<Map>())
              DataCard(
                title: localizedName(
                  context,
                  Map<String, dynamic>.from(service),
                ),
                subtitle: money(service['Price'] ?? service['price']),
              ),
            if (owner && status == 'Pending')
              Wrap(
                spacing: 12,
                children: [
                  FilledButton(
                    onPressed: () => action('accept'),
                    child: Text(tr(context, 'قبول', 'Accept')),
                  ),
                  OutlinedButton(
                    onPressed: () => action('reject'),
                    child: Text(tr(context, 'رفض', 'Reject')),
                  ),
                ],
              ),
            if (owner &&
                mode == 'delivery' &&
                ![
                  'Completed',
                  'Cancelled',
                  'Rejected',
                  'Expired',
                ].contains(status)) ...[
              OutlinedButton(
                onPressed: () async {
                  final result = await editRecord(
                    context,
                    ref,
                    title: tr(context, 'تعيين سائق', 'Assign driver'),
                    path: 'owner/bookings/${widget.id}/driver',
                    method: 'POST',
                    fields: const [
                      FieldSpec(
                        'driver_id',
                        'رقم السائق من قائمة فريقي',
                        'Driver ID from my team',
                        integer: true,
                      ),
                    ],
                  );
                  if (result != null) reload();
                },
                child: Text(tr(context, 'تعيين سائق', 'Assign driver')),
              ),
              if (booking['DeliveryStage'] == 'arrived_station')
                FilledButton(
                  onPressed: () => action('service-completed'),
                  child: Text(
                    tr(context, 'اكتملت الخدمة', 'Service completed'),
                  ),
                ),
            ],
            if (owner && mode != 'delivery' && status == 'Accepted')
              FilledButton(
                onPressed: () => action('complete'),
                child: Text(tr(context, 'إكمال الخدمة', 'Complete service')),
              ),
            if (!owner) ...[
              if (status == 'Pending')
                OutlinedButton(
                  onPressed: () => action('cancel'),
                  child: Text(tr(context, 'إلغاء الحجز', 'Cancel booking')),
                ),
              if (status == 'Pending')
                OutlinedButton(
                  onPressed: () async {
                    final result = await editRecord(
                      context,
                      ref,
                      title: tr(context, 'تعديل الموعد', 'Reschedule'),
                      path: 'bookings/${widget.id}/reschedule',
                      method: 'POST',
                      fields: const [
                        FieldSpec(
                          'booking_date',
                          'التاريخ YYYY-MM-DD',
                          'Date YYYY-MM-DD',
                        ),
                        FieldSpec('booking_time', 'الوقت HH:mm', 'Time HH:mm'),
                      ],
                    );
                    if (result != null) reload();
                  },
                  child: Text(tr(context, 'تعديل الموعد', 'Reschedule')),
                ),
              OutlinedButton(
                onPressed: () => context.push('/booking/${widget.id}/payment'),
                child: Text(tr(context, 'خيارات الدفع', 'Payment options')),
              ),
              if (mode == 'delivery') ...[
                FilledButton.icon(
                  onPressed: () =>
                      context.push('/booking/${widget.id}/tracking'),
                  icon: const Icon(Icons.map_outlined),
                  label: Text(tr(context, 'تتبع السائق', 'Track driver')),
                ),
                if ([
                  'arrived_pickup',
                  'returning_vehicle',
                ].contains('${booking['DeliveryStage']}'))
                  OutlinedButton(
                    onPressed: () async {
                      final kind = booking['DeliveryStage'] == 'arrived_pickup'
                          ? 'pickup'
                          : 'delivery';
                      final result = await editRecord(
                        context,
                        ref,
                        title: tr(
                          context,
                          'إصدار رمز التسليم للسائق',
                          'Issue handoff code',
                        ),
                        path: 'bookings/${widget.id}/confirmation-code',
                        method: 'POST',
                        fields: const [],
                        fixed: {'kind': kind},
                      );
                      if (result != null && context.mounted) {
                        await showDialog<void>(
                          context: context,
                          builder: (context) => AlertDialog(
                            title: Text(
                              tr(context, 'الرمز الخاص', 'Private code'),
                            ),
                            content: Text(
                              '${result['confirmation_code']}\n${tr(context, 'شاركه مع السائق فقط بعد التحقق من التسليم. لا تحفظه أو تنشره.', 'Share only with your assigned driver after verifying the handoff. Do not save or publish it.')}',
                            ),
                            actions: [
                              TextButton(
                                onPressed: () => Navigator.pop(context),
                                child: Text(tr(context, 'إغلاق', 'Close')),
                              ),
                            ],
                          ),
                        );
                      }
                    },
                    child: Text(
                      tr(
                        context,
                        'رمز تأكيد التسليم',
                        'Handoff confirmation code',
                      ),
                    ),
                  ),
              ],
              if (status == 'Completed')
                OutlinedButton(
                  onPressed: () => editRecord(
                    context,
                    ref,
                    title: tr(context, 'تقييم الخدمة', 'Review service'),
                    path: 'bookings/${widget.id}/reviews',
                    method: 'POST',
                    fields: const [
                      FieldSpec(
                        'rating',
                        'التقييم من 1 إلى 5',
                        'Rating 1–5',
                        integer: true,
                      ),
                      FieldSpec(
                        'comment',
                        'تعليق',
                        'Comment',
                        required: false,
                        lines: 3,
                      ),
                    ],
                  ),
                  child: Text(tr(context, 'إضافة تقييم', 'Write review')),
                ),
            ],
            TextButton(
              onPressed: () => context.push('/booking/${widget.id}/events'),
              child: Text(tr(context, 'سجل الحجز', 'Booking timeline')),
            ),
          ],
        );
      },
    ),
  );
}
