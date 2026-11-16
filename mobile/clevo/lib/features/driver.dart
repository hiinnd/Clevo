import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';
import '../core/state.dart';
import '../ui/common.dart';
import 'bookings.dart';

class DriverJobsScreen extends StatelessWidget {
  const DriverJobsScreen({super.key, this.history = false});
  final bool history;
  @override
  Widget build(BuildContext context) => ClevoPage(
    title: history
        ? tr(context, 'سجل المهام', 'Job history')
        : tr(context, 'المهام المسندة', 'Assigned jobs'),
    child: ResourceList(
      path: history ? 'driver/jobs/history' : 'driver/jobs',
      field: 'tasks',
      itemBuilder: (context, row, reload) => DataCard(
        title: '#${text(row['Order_Number'] ?? row['BookingID'])}',
        subtitle:
            '${bookingState(context, row['Status'])}\n${text(row['Booking_Date'])} ${text(row['Booking_Time'])}',
        onTap: () => context.push('/driver/job/${row['BookingID']}'),
        trailing: const Icon(Icons.chevron_right),
      ),
    ),
  );
}

class DriverJobScreen extends ConsumerStatefulWidget {
  const DriverJobScreen({super.key, required this.id});
  final String id;
  @override
  ConsumerState<DriverJobScreen> createState() => _DriverJobScreenState();
}

class _DriverJobScreenState extends ConsumerState<DriverJobScreen> {
  late Future<Map<String, dynamic>> _future;
  @override
  void initState() {
    super.initState();
    reload();
  }

  void reload() {
    setState(
      () => _future = ref.read(apiProvider).request('driver/jobs/${widget.id}'),
    );
  }

  @override
  Widget build(BuildContext context) => ClevoPage(
    title: tr(context, 'تفاصيل المهمة', 'Job details'),
    actions: [IconButton(onPressed: reload, icon: const Icon(Icons.refresh))],
    child: FutureBuilder(
      future: _future,
      builder: (context, snapshot) {
        if (snapshot.hasError) return ErrorPanel(snapshot.error!, reload);
        if (!snapshot.hasData) {
          return const Center(child: CircularProgressIndicator());
        }
        final job =
            (snapshot.data!['job'] ?? snapshot.data!['booking']) as Map? ?? {};
        final stage = '${job['DeliveryStage']}';
        final next = switch (stage) {
          'accepted' => 'heading_pickup',
          'heading_pickup' => 'arrived_pickup',
          'vehicle_collected' => 'arrived_station',
          'service_completed' => 'returning_vehicle',
          _ => null,
        };
        final active = ![
          'Completed',
          'Cancelled',
          'Rejected',
          'Expired',
        ].contains('${job['Status']}');
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            DataCard(
              title: '#${text(job['Order_Number'] ?? job['BookingID'])}',
              subtitle:
                  '${bookingState(context, job['Status'])}\n${text(job['Booking_Date'])} ${text(job['Booking_Time'])}',
              children: [
                Text('${tr(context, 'المرحلة', 'Stage')}: $stage'),
                Text(text(job['Address_Details'])),
                Text(
                  '${tr(context, 'المحطة', 'Station')}: ${text(job['StationName'] ?? job['Station_Name'])}',
                ),
              ],
            ),
            if (active) ...[
              if (stage == 'assigned')
                FilledButton(
                  onPressed: () async {
                    if (await command(
                      context,
                      ref,
                      'driver/jobs/${widget.id}/accept',
                    )) {
                      reload();
                    }
                  },
                  child: Text(tr(context, 'قبول المهمة', 'Accept assignment')),
                ),
              if (next != null)
                FilledButton(
                  onPressed: () async {
                    if (await command(
                      context,
                      ref,
                      'driver/jobs/${widget.id}/delivery-stage',
                      method: 'PATCH',
                      body: {'stage': next},
                    )) {
                      reload();
                    }
                  },
                  child: Text(switch (next) {
                    'heading_pickup' => tr(
                      context,
                      'التوجه للاستلام',
                      'Head to pickup',
                    ),
                    'arrived_pickup' => tr(
                      context,
                      'وصلت إلى العميل',
                      'Arrived at pickup',
                    ),
                    'arrived_station' => tr(
                      context,
                      'وصلت إلى المحطة',
                      'Arrived at station',
                    ),
                    _ => tr(
                      context,
                      'بدء إرجاع السيارة',
                      'Start return journey',
                    ),
                  }),
                ),
              if (['arrived_pickup', 'returning_vehicle'].contains(stage))
                FilledButton(
                  onPressed: () async {
                    final result = await editRecord(
                      context,
                      ref,
                      title: tr(
                        context,
                        'تأكيد التسليم برمز العميل',
                        'Confirm handoff with customer code',
                      ),
                      path: 'driver/jobs/${widget.id}/confirm-handoff',
                      method: 'POST',
                      fixed: {
                        'kind': stage == 'arrived_pickup'
                            ? 'pickup'
                            : 'delivery',
                      },
                      fields: const [
                        FieldSpec(
                          'code',
                          'الرمز المكون من 6 أرقام',
                          '6-digit code',
                          secret: true,
                        ),
                      ],
                    );
                    if (result != null) reload();
                  },
                  child: Text(
                    tr(
                      context,
                      'تأكيد الاستلام أو الإرجاع',
                      'Confirm pickup or return',
                    ),
                  ),
                ),
              if (stage == 'arrived_station')
                Text(
                  tr(
                    context,
                    'بانتظار تأكيد المحطة لاكتمال الخدمة.',
                    'Waiting for station confirmation of service completion.',
                  ),
                ),
              OutlinedButton.icon(
                onPressed: () =>
                    context.push('/driver/job/${widget.id}/tracking'),
                icon: const Icon(Icons.my_location),
                label: Text(
                  tr(context, 'إدارة مشاركة الموقع', 'Manage location sharing'),
                ),
              ),
              if (job['Latitude'] != null && job['Longitude'] != null)
                OutlinedButton.icon(
                  onPressed: () async {
                    final uri = Uri.https('www.google.com', '/maps/dir/', {
                      'api': '1',
                      'destination': '${job['Latitude']},${job['Longitude']}',
                      'travelmode': 'driving',
                    });
                    try {
                      await launchUrl(
                        uri,
                        mode: LaunchMode.externalApplication,
                      );
                    } catch (error) {
                      if (context.mounted) showFailure(context, error);
                    }
                  },
                  icon: const Icon(Icons.navigation_outlined),
                  label: Text(
                    tr(
                      context,
                      'فتح الملاحة إلى عنوان العميل',
                      'Navigate to customer address',
                    ),
                  ),
                ),
            ],
          ],
        );
      },
    ),
  );
}
