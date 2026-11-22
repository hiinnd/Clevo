import 'package:clevo/app.dart';
import 'package:clevo/core/state.dart';
import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'api_test.dart' show MemoryVault, client, reply, originalPair;

void main() {
  testWidgets('Owner station editor preserves the backend phone number', (
    tester,
  ) async {
    SharedPreferences.setMockInitialValues({'language': 'en'});
    final api = client(MemoryVault({...originalPair}), (request) {
      if (request.path == 'owner/stations') {
        return reply({
          'stations': [
            {
              'StationID': 1,
              'Name': 'Station',
              'Location': 'Amman',
              'PhoneNumber': '0791234567',
            },
          ],
        });
      }
      return reply({
        'user': {'id': 7, 'name': 'Owner', 'role': 'owner'},
      });
    });
    final container = ProviderContainer(
      overrides: [apiProvider.overrideWithValue(api)],
    );
    try {
      await tester.pumpWidget(
        UncontrolledProviderScope(
          container: container,
          child: const ClevoApp(),
        ),
      );
      await tester.pumpAndSettle();
      container.read(routerProvider).go('/owner/stations');
      await tester.pumpAndSettle();
      await tester.tap(find.text('Edit'));
      await tester.pumpAndSettle();
      expect(find.text('0791234567'), findsOneWidget);
      expect(tester.takeException(), isNull);
    } finally {
      await tester.pumpWidget(const SizedBox.shrink());
      container.dispose();
      api.dio.close();
      api.authDio.close();
    }
  });
}
