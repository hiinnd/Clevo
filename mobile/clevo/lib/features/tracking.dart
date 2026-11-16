import 'dart:async';
import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';
import '../core/api.dart';
import '../core/state.dart';
import '../core/tracking_policy.dart';
import '../core/router_events.dart';
import '../ui/common.dart';
import '../core/location.dart';

class TrackingScreen extends ConsumerStatefulWidget {
  const TrackingScreen({super.key, required this.id, this.driver = false});
  final String id;
  final bool driver;
  @override
  ConsumerState<TrackingScreen> createState() => _TrackingScreenState();
}

class _TrackingScreenState extends ConsumerState<TrackingScreen>
    with WidgetsBindingObserver, RouteAware {
  Map<String, dynamic>? _tracking, _pending;
  Object? _error;
  Timer? _timer;
  Timer? _freshness;
  DateTime? _projectedAt;
  bool _foreground = true, _sharing = false, _inflight = false;
  bool _visible = true;
  int _epoch = 0, _sequence = 0;
  int? _assignment;
  GoogleMapController? _map;
  String get path =>
      widget.driver ? 'driver/jobs/${widget.id}' : 'bookings/${widget.id}';
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _freshness = Timer.periodic(const Duration(seconds: 5), (_) {
      if (mounted && _foreground && _tracking != null) setState(() {});
    });
    poll();
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final route = ModalRoute.of(context);
    if (route != null) clevoRouteObserver.subscribe(this, route);
  }

  @override
  void didPushNext() {
    _visible = false;
    didChangeAppLifecycleState(AppLifecycleState.inactive);
  }

  @override
  void didPopNext() {
    _visible = true;
    didChangeAppLifecycleState(
      WidgetsBinding.instance.lifecycleState ?? AppLifecycleState.resumed,
    );
  }

  @override
  void dispose() {
    _epoch++;
    _pending = null;
    _timer?.cancel();
    _freshness?.cancel();
    _map?.dispose();
    clevoRouteObserver.unsubscribe(this);
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _foreground = state == AppLifecycleState.resumed && _visible;
    _epoch++;
    _timer?.cancel();
    _pending = null;
    if (!_foreground) {
      setState(() {
        _sharing = false;
        _tracking = null;
      });
    } else {
      poll();
    }
  }

  Future<void> poll() async {
    _timer?.cancel();
    if (_inflight || !_foreground || !mounted) return;
    _inflight = true;
    final epoch = _epoch;
    try {
      final data = await ref.read(apiProvider).request('$path/tracking');
      if (!mounted || epoch != _epoch || !_foreground) return;
      final tracking = Map<String, dynamic>.from(data['tracking'] as Map);
      final assignment = int.tryParse('${tracking['assignment_id']}');
      if (_assignment != assignment) {
        _pending = null;
        _sequence = 0;
        _sharing = false;
        _assignment = assignment;
      }
      final serverSequence = int.tryParse('${tracking['last_sequence']}') ?? 0;
      if (serverSequence > _sequence) _sequence = serverSequence;
      if (!canShareLocation(tracking, foreground: _foreground)) {
        _pending = null;
        _sharing = false;
      }
      setState(() {
        _tracking = tracking;
        _projectedAt = DateTime.now();
        _error = null;
      });
      final point = tracking['location'];
      if (point is Map && tracking['location_state'] == 'fresh') {
        _map?.animateCamera(
          CameraUpdate.newLatLng(
            LatLng(
              (point['latitude'] as num).toDouble(),
              (point['longitude'] as num).toDouble(),
            ),
          ),
        );
      }
      if (widget.driver &&
          _sharing &&
          canShareLocation(tracking, foreground: _foreground)) {
        await transmit(epoch);
      }
    } catch (error) {
      if (mounted && epoch == _epoch) {
        setState(() {
          _error = error;
          _tracking = null;
          if (error is ApiFailure &&
              [401, 403, 404, 409].contains(error.status)) {
            _sharing = false;
            _pending = null;
          }
        });
      }
    } finally {
      _inflight = false;
      if (mounted && _foreground) {
        _timer = Timer(const Duration(seconds: 10), poll);
      }
    }
  }

  Future<void> transmit(int epoch) async {
    // A single memory-only sample may be retried for <=110s with identical sequence/time.
    if (_pending != null && !reusableSample(_pending!, DateTime.now())) {
      _pending = null;
    }
    if (_pending == null) {
      final position = await ref.read(locationReaderProvider)();
      if (!mounted || epoch != _epoch || !_foreground || !_sharing) return;
      _pending = {
        'assignment_id': _assignment,
        'sequence': ++_sequence,
        'latitude': position.latitude,
        'longitude': position.longitude,
        'accuracy_m': position.accuracy,
        'captured_at': position.timestamp.toUtc().toIso8601String(),
        'capture_mode': 'foreground',
      };
      if (!reusableSample(_pending!, DateTime.now())) {
        _pending = null;
        return;
      }
    }
    if (!mounted || epoch != _epoch || !_foreground || !_sharing) return;
    await ref
        .read(apiProvider)
        .request('$path/locations', method: 'POST', body: _pending);
    if (epoch == _epoch) _pending = null;
  }

  @override
  Widget build(BuildContext context) {
    final tracking = _tracking, point = tracking?['location'] as Map?;
    final age =
        (int.tryParse('${point?['age_seconds']}') ?? 99999) +
        (_projectedAt == null
            ? 99999
            : DateTime.now().difference(_projectedAt!).inSeconds);
    final fresh =
        tracking?['location_state'] == 'fresh' && point != null && age <= 30;
    final maps = ref.watch(configProvider).mapsEnabled;
    final location = point == null
        ? null
        : LatLng(
            (point['latitude'] as num).toDouble(),
            (point['longitude'] as num).toDouble(),
          );
    return ClevoPage(
      title: tr(context, 'التتبع المصرّح', 'Authorized tracking'),
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          if (_error != null) ErrorPanel(_error!, poll),
          if (tracking == null && _error == null)
            const LinearProgressIndicator(),
          if (tracking != null)
            DataCard(
              title: text(tracking['delivery_stage']),
              subtitle:
                  '${tr(context, 'حالة الموقع', 'Location status')}: ${text(tracking['location_state'])}',
              children: [
                if (point != null)
                  Text(
                    '${tr(context, 'عمر القراءة بالثواني', 'Sample age seconds')}: ${text(point['age_seconds'])} · ±${text(point['accuracy_m'])} m',
                  ),
                if (!fresh)
                  Text(
                    tr(
                      context,
                      'لا يوجد موقع حديث موثوق. لا يُعرض موقع قديم كأنه حي.',
                      'No reliable fresh location. An old location is never presented as live.',
                    ),
                  ),
              ],
            ),
          if (fresh && maps)
            SizedBox(
              height: 380,
              child: GoogleMap(
                initialCameraPosition: CameraPosition(
                  target: location!,
                  zoom: 15,
                ),
                onMapCreated: (controller) => _map = controller,
                myLocationEnabled: false,
                myLocationButtonEnabled: false,
                markers: {
                  Marker(
                    markerId: const MarkerId('assigned_driver'),
                    position: location,
                  ),
                },
                zoomControlsEnabled: true,
              ),
            ),
          if (!maps)
            DataCard(
              title: tr(
                context,
                'الخريطة غير متاحة حالياً',
                'Map currently unavailable',
              ),
              subtitle: tr(
                context,
                'يمكنك متابعة حالة الرحلة من بيانات المهمة.',
                'You can still follow the trip status here.',
              ),
            ),
          if (widget.driver)
            SwitchListTile(
              value: _sharing,
              title: Text(
                tr(
                  context,
                  'مشاركة موقعي لهذه المهمة',
                  'Share my location for this job',
                ),
              ),
              subtitle: Text(
                tr(
                  context,
                  'أثناء فتح هذه الشاشة فقط. تتوقف عند مغادرة التطبيق أو انتهاء الصلاحية.',
                  'Only while this screen is open. Stops when the app leaves the foreground or authorization ends.',
                ),
              ),
              onChanged:
                  tracking == null ||
                      !canShareLocation(tracking, foreground: _foreground)
                  ? null
                  : (value) {
                      setState(() {
                        _sharing = value;
                        if (!value) _pending = null;
                      });
                      if (value) {
                        _timer?.cancel();
                        poll();
                      }
                    },
            ),
        ],
      ),
    );
  }
}
