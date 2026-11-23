import 'package:clevo/ui/value_labels.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test(
    'All registered state labels have both languages without changing wire values',
    () {
      for (final entry in valueLabels.entries) {
        expect(valueLabel(entry.key, 'ar'), entry.value.$1);
        expect(valueLabel(entry.key, 'en'), entry.value.$2);
        expect(entry.value.$1.trim(), isNotEmpty);
        expect(entry.value.$2.trim(), isNotEmpty);
      }
    },
  );
  test(
    'New server state is visible and cannot be mislabeled as payment success',
    () {
      expect(
        valueLabel('provider_status_unknown', 'ar'),
        'provider_status_unknown',
      );
      expect(valueLabel(null, 'ar'), '—');
      expect(valueLabel('pending', 'ar'), isNot(valueLabel('succeeded', 'ar')));
    },
  );
}
