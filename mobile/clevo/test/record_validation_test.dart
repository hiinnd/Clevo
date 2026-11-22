import 'package:clevo/ui/common.dart';
import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  for (final value in ['NaN', 'Infinity', '-Infinity', '1e999']) {
    testWidgets('Numeric form rejects non-finite $value before submission', (
      tester,
    ) async {
      await tester.pumpWidget(
        const ProviderScope(
          child: MaterialApp(
            home: Scaffold(
              body: RecordDialog(
                title: 'Coordinates',
                path: 'unused',
                method: 'PATCH',
                fields: [
                  FieldSpec('latitude', 'خط العرض', 'Latitude', number: true),
                ],
                fixed: {},
                idempotent: true,
              ),
            ),
          ),
        ),
      );
      await tester.enterText(find.byType(TextFormField), value);
      await tester.tap(find.byType(FilledButton));
      await tester.pumpAndSettle();
      expect(find.text('Invalid number'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  }
}
