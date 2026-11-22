import 'package:clevo/app.dart';
import 'package:clevo/core/state.dart';
import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'api_test.dart' show MemoryVault, client, reply, originalPair;

void main() {
  testWidgets(
    'Deletion request requires confirmation and password, then displays pending state',
    (tester) async {
      SharedPreferences.setMockInitialValues({'language': 'en'});
      var requested = false;
      final api = client(MemoryVault({...originalPair}), (request) {
        if (request.path == 'legal') {
          return reply({
            'legal': {'approved': false, 'deletion_enabled': true},
          });
        }
        if (request.path == 'me/account-deletion-request') {
          if (request.method == 'POST') {
            expect(request.data['confirm'], true);
            expect(request.data['password'], 'SyntheticPassword!');
            expect(request.headers['Idempotency-Key'], isNotEmpty);
            requested = true;
          }
          return reply({
            'request': requested
                ? {'RequestID': 12, 'State': 'requested'}
                : null,
          });
        }
        return reply({
          'user': {'id': 7, 'name': 'QA', 'role': 'customer'},
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
        container.read(routerProvider).go('/privacy');
        await tester.pumpAndSettle();
        expect(find.text('Policy approval is pending.'), findsOneWidget);
        await tester.tap(find.text('Request account deletion'));
        await tester.pumpAndSettle();
        expect(requested, false);
        expect(
          find.textContaining('A request does not mean deletion is complete.'),
          findsOneWidget,
        );
        await tester.tap(find.text('Continue'));
        await tester.pumpAndSettle();
        await tester.enterText(
          find.byType(TextFormField).first,
          'SyntheticPassword!',
        );
        final dialog = find.byType(AlertDialog);
        await tester.tap(
          find.descendant(of: dialog, matching: find.byType(FilledButton)).last,
        );
        await tester.pumpAndSettle();
        expect(requested, true);
        expect(find.text('Cancel request'), findsOneWidget);
        expect(tester.takeException(), isNull);
      } finally {
        await tester.pumpWidget(const SizedBox.shrink());
        container.dispose();
        api.dio.close();
        api.authDio.close();
      }
    },
  );
}
