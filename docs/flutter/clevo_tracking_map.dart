// Integration example. Requires Flutter, flutter_map and latlong2 in the host app.
// fetchTracking must use the host app's authenticated API client and refresh policy.
import 'dart:async';
import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

class ClevoTrackingMap extends StatefulWidget {
  const ClevoTrackingMap({super.key, required this.fetchTracking,
    required this.initialCenter, required this.tileUrl, required this.attribution,
    required this.unavailableText});
  final Future<Map<String, dynamic>> Function() fetchTracking;
  final LatLng initialCenter; // Customer/station position, never a fake driver marker.
  final String tileUrl; // Supply an approved commercial/self-hosted tile service.
  final String attribution;
  final String unavailableText; // Host localization, Arabic or English.
  @override
  State<ClevoTrackingMap> createState() => _ClevoTrackingMapState();
}

class _ClevoTrackingMapState extends State<ClevoTrackingMap>
    with WidgetsBindingObserver {
  Timer? _timer;
  LatLng? _driver;
  bool _busy = false, _visible = true;
  @override
  void initState() {
    super.initState(); WidgetsBinding.instance.addObserver(this);
    _timer = Timer.periodic(const Duration(seconds: 10), (_) => _poll());
    _poll();
  }
  Future<void> _poll() async {
    if (_busy || !_visible || !mounted) return;
    _busy = true;
    try {
      final data = await widget.fetchTracking().timeout(const Duration(seconds: 8));
      final location = data['location'];
      LatLng? next;
      if (data['sharing_allowed'] == true && data['location_state'] == 'fresh' &&
          location is Map && location['latitude'] is num &&
          location['longitude'] is num && location['age_seconds'] is num &&
          (location['age_seconds'] as num) <= 30) {
        final lat = (location['latitude'] as num).toDouble();
        final lng = (location['longitude'] as num).toDouble();
        if (lat.isFinite && lng.isFinite && lat.abs() <= 90 && lng.abs() <= 180) {
          next = LatLng(lat, lng);
        }
      }
      if (mounted && _visible) setState(() => _driver = next);
      if (['Completed', 'Cancelled', 'Rejected', 'Cancelled_Timeout']
          .contains(data['booking_status'])) _timer?.cancel();
    } catch (_) { if (mounted) setState(() => _driver = null); }
    finally { _busy = false; }
  }
  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _visible = state == AppLifecycleState.resumed;
    if (mounted) setState(() => _driver = null);
    if (_visible) _poll();
  }
  @override
  void dispose() {
    _timer?.cancel(); WidgetsBinding.instance.removeObserver(this); super.dispose();
  }
  @override
  Widget build(BuildContext context) => Column(children: [
    if (_driver == null) Text(widget.unavailableText),
    Expanded(child: FlutterMap(options: MapOptions(
      initialCenter: widget.initialCenter, initialZoom: 14), children: [
        TileLayer(urlTemplate: widget.tileUrl, userAgentPackageName: 'clevo.app'),
        MarkerLayer(markers: [if (_driver != null) Marker(point: _driver!,
          width: 40, height: 40, child: const Icon(Icons.directions_car))]),
      ])),
    Text(widget.attribution),
  ]);
}
