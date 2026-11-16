import 'package:clevo/core/config.dart';
import 'package:clevo/core/navigation.dart';
import 'package:clevo/core/notifications.dart';
import 'package:clevo/core/tracking_policy.dart';
import 'package:clevo/features/payments.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('production URL requires HTTPS and a fixed API prefix', () {
    for (final url in [
      'http://example.test/api/v1/',
      'https://u:p@example.test/api/v1/',
      'https://example.test/api/',
      'https://example.test/api/v1/?token=bad',
    ]) {
      expect(() => AppConfig(apiBase: url), throwsArgumentError);
    }
    expect(
      () =>
          AppConfig(apiBase: 'http://10.0.2.2/api/v1/', localDevelopment: true),
      returnsNormally,
    );
    expect(
      () => AppConfig(
        apiBase: 'http://example.test/api/v1/',
        localDevelopment: true,
      ),
      throwsArgumentError,
    );
  });
  test('role guards prevent local elevation and leave admin on the web', () {
    for (final role in ['customer', 'owner', 'driver']) {
      expect(roleAllows(role, '/$role'), true);
      for (final other in [
        'customer',
        'owner',
        'driver',
      ].where((r) => r != role)) {
        expect(roleAllows(role, '/$other'), false);
      }
    }
    expect(roleAllows('admin', '/owner/stations'), false);
    expect(roleAllows('owner', '/payment-orders'), false);
    expect(roleAllows('driver', '/booking/5'), false);
  });
  test('notification deep links are allowlisted and role constrained', () {
    expect(
      notificationTarget({'screen': 'driver_job', 'entity_id': '6'}, 'driver'),
      '/driver/job/6',
    );
    expect(
      notificationTarget({
        'screen': 'driver_job',
        'entity_id': '6',
      }, 'customer'),
      isNull,
    );
    for (final id in [
      '../profile',
      '-1',
      '0',
      'https://evil.test',
      '2?admin=1',
    ]) {
      expect(
        notificationTarget({'screen': 'booking', 'entity_id': id}, 'customer'),
        isNull,
      );
    }
    expect(
      notificationTarget({'screen': 'https://evil.test'}, 'customer'),
      isNull,
    );
  });
  test('hosted checkout allows only Jordan provider HTTPS payment routes', () {
    expect(
      trustedCheckout(
        Uri.parse('https://secure-jordan.paytabs.com/payment/abc'),
      ),
      true,
    );
    for (final url in [
      'http://secure-jordan.paytabs.com/payment/a',
      'https://secure-jordan.paytabs.com.evil.test/payment/a',
      'https://evil@secure-jordan.paytabs.com/payment/a',
      'https://secure-jordan.paytabs.com:8443/payment/a',
    ]) {
      expect(trustedCheckout(Uri.parse(url)), false);
    }
  });
  test(
    'location requires foreground, active stage and backend authorization',
    () {
      for (final stage in [
        'assigned',
        'accepted',
        'arrived_station',
        'service_completed',
        'delivered',
        'cancelled',
      ]) {
        expect(
          canShareLocation({
            'sharing_allowed': true,
            'delivery_stage': stage,
          }, foreground: true),
          false,
        );
      }
      expect(
        canShareLocation({
          'sharing_allowed': true,
          'delivery_stage': 'heading_pickup',
        }, foreground: true),
        true,
      );
      expect(
        canShareLocation({
          'sharing_allowed': true,
          'delivery_stage': 'heading_pickup',
        }, foreground: false),
        false,
      );
      expect(
        canShareLocation({
          'sharing_allowed': false,
          'delivery_stage': 'heading_pickup',
        }, foreground: true),
        false,
      );
    },
  );
  test('offline location recovery retains only recent original timestamps', () {
    final now = DateTime.utc(2026, 11, 16);
    expect(
      reusableSample({
        'captured_at': now
            .subtract(const Duration(seconds: 30))
            .toIso8601String(),
      }, now),
      true,
    );
    expect(
      reusableSample({
        'captured_at': now
            .subtract(const Duration(seconds: 121))
            .toIso8601String(),
      }, now),
      false,
    );
    expect(
      reusableSample({
        'captured_at': now.add(const Duration(seconds: 16)).toIso8601String(),
      }, now),
      false,
    );
  });
}
