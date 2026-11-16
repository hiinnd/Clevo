import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:geolocator/geolocator.dart';
import 'api.dart';

final locationReaderProvider = Provider<Future<Position> Function()>(
  (ref) => currentPosition,
);
Future<Position> currentPosition() async {
  if (!await Geolocator.isLocationServiceEnabled()) {
    throw const ApiFailure('location_disabled');
  }
  var permission = await Geolocator.checkPermission();
  if (permission == LocationPermission.denied) {
    permission = await Geolocator.requestPermission();
  }
  if (![
    LocationPermission.whileInUse,
    LocationPermission.always,
  ].contains(permission)) {
    throw const ApiFailure('location_permission_denied');
  }
  return Geolocator.getCurrentPosition(
    locationSettings: const LocationSettings(
      accuracy: LocationAccuracy.high,
      timeLimit: Duration(seconds: 12),
    ),
  );
}
