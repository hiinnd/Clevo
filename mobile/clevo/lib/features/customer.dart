import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'package:go_router/go_router.dart';
import '../core/api.dart';
import '../core/location.dart';
import '../core/state.dart';
import '../ui/common.dart';

class StationsScreen extends ConsumerStatefulWidget {
  const StationsScreen({super.key});
  @override
  ConsumerState<StationsScreen> createState() => _StationsScreenState();
}

class _StationsScreenState extends ConsumerState<StationsScreen> {
  final _search = TextEditingController();
  Map<String, dynamic> _query = {};
  @override
  void dispose() {
    _search.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => ClevoPage(
    title: tr(context, 'المحطات', 'Stations'),
    actions: [
      IconButton(
        icon: const Icon(Icons.tune),
        tooltip: tr(context, 'تصفية', 'Filters'),
        onPressed: () async {
          final values = await showDialog<Map<String, dynamic>>(
            context: context,
            builder: (_) => const StationFilters(),
          );
          if (values != null) {
            setState(() => _query = {'query': _search.text.trim(), ...values});
          }
        },
      ),
    ],
    child: Column(
      children: [
        Padding(
          padding: const EdgeInsets.all(16),
          child: SearchBar(
            controller: _search,
            hintText: tr(context, 'اسم المحطة', 'Station name'),
            leading: const Icon(Icons.search),
            onSubmitted: (value) =>
                setState(() => _query = {..._query, 'query': value.trim()}),
          ),
        ),
        Expanded(
          child: ResourceList(
            path: 'stations',
            field: 'stations',
            query: _query,
            itemBuilder: (context, row, reload) => DataCard(
              title: text(row['Name']),
              subtitle: '${text(row['Location'])} · ${text(row['Rating'])} ★',
              trailing: const Icon(Icons.chevron_right),
              onTap: () => context.push('/station/${row['StationID']}'),
              children: [
                const SizedBox(height: 12),
                Text(
                  '${tr(context, 'من', 'From')} ${money(row['PriceMin'])} · ${statusText(context, row['Status'])}',
                ),
              ],
            ),
          ),
        ),
      ],
    ),
  );
}

class StationFilters extends StatefulWidget {
  const StationFilters({super.key});
  @override
  State<StationFilters> createState() => _StationFiltersState();
}

class _StationFiltersState extends State<StationFilters> {
  final _price = TextEditingController(), _location = TextEditingController();
  double _rating = 0;
  String _sort = 'rating', _mode = '';
  Position? _position;
  bool _locating = false;
  @override
  void dispose() {
    _price.dispose();
    _location.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: Text(tr(context, 'البحث والتصفية', 'Search filters')),
    content: SizedBox(
      width: 400,
      child: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: _location,
              decoration: InputDecoration(
                labelText: tr(context, 'المنطقة', 'Area'),
              ),
            ),
            TextField(
              controller: _price,
              keyboardType: TextInputType.number,
              decoration: InputDecoration(
                labelText: tr(context, 'أعلى سعر JOD', 'Maximum price JOD'),
              ),
            ),
            Text(
              '${tr(context, 'أدنى تقييم', 'Minimum rating')}: ${_rating.toStringAsFixed(1)}',
            ),
            Slider(
              value: _rating,
              min: 0,
              max: 5,
              divisions: 10,
              onChanged: (value) => setState(() => _rating = value),
            ),
            DropdownButtonFormField<String>(
              initialValue: _mode,
              decoration: InputDecoration(
                labelText: tr(context, 'طريقة الخدمة', 'Service mode'),
              ),
              items: [
                DropdownMenuItem(
                  value: '',
                  child: Text(tr(context, 'الكل', 'All')),
                ),
                DropdownMenuItem(
                  value: 'station',
                  child: Text(tr(context, 'في المحطة', 'At station')),
                ),
                DropdownMenuItem(
                  value: 'home',
                  child: Text(tr(context, 'منزلية', 'At home')),
                ),
                DropdownMenuItem(
                  value: 'delivery',
                  child: Text(
                    tr(context, 'استلام وإرجاع', 'Pickup and return'),
                  ),
                ),
              ],
              onChanged: (value) => setState(() => _mode = value!),
            ),
            DropdownButtonFormField<String>(
              initialValue: _sort,
              items: [
                DropdownMenuItem(
                  value: 'rating',
                  child: Text(tr(context, 'التقييم', 'Rating')),
                ),
                DropdownMenuItem(
                  value: 'price',
                  child: Text(tr(context, 'السعر', 'Price')),
                ),
              ],
              onChanged: (value) => setState(() => _sort = value!),
            ),
            OutlinedButton.icon(
              icon: const Icon(Icons.my_location),
              label: Text(
                tr(
                  context,
                  'البحث ضمن 25 كم من موقعي',
                  'Search within 25 km of me',
                ),
              ),
              onPressed: _locating
                  ? null
                  : () async {
                      setState(() => _locating = true);
                      try {
                        final location = await currentPosition();
                        if (mounted) setState(() => _position = location);
                      } catch (error) {
                        if (context.mounted) showFailure(context, error);
                      } finally {
                        if (mounted) setState(() => _locating = false);
                      }
                    },
            ),
            if (_position != null)
              Text(
                tr(
                  context,
                  'تم تحديد الموقع للبحث',
                  'Location selected for search',
                ),
              ),
          ],
        ),
      ),
    ),
    actions: [
      TextButton(
        onPressed: () => Navigator.pop(context),
        child: Text(tr(context, 'إلغاء', 'Cancel')),
      ),
      FilledButton(
        onPressed: () {
          final price = double.tryParse(_price.text);
          if (_price.text.isNotEmpty &&
              (price == null || !price.isFinite || price < 0)) {
            return;
          }
          Navigator.pop(context, <String, dynamic>{
            'location': _location.text.trim(),
            'min_rating': _rating,
            'sort': _sort,
            'direction': _sort == 'price' ? 'asc' : 'desc',
            'max_price': ?price,
            if (_mode.isNotEmpty) 'mode': _mode,
            if (_position != null) ...{
              'lat': _position!.latitude,
              'lng': _position!.longitude,
              'radius_km': 25,
            },
          });
        },
        child: Text(tr(context, 'تطبيق', 'Apply')),
      ),
    ],
  );
}

class StationScreen extends ConsumerStatefulWidget {
  const StationScreen(this.id, {super.key});
  final int id;
  @override
  ConsumerState<StationScreen> createState() => _StationScreenState();
}

class _StationScreenState extends ConsumerState<StationScreen> {
  late Future<Map<String, dynamic>> _station;
  final _selected = <int>{}, _services = <Map<String, dynamic>>[];
  final _address = TextEditingController(),
      _lat = TextEditingController(),
      _lng = TextEditingController();
  DateTime? _date;
  TimeOfDay? _time;
  String _mode = 'station';
  Map<String, dynamic>? _quote;
  bool _busy = false, _unknown = false, _usePlan = false;
  int _servicePage = 0, _totalPages = 1;
  String _key = operationKey();
  @override
  void initState() {
    super.initState();
    _station = ref.read(apiProvider).request('stations/${widget.id}');
    loadServices();
  }

  @override
  void dispose() {
    _address.dispose();
    _lat.dispose();
    _lng.dispose();
    super.dispose();
  }

  Future<void> loadServices() async {
    try {
      final page = await ref
          .read(apiProvider)
          .list(
            'stations/${widget.id}/services',
            'services',
            page: _servicePage + 1,
          );
      if (mounted) {
        setState(() {
          _services.addAll(page.rows);
          _servicePage = page.page;
          _totalPages = page.totalPages;
        });
      }
    } catch (error) {
      if (mounted) showFailure(context, error);
    }
  }

  void changed(VoidCallback change) => setState(() {
    change();
    _quote = null;
    _key = operationKey();
  });
  String get date =>
      '${_date!.year}-${_date!.month.toString().padLeft(2, '0')}-${_date!.day.toString().padLeft(2, '0')}';
  String get time =>
      '${_time!.hour.toString().padLeft(2, '0')}:${_time!.minute.toString().padLeft(2, '0')}';
  Future<void> availability() async {
    if (_selected.isEmpty || _date == null || _time == null) return;
    setState(() => _busy = true);
    try {
      final response = await ref
          .read(apiProvider)
          .request(
            'stations/${widget.id}/availability',
            query: {
              'date': date,
              'time': time,
              'mode': _mode,
              'service_ids': _selected.join(','),
            },
          );
      if (mounted) {
        setState(
          () => _quote = Map<String, dynamic>.from(
            response['availability'] as Map,
          ),
        );
      }
    } catch (error) {
      if (mounted) showFailure(context, error);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> book() async {
    if (ref.read(sessionProvider).asData?.value?.role != 'customer') {
      context.go('/login');
      return;
    }
    if (_mode != 'station' &&
        (double.tryParse(_lat.text) == null ||
            double.tryParse(_lng.text) == null ||
            _address.text.trim().isEmpty)) {
      showFailure(context, const ApiFailure('location_required'));
      return;
    }
    setState(() => _busy = true);
    try {
      final result = await ref
          .read(apiProvider)
          .request(
            'bookings',
            method: 'POST',
            key: _key,
            body: {
              'station_id': widget.id,
              'service_ids': _selected.toList(),
              'service_location': _mode,
              'booking_date': date,
              'booking_time': time,
              'use_plan': _usePlan,
              if (_mode != 'station') ...{
                'latitude': double.parse(_lat.text),
                'longitude': double.parse(_lng.text),
                'address_details': _address.text.trim(),
              },
            },
          );
      if (mounted) context.go('/booking/${result['booking_id']}');
    } catch (error) {
      if (mounted) {
        showFailure(context, error);
        setState(() => _unknown = error is ApiFailure && error.uncertain);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => ClevoPage(
    title: tr(context, 'تفاصيل المحطة', 'Station details'),
    child: FutureBuilder(
      future: _station,
      builder: (context, snapshot) {
        if (snapshot.hasError) {
          return ErrorPanel(
            snapshot.error!,
            () => setState(
              () => _station = ref
                  .read(apiProvider)
                  .request('stations/${widget.id}'),
            ),
          );
        }
        if (!snapshot.hasData) {
          return const Center(child: CircularProgressIndicator());
        }
        final station = Map<String, dynamic>.from(
          snapshot.data!['station'] as Map,
        );
        final modes = [
          if ('${station['Has_At_Station']}' == '1') 'station',
          if ('${station['Has_Home_Service']}' == '1') 'home',
          if ('${station['Has_Delivery']}' == '1') 'delivery',
        ];
        if (!modes.contains(_mode) && modes.isNotEmpty) _mode = modes.first;
        final appointment = _quote?['appointment'] as Map?;
        return ListView(
          padding: const EdgeInsets.all(20),
          children: [
            DataCard(
              title: text(station['Name']),
              subtitle:
                  '${text(station['Location'])} · ${text(station['Rating'])} ★',
              children: [
                Text(
                  '${text(station['OpeningTime'])} — ${text(station['ClosingTime'])}',
                ),
              ],
            ),
            Text(
              tr(context, 'الخدمات والأسعار', 'Services and prices'),
              style: Theme.of(context).textTheme.titleLarge,
            ),
            for (final service in _services)
              CheckboxListTile(
                value: _selected.contains(idOf(service, 'ServiceID')),
                onChanged: _busy || _unknown
                    ? null
                    : (value) => changed(() {
                        if (value == true && _selected.length < 30) {
                          _selected.add(idOf(service, 'ServiceID'));
                        } else {
                          _selected.remove(idOf(service, 'ServiceID'));
                        }
                      }),
                title: Text(localizedName(context, service)),
                subtitle: Text(
                  '${money(service['Price'])} · ${text(service['Duration'])} ${tr(context, 'دقيقة', 'min')}',
                ),
              ),
            if (_servicePage < _totalPages)
              TextButton(
                onPressed: loadServices,
                child: Text(tr(context, 'المزيد من الخدمات', 'More services')),
              ),
            DropdownButtonFormField<String>(
              initialValue: modes.contains(_mode) ? _mode : null,
              decoration: InputDecoration(
                labelText: tr(context, 'طريقة الخدمة', 'Service mode'),
              ),
              items: [
                if ('${station['Has_At_Station']}' == '1')
                  DropdownMenuItem(
                    value: 'station',
                    child: Text(tr(context, 'في المحطة', 'At station')),
                  ),
                if ('${station['Has_Home_Service']}' == '1')
                  DropdownMenuItem(
                    value: 'home',
                    child: Text(tr(context, 'عناية منزلية', 'Home service')),
                  ),
                if ('${station['Has_Delivery']}' == '1')
                  DropdownMenuItem(
                    value: 'delivery',
                    child: Text(
                      tr(
                        context,
                        'استلام وإرجاع السيارة',
                        'Home pickup and return',
                      ),
                    ),
                  ),
              ],
              onChanged: _busy || _unknown
                  ? null
                  : (value) => changed(() => _mode = value!),
            ),
            Row(
              children: [
                Expanded(
                  child: OutlinedButton(
                    onPressed: _busy || _unknown
                        ? null
                        : () async {
                            final value = await showDatePicker(
                              context: context,
                              initialDate: DateTime.now().add(
                                const Duration(days: 1),
                              ),
                              firstDate: DateTime.now(),
                              lastDate: DateTime.now().add(
                                const Duration(days: 365),
                              ),
                            );
                            if (value != null) changed(() => _date = value);
                          },
                    child: Text(
                      _date == null ? tr(context, 'التاريخ', 'Date') : date,
                    ),
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: OutlinedButton(
                    onPressed: _busy || _unknown
                        ? null
                        : () async {
                            final value = await showTimePicker(
                              context: context,
                              initialTime: const TimeOfDay(hour: 10, minute: 0),
                            );
                            if (value != null) changed(() => _time = value);
                          },
                    child: Text(
                      _time == null ? tr(context, 'الوقت', 'Time') : time,
                    ),
                  ),
                ),
              ],
            ),
            Text(
              tr(
                context,
                'المواعيد بتوقيت المحطة في الأردن',
                'Appointment times use the station’s Jordan local time',
              ),
            ),
            if (_mode != 'station') ...[
              TextField(
                controller: _address,
                enabled: !_busy && !_unknown,
                decoration: InputDecoration(
                  labelText: tr(context, 'تفاصيل العنوان', 'Address details'),
                ),
              ),
              Row(
                children: [
                  Expanded(
                    child: TextField(
                      controller: _lat,
                      enabled: !_busy && !_unknown,
                      keyboardType: TextInputType.number,
                      decoration: InputDecoration(
                        labelText: tr(context, 'خط العرض', 'Latitude'),
                      ),
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: TextField(
                      controller: _lng,
                      enabled: !_busy && !_unknown,
                      keyboardType: TextInputType.number,
                      decoration: InputDecoration(
                        labelText: tr(context, 'خط الطول', 'Longitude'),
                      ),
                    ),
                  ),
                ],
              ),
              OutlinedButton.icon(
                onPressed: _busy || _unknown
                    ? null
                    : () async {
                        try {
                          final p = await currentPosition();
                          if (mounted) {
                            setState(() {
                              _lat.text = '${p.latitude}';
                              _lng.text = '${p.longitude}';
                            });
                          }
                        } catch (error) {
                          if (context.mounted) showFailure(context, error);
                        }
                      },
                icon: const Icon(Icons.my_location),
                label: Text(tr(context, 'استخدام موقعي', 'Use my location')),
              ),
            ],
            SwitchListTile(
              value: _usePlan,
              title: Text(
                tr(
                  context,
                  'استخدام باقتي المؤهلة',
                  'Use my eligible subscription',
                ),
              ),
              onChanged: _busy || _unknown
                  ? null
                  : (value) => changed(() => _usePlan = value),
            ),
            if (_unknown)
              Text(
                tr(
                  context,
                  'لم تتأكد النتيجة؛ أعد إرسال الطلب نفسه أو راجع حجوزاتي.',
                  'Outcome unknown. Retry the identical request or check My bookings.',
                ),
              ),
            OutlinedButton(
              onPressed: _busy || _unknown ? null : availability,
              child: Text(
                tr(
                  context,
                  'التحقق من التوفر والسعر',
                  'Check availability and price',
                ),
              ),
            ),
            if (appointment != null)
              DataCard(
                title: money(appointment['total']),
                subtitle:
                    '${tr(context, 'الخدمات', 'Services')}: ${money(appointment['services_subtotal'])} · ${tr(context, 'الرسوم', 'Fees')}: ${money(appointment['additional_fee'])}',
                children: [
                  Text(
                    '${text(appointment['duration_minutes'])} ${tr(context, 'دقيقة', 'minutes')} · ${appointment['available'] == true ? tr(context, 'متاح', 'Available') : tr(context, 'غير متاح', 'Unavailable')}',
                  ),
                ],
              ),
            FilledButton(
              onPressed:
                  !_busy && (appointment?['available'] == true || _unknown)
                  ? book
                  : null,
              child: Text(tr(context, 'طلب الحجز', 'Request booking')),
            ),
            TextButton(
              onPressed: () => context.push('/station/${widget.id}/reviews'),
              child: Text(tr(context, 'التقييمات', 'Reviews')),
            ),
          ],
        );
      },
    ),
  );
}
