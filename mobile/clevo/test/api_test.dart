import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';
import 'package:clevo/core/api.dart';
import 'package:clevo/core/config.dart';
import 'package:dio/dio.dart';
import 'package:flutter_test/flutter_test.dart';

class MemoryVault implements TokenVault {
  MemoryVault([this.pair]);
  Map<String, dynamic>? pair;
  Completer<void>? writeGate;
  @override
  Future<Map<String, dynamic>?> read() async => pair;
  @override
  Future<void> write(Map<String, dynamic> value) async {
    await writeGate?.future;
    pair = value;
  }

  @override
  Future<void> clear() async {
    pair = null;
  }
}

typedef HandleRequest = FutureOr<ResponseBody> Function(RequestOptions request);

class TestAdapter implements HttpClientAdapter {
  TestAdapter(this.handle);
  final HandleRequest handle;
  @override
  Future<ResponseBody> fetch(
    RequestOptions options,
    Stream<Uint8List>? requestStream,
    Future<void>? cancelFuture,
  ) async => handle(options);
  @override
  void close({bool force = false}) {}
}

ResponseBody reply(Map<String, dynamic> data, [int status = 200]) =>
    ResponseBody.fromString(
      jsonEncode({
        'status': status < 300 ? 'success' : 'error',
        'data': data,
        'message': status < 300 ? 'ok' : 'unauthorized',
        if (status >= 300) 'error': {'code': 'unauthorized'},
      }),
      status,
      headers: {
        Headers.contentTypeHeader: ['application/json'],
      },
    );
ClevoApi client(
  MemoryVault vault,
  HandleRequest request, [
  HandleRequest? auth,
]) => ClevoApi(
  AppConfig(apiBase: 'https://api.example.test/api/v1/'),
  vault,
  transport: Dio()..httpClientAdapter = TestAdapter(request),
  authTransport: Dio()..httpClientAdapter = TestAdapter(auth ?? request),
);
const originalPair = {
  'access_token': 'first-access',
  'refresh_token': 'first-refresh',
};
const nextPair = {
  'access_token': 'next-access',
  'refresh_token': 'next-refresh',
};
void main() {
  test(
    'parallel 401 requests rotate refresh exactly once and preserve operation intent',
    () async {
      final vault = MemoryVault({...originalPair}), gate = Completer<void>();
      var rotations = 0;
      final observed = <RequestOptions>[];
      final api = client(
        vault,
        (request) {
          observed.add(request);
          return reply(
            {},
            request.headers['Authorization'] == 'Bearer first-access'
                ? 401
                : 200,
          );
        },
        (request) async {
          rotations++;
          expect(request.data, {'refresh_token': 'first-refresh'});
          await gate.future;
          return reply({...nextPair});
        },
      );
      await api.restore();
      final first = api.request(
        'bookings',
        method: 'POST',
        body: {'station_id': 9},
        key: 'same-operation',
      );
      final second = api.request('notifications');
      await Future<void>.delayed(const Duration(milliseconds: 25));
      gate.complete();
      await Future.wait([first, second]);
      expect(rotations, 1);
      expect(vault.pair, nextPair);
      final bookings = observed.where((r) => r.path == 'bookings').toList();
      expect(bookings.length, 2);
      expect(bookings.last.data, bookings.first.data);
      expect(bookings.last.headers['Idempotency-Key'], 'same-operation');
      expect(bookings.last.headers['Authorization'], 'Bearer next-access');
    },
  );
  test('rejected refresh clears credentials and expires session', () async {
    final vault = MemoryVault({...originalPair});
    var expired = false;
    final api = client(vault, (_) => reply({}, 401), (_) => reply({}, 401));
    api.onExpired = () => expired = true;
    await api.restore();
    await expectLater(api.request('me'), throwsA(isA<ApiFailure>()));
    expect(vault.pair, isNull);
    expect(expired, isTrue);
  });
  test(
    'logout fences a pending refresh and cannot resurrect credentials',
    () async {
      final gate = Completer<void>(), started = Completer<void>();
      final vault = MemoryVault({...originalPair});
      final api = client(vault, (_) => reply({}, 401), (_) async {
        started.complete();
        await gate.future;
        return reply({...nextPair});
      });
      await api.restore();
      final pending = expectLater(
        api.request('me'),
        throwsA(isA<ApiFailure>()),
      );
      await started.future;
      await api.clear();
      gate.complete();
      await pending;
      expect(vault.pair, isNull);
    },
  );
  test('serialized secure writes cannot survive logout', () async {
    final vault = MemoryVault()..writeGate = Completer<void>();
    final api = client(vault, (_) => reply({...originalPair}));
    final pending = expectLater(
      api.authenticate('test', 'synthetic'),
      throwsA(isA<ApiFailure>()),
    );
    await Future<void>.delayed(const Duration(milliseconds: 15));
    final logout = api.clear();
    vault.writeGate!.complete();
    await logout;
    await pending;
    expect(vault.pair, isNull);
  });
  test('offline logout always erases credentials', () async {
    final vault = MemoryVault({...originalPair});
    final api = client(
      vault,
      (request) => throw DioException(
        requestOptions: request,
        type: DioExceptionType.connectionError,
      ),
    );
    await api.restore();
    await api.logout();
    expect(vault.pair, isNull);
  });
  test(
    'mutation transport failure is uncertain and never automatically retried',
    () async {
      var calls = 0;
      final api = client(MemoryVault(), (request) {
        calls++;
        throw DioException(
          requestOptions: request,
          type: DioExceptionType.receiveTimeout,
        );
      });
      await expectLater(
        api.request('bookings', method: 'POST', key: 'intent', body: {}),
        throwsA(
          isA<ApiFailure>().having((e) => e.uncertain, 'uncertain', true),
        ),
      );
      expect(calls, 1);
    },
  );
  test('API client refuses cross-origin paths and redirects', () async {
    var calls = 0;
    final api = client(MemoryVault(), (request) {
      calls++;
      expect(request.followRedirects, false);
      return reply({});
    });
    for (final path in [
      'https://evil.test/api',
      '//evil.test',
      '../me',
      '/me',
    ]) {
      await expectLater(api.request(path), throwsA(isA<ApiFailure>()));
    }
    expect(calls, 0);
    await api.request('stations');
    expect(calls, 1);
  });
  test(
    'public API calls do not attach an access token when explicitly anonymous',
    () async {
      final api = client(MemoryVault({...originalPair}), (request) {
        expect(request.headers.containsKey('Authorization'), false);
        return reply({});
      });
      await api.restore();
      await api.request(
        'auth/email/resend',
        method: 'POST',
        authenticated: false,
      );
    },
  );
}
