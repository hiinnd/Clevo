import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../core/state.dart';
import '../ui/common.dart';

class OwnerStationsScreen extends ConsumerWidget {
  const OwnerStationsScreen({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) => ClevoPage(
    title: tr(context, 'محطاتي', 'My stations'),
    child: ResourceList(
      path: 'owner/stations',
      field: 'stations',
      header: Padding(
        padding: const EdgeInsets.all(12),
        child: Text(
          tr(
            context,
            'إضافة محطة جديدة متاحة حالياً عبر حساب المالك على الويب.',
            'New station onboarding is currently available through the owner web account.',
          ),
        ),
      ),
      itemBuilder: (context, row, reload) => DataCard(
        title: text(row['Name']),
        subtitle: '${text(row['Location'])} · ${text(row['Status'])}',
        children: [
          Wrap(
            spacing: 8,
            children: [
              TextButton(
                onPressed: () =>
                    context.push('/owner/station/${row['StationID']}/services'),
                child: Text(
                  tr(context, 'الخدمات والأسعار', 'Services & prices'),
                ),
              ),
              TextButton(
                onPressed: () async {
                  final result = await editRecord(
                    context,
                    ref,
                    title: tr(context, 'بيانات المحطة', 'Station details'),
                    path: 'owner/stations/${row['StationID']}',
                    method: 'PATCH',
                    fields: [
                      FieldSpec(
                        'name',
                        'الاسم',
                        'Name',
                        initial: text(row['Name'], ''),
                      ),
                      FieldSpec(
                        'location',
                        'المنطقة',
                        'Area',
                        initial: text(row['Location'], ''),
                      ),
                      FieldSpec(
                        'phone',
                        'الهاتف',
                        'Phone',
                        initial: text(row['PhoneNumber'], ''),
                        required: false,
                      ),
                      FieldSpec(
                        'latitude',
                        'خط العرض',
                        'Latitude',
                        initial: text(row['Lat'], ''),
                        required: false,
                        number: true,
                      ),
                      FieldSpec(
                        'longitude',
                        'خط الطول',
                        'Longitude',
                        initial: text(row['Lon'], ''),
                        required: false,
                        number: true,
                      ),
                    ],
                  );
                  if (result != null) reload();
                },
                child: Text(tr(context, 'تعديل', 'Edit')),
              ),
              TextButton(
                onPressed: () => context.push(
                  '/owner/station/${row['StationID']}/availability',
                ),
                child: Text(
                  tr(context, 'السعة وأوقات العمل', 'Capacity & working hours'),
                ),
              ),
            ],
          ),
        ],
      ),
    ),
  );
}

class OwnerServicesScreen extends ConsumerWidget {
  const OwnerServicesScreen({super.key, required this.id});
  final String id;
  Future<void> edit(
    BuildContext context,
    WidgetRef ref, [
    Map<String, dynamic>? row,
  ]) async {
    final result = await editRecord(
      context,
      ref,
      title: tr(context, 'الخدمة', 'Service'),
      path: row == null
          ? 'owner/stations/$id/services'
          : 'owner/services/${row['ServiceID']}',
      method: row == null ? 'POST' : 'PATCH',
      fields: [
        FieldSpec(
          'name_ar',
          'الاسم بالعربية',
          'Arabic name',
          initial: text(row?['Name_ar'], ''),
        ),
        FieldSpec(
          'name_en',
          'الاسم بالإنجليزية',
          'English name',
          initial: text(row?['Name_en'], ''),
        ),
        FieldSpec(
          'price',
          'السعر بالدينار',
          'Price JOD',
          initial: text(row?['Price'], ''),
        ),
        FieldSpec(
          'duration',
          'المدة بالدقائق',
          'Duration in minutes',
          integer: true,
          initial: text(row?['Duration'], ''),
        ),
        FieldSpec(
          'description_ar',
          'الوصف بالعربية',
          'Arabic description',
          required: false,
          lines: 3,
          initial: text(row?['Description_ar'], ''),
        ),
        FieldSpec(
          'description_en',
          'الوصف بالإنجليزية',
          'English description',
          required: false,
          lines: 3,
          initial: text(row?['Description_en'], ''),
        ),
      ],
      fixed: row == null
          ? {'is_station': true, 'is_home': false, 'is_pickup': false}
          : {},
    );
    if (result != null && context.mounted) {
      context.pushReplacement('/owner/station/$id/services');
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) => ClevoPage(
    title: tr(context, 'إدارة الخدمات', 'Manage services'),
    actions: [
      IconButton(
        onPressed: () => edit(context, ref),
        icon: const Icon(Icons.add),
      ),
    ],
    child: ResourceList(
      path: 'owner/stations/$id/services',
      field: 'services',
      itemBuilder: (context, row, reload) => DataCard(
        title: localizedName(context, row),
        subtitle:
            '${money(row['Price'])} · ${text(row['Duration'])} ${tr(context, 'دقيقة', 'minutes')}',
        children: [
          Wrap(
            spacing: 8,
            children: [
              TextButton(
                onPressed: () => edit(context, ref, row),
                child: Text(tr(context, 'تعديل', 'Edit')),
              ),
              TextButton(
                onPressed: () async {
                  if (await command(
                    context,
                    ref,
                    'owner/services/${row['ServiceID']}',
                    method: 'DELETE',
                  )) {
                    reload();
                  }
                },
                child: Text(tr(context, 'إيقاف الخدمة', 'Deactivate')),
              ),
            ],
          ),
          for (final mode in [
            ('is_station', 'في المحطة', 'At station'),
            ('is_home', 'منزلية', 'Home service'),
            ('is_pickup', 'استلام وإرجاع', 'Pickup & return'),
          ])
            SwitchListTile(
              title: Text(tr(context, mode.$2, mode.$3)),
              value: '${row[mode.$1]}' == '1' || row[mode.$1] == true,
              onChanged: (value) async {
                if (await command(
                  context,
                  ref,
                  'owner/services/${row['ServiceID']}',
                  method: 'PATCH',
                  body: {mode.$1: value},
                )) {
                  reload();
                }
              },
            ),
        ],
      ),
    ),
  );
}

class AvailabilityScreen extends ConsumerStatefulWidget {
  const AvailabilityScreen({super.key, required this.id});
  final String id;
  @override
  ConsumerState<AvailabilityScreen> createState() => _AvailabilityScreenState();
}

class _AvailabilityScreenState extends ConsumerState<AvailabilityScreen> {
  late Future<Map<String, dynamic>> _future;
  @override
  void initState() {
    super.initState();
    reload();
  }

  void reload() {
    setState(
      () => _future = ref.read(apiProvider).request('stations/${widget.id}'),
    );
  }

  @override
  Widget build(BuildContext context) => ClevoPage(
    title: tr(context, 'إعداد التوفر', 'Availability settings'),
    child: FutureBuilder(
      future: _future,
      builder: (context, snapshot) {
        if (snapshot.hasError) return ErrorPanel(snapshot.error!, reload);
        if (!snapshot.hasData) {
          return const Center(child: CircularProgressIndicator());
        }
        final station = snapshot.data!['station'] as Map;
        final path = 'owner/stations/${widget.id}/availability';
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            DataCard(
              title: text(station['Name']),
              subtitle:
                  '${text(station['OpeningTime'])} – ${text(station['ClosingTime'])}',
              children: [
                DropdownButtonFormField<String>(
                  initialValue:
                      ['Active', 'Busy', 'Closed'].contains(station['Status'])
                      ? '${station['Status']}'
                      : 'Closed',
                  items: [
                    for (final s in ['Active', 'Busy', 'Closed'])
                      DropdownMenuItem(
                        value: s,
                        child: Text(
                          tr(context, switch (s) {
                            'Active' => 'متاحة',
                            'Busy' => 'مشغولة',
                            _ => 'مغلقة',
                          }, s),
                        ),
                      ),
                  ],
                  onChanged: (value) async {
                    if (await command(
                      context,
                      ref,
                      path,
                      method: 'PATCH',
                      body: {'status': value},
                    )) {
                      reload();
                    }
                  },
                ),
                for (final mode in [
                  (
                    'has_at_station',
                    'Has_At_Station',
                    'في المحطة',
                    'At station',
                  ),
                  (
                    'has_home_service',
                    'Has_Home_Service',
                    'منزلية',
                    'Home service',
                  ),
                  (
                    'has_delivery',
                    'Has_Delivery',
                    'استلام وإرجاع',
                    'Pickup & return',
                  ),
                ])
                  SwitchListTile(
                    title: Text(tr(context, mode.$3, mode.$4)),
                    value: '${station[mode.$2]}' == '1',
                    onChanged: (value) async {
                      if (await command(
                        context,
                        ref,
                        path,
                        method: 'PATCH',
                        body: {mode.$1: value},
                      )) {
                        reload();
                      }
                    },
                  ),
              ],
            ),
            FilledButton(
              onPressed: () async {
                final result = await editRecord(
                  context,
                  ref,
                  title: tr(
                    context,
                    'أوقات العمل والسعة والرسوم',
                    'Hours, capacity & fees',
                  ),
                  path: path,
                  method: 'PATCH',
                  fields: [
                    FieldSpec(
                      'opening_time',
                      'وقت الفتح HH:mm',
                      'Opening HH:mm',
                      initial: text(station['OpeningTime'], ''),
                    ),
                    FieldSpec(
                      'closing_time',
                      'وقت الإغلاق HH:mm',
                      'Closing HH:mm',
                      initial: text(station['ClosingTime'], ''),
                    ),
                    FieldSpec(
                      'capacity',
                      'سعة المحطة',
                      'Station capacity',
                      integer: true,
                      initial: text(station['BookingCapacity'], ''),
                    ),
                    FieldSpec(
                      'home_capacity',
                      'سعة الخدمات المنزلية',
                      'Home capacity',
                      integer: true,
                      initial: text(station['HomeCapacity'], ''),
                    ),
                    FieldSpec(
                      'default_service_minutes',
                      'المدة الافتراضية بالدقائق',
                      'Default duration minutes',
                      integer: true,
                      initial: text(station['DefaultServiceMinutes'], ''),
                    ),
                    FieldSpec(
                      'delivery_buffer_minutes',
                      'هامش التوصيل بالدقائق',
                      'Delivery buffer minutes',
                      integer: true,
                      initial: text(station['DeliveryBufferMinutes'], ''),
                    ),
                    FieldSpec(
                      'station_fee',
                      'رسوم المحطة',
                      'Station fee',
                      initial: text(station['StationFee'], ''),
                    ),
                    FieldSpec(
                      'home_fee',
                      'رسوم المنزل',
                      'Home fee',
                      initial: text(station['HomeFee'], ''),
                    ),
                    FieldSpec(
                      'delivery_fee',
                      'رسوم الاستلام والإرجاع',
                      'Pickup & return fee',
                      initial: text(station['DeliveryFee'], ''),
                    ),
                  ],
                );
                if (result != null) reload();
              },
              child: Text(
                tr(
                  context,
                  'تعديل أوقات العمل والسعة',
                  'Edit hours and capacity',
                ),
              ),
            ),
          ],
        );
      },
    ),
  );
}

class OwnerDriversScreen extends ConsumerWidget {
  const OwnerDriversScreen({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) => ClevoPage(
    title: tr(context, 'فريق السائقين', 'Driver team'),
    actions: [
      IconButton(
        icon: const Icon(Icons.person_add_alt),
        onPressed: () async {
          final result = await editRecord(
            context,
            ref,
            title: tr(context, 'إضافة سائق', 'Add driver'),
            path: 'owner/drivers',
            method: 'POST',
            fields: const [
              FieldSpec(
                'station_id',
                'رقم محطتي',
                'My station ID',
                integer: true,
              ),
              FieldSpec('name', 'الاسم', 'Name'),
              FieldSpec('phone', 'الهاتف', 'Phone'),
              FieldSpec('email', 'البريد الإلكتروني', 'Email', required: false),
            ],
          );
          if (result != null && context.mounted) {
            context.pushReplacement('/owner/drivers');
          }
        },
      ),
    ],
    child: ResourceList(
      path: 'owner/drivers',
      field: 'drivers',
      itemBuilder: (context, row, reload) => DataCard(
        title: text(row['UserName']),
        subtitle: '#${text(row['UserID'])} · ${text(row['PhoneNumber'])}',
        children: [
          Wrap(
            spacing: 8,
            children: [
              TextButton(
                onPressed: () async {
                  final result = await editRecord(
                    context,
                    ref,
                    title: tr(context, 'تعديل السائق', 'Edit driver'),
                    path: 'owner/drivers/${row['UserID']}',
                    method: 'PATCH',
                    fields: [
                      FieldSpec(
                        'name',
                        'الاسم',
                        'Name',
                        initial: text(row['UserName'], ''),
                      ),
                      FieldSpec(
                        'phone',
                        'الهاتف',
                        'Phone',
                        initial: text(row['PhoneNumber'], ''),
                      ),
                    ],
                  );
                  if (result != null) reload();
                },
                child: Text(tr(context, 'تعديل', 'Edit')),
              ),
              TextButton(
                onPressed: () => editRecord(
                  context,
                  ref,
                  title: tr(
                    context,
                    'دعوة لتفعيل الحساب',
                    'Invite to activate account',
                  ),
                  path: 'owner/drivers/${row['UserID']}/invitation',
                  method: 'POST',
                  fields: const [
                    FieldSpec('email', 'بريد السائق', 'Driver email'),
                  ],
                ),
                child: Text(tr(context, 'إرسال دعوة', 'Send invitation')),
              ),
              TextButton(
                onPressed: () async {
                  if (await command(
                    context,
                    ref,
                    'owner/drivers/${row['UserID']}',
                    method: 'DELETE',
                  )) {
                    reload();
                  }
                },
                child: Text(tr(context, 'إيقاف', 'Deactivate')),
              ),
            ],
          ),
        ],
      ),
    ),
  );
}

class EarningsScreen extends ConsumerWidget {
  const EarningsScreen({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) => ClevoPage(
    title: tr(context, 'الأرباح والعمولات', 'Earnings & commission'),
    child: FutureBuilder(
      future: ref.read(apiProvider).request('owner/financial-statement'),
      builder: (context, snapshot) {
        if (snapshot.hasError) {
          return ErrorPanel(
            snapshot.error!,
            () => context.pushReplacement('/owner/earnings'),
          );
        }
        if (!snapshot.hasData) {
          return const Center(child: CircularProgressIndicator());
        }
        final statement =
            snapshot.data!['financial_statement'] as Map? ?? snapshot.data!;
        final summary = statement['summary'] as Map? ?? {};
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            DataCard(
              title: tr(context, 'كشف الحساب المالي', 'Financial statement'),
              children: [
                Text(
                  '${tr(context, 'أرباح الاختبار بالفلس', 'Sandbox earnings in fils')}: ${text(summary['sandbox_payable_minor'])}',
                ),
                Text(
                  '${tr(context, 'عمولة نقدية مستحقة بالفلس', 'Cash commission due in fils')}: ${text(summary['cash_commission_due_minor'])}',
                ),
                Text(
                  tr(
                    context,
                    'عمولة الحجوزات الجديدة 5% من الخدمات والرسوم بعد الخصومات. التسويات التجارية غير مفعّلة.',
                    'New bookings use 5% of services and fees after discounts. Commercial settlements are disabled.',
                  ),
                ),
              ],
            ),
            TextButton(
              onPressed: () => context.push('/owner/statement'),
              child: Text(tr(context, 'حركات كشف الحساب', 'Statement entries')),
            ),
            OutlinedButton(
              onPressed: () => editRecord(
                context,
                ref,
                title: tr(context, 'حساب التسوية', 'Settlement account'),
                path: 'owner/bank-account',
                method: 'PUT',
                fields: const [
                  FieldSpec(
                    'account_holder_name',
                    'اسم صاحب الحساب',
                    'Account holder',
                  ),
                  FieldSpec('bank_name', 'البنك', 'Bank'),
                  FieldSpec('iban', 'IBAN', 'IBAN'),
                  FieldSpec(
                    'account_number',
                    'رقم الحساب',
                    'Account number',
                    required: false,
                  ),
                  FieldSpec('swift_code', 'SWIFT', 'SWIFT', required: false),
                ],
              ),
              child: Text(
                tr(context, 'تحديث الحساب البنكي', 'Update bank account'),
              ),
            ),
          ],
        );
      },
    ),
  );
}
