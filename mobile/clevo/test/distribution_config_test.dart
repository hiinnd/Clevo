import 'package:clevo/core/config.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test(
    'Distribution rejects unconfigured, local, insecure and credential URLs',
    () {
      for (final base in [
        'https://clevo.invalid/api/v1/',
        'http://api.clevo.jo/api/v1/',
        'https://localhost/api/v1/',
        'https://127.0.0.1/api/v1/',
        'https://example.com/api/v1/',
        'https://u:p@api.clevo.jo/api/v1/',
        'https://api.clevo.jo/api/v1/?secret=value',
      ]) {
        expect(() => validateDistributionEndpoint(base), throwsArgumentError);
      }
    },
  );
  test('Distribution URL structure accepts a public HTTPS API domain', () {
    // Syntax fixture only: no network request or domain ownership claim.
    expect(
      () => validateDistributionEndpoint('https://api.clevo.jo/api/v1/'),
      returnsNormally,
    );
  });
}
