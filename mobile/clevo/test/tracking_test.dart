import 'dart:async';
import 'package:clevo/app.dart';
import 'package:clevo/core/location.dart';
import 'package:clevo/core/state.dart';
import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:geolocator/geolocator.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'api_test.dart' show MemoryVault, client, reply, originalPair;

Position sample() => Position(
  latitude: 31.95,
  longitude: 35.91,
  timestamp: DateTime.now(),
  accuracy: 12,
  altitude: 0,
  altitudeAccuracy: 0,
  heading: 0,
  headingAccuracy: 0,
  speed: 0,
  speedAccuracy: 0,
);
void main() {
  testWidgets(
    'backgrounding while GPS is pending prevents transmission and does not auto-resume',
    (tester) async {
      SharedPreferences.setMockInitialValues({'language': 'en'});
      final gps = Completer<Position>();
      var samples = 0;
      final api = client(MemoryVault({...originalPair}), (request) {
        if (request.path.endsWith('/locations')) samples++;
        if (request.path == 'me') {
          return reply({
            'user': {'id': 7, 'name': 'Driver test', 'role': 'driver'},
          });
        }
        return reply({
          'tracking': {
            'sharing_allowed': true,
            'delivery_stage': 'heading_pickup',
            'assignment_id': 12,
            'last_sequence': 0,
            'location_state': 'waiting',
            'background_tracking_allowed': false,
          },
        });
      });
      final container = ProviderContainer(
        overrides: [
          apiProvider.overrideWithValue(api),
          locationReaderProvider.overrideWithValue(() => gps.future),
        ],
      );
      await tester.pumpWidget(
        UncontrolledProviderScope(
          container: container,
          child: const ClevoApp(),
        ),
      );
      await tester.pumpAndSettle();
      container.read(routerProvider).go('/driver/job/42/tracking');
      await tester.pumpAndSettle();
      await tester.tap(find.byType(SwitchListTile));
      await tester.pump();
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.paused);
      await tester.pump();
      gps.complete(sample());
      await tester.pumpAndSettle();
      expect(samples, 0);
      tester.binding.handleAppLifecycleStateChanged(AppLifecycleState.resumed);
      await tester.pumpAndSettle();
      expect(
        tester.widget<SwitchListTile>(find.byType(SwitchListTile)).value,
        false,
      );
      await tester.pump(const Duration(seconds: 12));
      await tester.pumpAndSettle();
      expect(samples, 0);
      await tester.pumpWidget(const SizedBox.shrink());
      container.dispose();
      expect(tester.takeException(), isNull);
    },
  );
  testWidgets(
    'covering tracking screen and revoked job authorization stop location collection',
    (tester) async {
      SharedPreferences.setMockInitialValues({'language': 'en'});
      var samples = 0, denied = false;
      final api = client(MemoryVault({...originalPair}), (request) {
        if (request.path == 'me') {
          return reply({
            'user': {'id': 7, 'name': 'Driver test', 'role': 'driver'},
          });
        }
        if (request.path.endsWith('/locations')) {
          samples++;
          expect(request.data['capture_mode'], 'foreground');
          expect(request.data['assignment_id'], 12);
          return reply({'stored': true});
        }
        if (denied) return reply({}, 403);
        return reply({
          'tracking': {
            'sharing_allowed': true,
            'delivery_stage': 'heading_pickup',
            'assignment_id': 12,
            'last_sequence': samples,
            'location_state': 'waiting',
            'background_tracking_allowed': false,
          },
        });
      });
      final container = ProviderContainer(
        overrides: [
          apiProvider.overrideWithValue(api),
          locationReaderProvider.overrideWithValue(() async => sample()),
        ],
      );
      await tester.pumpWidget(
        UncontrolledProviderScope(
          container: container,
          child: const ClevoApp(),
        ),
      );
      await tester.pumpAndSettle();
      final router = container.read(routerProvider);
      router.go('/driver/job/42/tracking');
      await tester.pumpAndSettle();
      await tester.tap(find.byType(SwitchListTile));
      await tester.pumpAndSettle();
      expect(samples, 1);
      router.push('/profile');
      await tester.pumpAndSettle();
      await tester.pump(const Duration(seconds: 12));
      expect(samples, 1);
      router.pop();
      await tester.pumpAndSettle();
      expect(
        tester.widget<SwitchListTile>(find.byType(SwitchListTile)).value,
        false,
      );
      await tester.tap(find.byType(SwitchListTile));
      await tester.pumpAndSettle();
      expect(samples, 2);
      denied = true;
      await tester.pump(const Duration(seconds: 11));
      await tester.pumpAndSettle();
      expect(
        tester.widget<SwitchListTile>(find.byType(SwitchListTile)).value,
        false,
      );
      expect(samples, 2);
      await tester.pumpWidget(const SizedBox.shrink());
      container.dispose();
      expect(tester.takeException(), isNull);
    },
  );
}
