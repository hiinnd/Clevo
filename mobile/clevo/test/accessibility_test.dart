import 'package:clevo/app.dart';
import 'package:clevo/core/state.dart';
import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'api_test.dart' show MemoryVault, client, reply, originalPair;

void main() {
  for (final language in ['en', 'ar']) {
    for (final role in ['guest', 'customer', 'owner', 'driver']) {
      testWidgets('$language $role accessibility and 200% compact text', (
        tester,
      ) async {
        tester.view.physicalSize = const Size(390, 844);
        tester.view.devicePixelRatio = 1;
        tester.platformDispatcher.textScaleFactorTestValue = 2;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        addTearDown(tester.platformDispatcher.clearTextScaleFactorTestValue);
        SharedPreferences.setMockInitialValues({'language': language});
        final api = client(
          MemoryVault(role == 'guest' ? null : {...originalPair}),
          (_) => reply({
            'user': {'id': 7, 'name': 'QA', 'role': role},
          }),
        );
        final container = ProviderContainer(
          overrides: [apiProvider.overrideWithValue(api)],
        );
        final semantics = tester.ensureSemantics();
        try {
          await tester.pumpWidget(
            UncontrolledProviderScope(
              container: container,
              child: const ClevoApp(),
            ),
          );
          await tester.pumpAndSettle();
          expect(
            tester.takeException(),
            isNull,
            reason: 'No overflow with large text',
          );
          await expectLater(tester, meetsGuideline(androidTapTargetGuideline));
          await expectLater(tester, meetsGuideline(iOSTapTargetGuideline));
          await expectLater(tester, meetsGuideline(labeledTapTargetGuideline));
          await expectLater(tester, meetsGuideline(textContrastGuideline));
        } finally {
          await tester.pumpWidget(const SizedBox.shrink());
          semantics.dispose();
          container.dispose();
          api.dio.close();
          api.authDio.close();
        }
      });
    }
  }
}
