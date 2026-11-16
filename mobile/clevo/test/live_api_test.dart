import 'dart:convert';
import 'dart:io';
import 'package:clevo/core/api.dart';
import 'package:clevo/core/config.dart';
import 'package:flutter_test/flutter_test.dart';
import 'api_test.dart' show MemoryVault;

void main() {
  final path = Platform.environment['CLEVO_MOBILE_FIXTURE_FILE'];
  test(
    'real PHP/MySQL customer, owner and driver integration with isolation and replay protection',
    () async {
      final fixture = jsonDecode(await File(path!).readAsString()) as Map;
      final url = '${fixture['api_url']}';
      final parsed = Uri.parse(url);
      // This suite never targets a commercial or external environment.
      expect(parsed.host, '127.0.0.1');
      expect(parsed.port, 18089);
      final clients = <String, ClevoApi>{};
      for (final role in ['customer', 'other', 'owner', 'driver']) {
        final api = ClevoApi(
          AppConfig(apiBase: url, localDevelopment: true),
          MemoryVault(),
        );
        clients[role] = api;
        await api.authenticate(
          '${fixture[role]['login']}',
          '${fixture['password']}',
        );
        final me = await api.request('me');
        expect(
          (me['user'] as Map)['role'],
          role == 'other' ? 'customer' : role,
        );
      }
      try {
        final customer = clients['customer']!,
            owner = clients['owner']!,
            driver = clients['driver']!;
        final station = fixture['station_id'], service = fixture['service_id'];
        final public = await customer.request('stations/$station');
        expect((public['station'] as Map)['StationID'], station);
        final services = await owner.list(
          'owner/stations/$station/services',
          'services',
        );
        expect(services.rows.any((r) => r['ServiceID'] == service), true);
        final availability = await customer.request(
          'stations/$station/availability',
          query: {
            'date': fixture['booking_date'],
            'time': '10:00',
            'mode': 'station',
            'service_ids': '$service',
          },
        );
        expect(
          ((availability['availability'] as Map)['appointment']
              as Map)['available'],
          true,
        );
        final key = operationKey();
        final body = {
          'station_id': station,
          'service_ids': [service],
          'service_location': 'station',
          'booking_date': fixture['booking_date'],
          'booking_time': '10:00',
          'use_plan': false,
        };
        final booking = await customer.request(
          'bookings',
          method: 'POST',
          body: body,
          key: key,
        );
        final retry = await customer.request(
          'bookings',
          method: 'POST',
          body: body,
          key: key,
        );
        expect(retry['booking_id'], booking['booking_id']);
        final id = booking['booking_id'];
        final detail = await customer.request('bookings/$id');
        expect(
          (detail['booking'] as Map)['ServicesSubtotal'],
          fixture['service_price'],
        );
        await expectLater(
          clients['other']!.request('bookings/$id'),
          throwsA(
            isA<ApiFailure>().having((e) => e.status, 'IDOR status', 404),
          ),
        );
        await expectLater(
          driver.request('bookings/$id'),
          throwsA(
            isA<ApiFailure>().having((e) => e.status, 'unassigned booking', 404),
          ),
        );
        await expectLater(
          driver.request('owner/stations'),
          throwsA(
            isA<ApiFailure>().having((e) => e.status, 'role status', 403),
          ),
        );
        await owner.request(
          'owner/bookings/$id/accept',
          method: 'POST',
          key: operationKey(),
          body: {},
        );
        final status = await customer.request('bookings/$id/status');
        expect((status['booking'] as Map)['Status'], 'Accepted');
        final jobs = await driver.list('driver/jobs', 'tasks');
        expect(jobs.rows, isNotEmpty);
        await expectLater(
          clients['other']!.request(
            'bookings/${fixture['driver_booking_id']}/tracking',
          ),
          throwsA(
            isA<ApiFailure>().having(
              (e) => e.status,
              'tracking ownership',
              404,
            ),
          ),
        );
        final tracking = await driver.request(
          'driver/jobs/${fixture['driver_booking_id']}/tracking',
        );
        expect(
          (tracking['tracking'] as Map)['background_tracking_allowed'],
          false,
        );
        final preferences = await customer.request(
          'me/notification-preferences',
        );
        expect(preferences['preferences'], isA<Map>());
        await customer.request(
          'me/notification-preferences',
          method: 'PUT',
          key: operationKey(),
          body: {
            'language': 'en',
            'push_enabled': false,
            'email_enabled': false,
          },
        );
        final wallet = await customer.request('me/wallet');
        expect(
          (wallet['wallet'] as Map)['commercial_stored_value_enabled'],
          false,
        );
        await expectLater(
          customer.request(
            'payment-orders',
            method: 'POST',
            key: operationKey(),
            body: {'purpose': 'booking', 'booking_id': id},
          ),
          throwsA(
            isA<ApiFailure>().having(
              (e) => e.status,
              'no fake online success',
              503,
            ),
          ),
        );
      } finally {
        for (final api in clients.values) {
          await api.logout();
          api.dio.close();
          api.authDio.close();
        }
      }
    },
    skip: path == null
        ? 'Run through tests/run-isolated.ps1 -Flutter ... with disposable fixtures.'
        : false,
  );
}
