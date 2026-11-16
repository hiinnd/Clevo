import 'dart:async';
import 'dart:convert';
import 'dart:math';
import 'package:dio/dio.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'config.dart';

abstract interface class TokenVault {
  Future<Map<String, dynamic>?> read();
  Future<void> write(Map<String, dynamic> pair);
  Future<void> clear();
}

class SecureTokenVault implements TokenVault {
  const SecureTokenVault();
  static const _storage = FlutterSecureStorage();
  static const _key = 'clevo.session.v1';
  @override
  Future<Map<String, dynamic>?> read() async {
    final raw = await _storage.read(key: _key);
    if (raw == null) return null;
    try {
      return Map<String, dynamic>.from(jsonDecode(raw) as Map);
    } catch (_) {
      await clear();
      return null;
    }
  }

  @override
  Future<void> write(Map<String, dynamic> pair) =>
      _storage.write(key: _key, value: jsonEncode(pair));
  @override
  Future<void> clear() => _storage.delete(key: _key);
}

class ApiFailure implements Exception {
  const ApiFailure(this.code, {this.status, this.uncertain = false});
  final String code;
  final int? status;
  final bool uncertain;
  @override
  String toString() => 'ApiFailure($code)'; // Never stringify responses or credentials.
}

class PageResult {
  const PageResult(this.rows, this.page, this.totalPages);
  final List<Map<String, dynamic>> rows;
  final int page, totalPages;
}

String operationKey() {
  final random = Random.secure();
  return List.generate(
    24,
    (_) => random.nextInt(256).toRadixString(16).padLeft(2, '0'),
  ).join();
}

class ClevoApi {
  ClevoApi(this.config, this.vault, {Dio? transport, Dio? authTransport})
    : dio = transport ?? Dio(),
      authDio = authTransport ?? Dio() {
    for (final client in [dio, authDio]) {
      client.options = BaseOptions(
        baseUrl: config.apiBase,
        connectTimeout: const Duration(seconds: 12),
        receiveTimeout: const Duration(seconds: 25),
        sendTimeout: const Duration(seconds: 25),
        followRedirects: false,
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/json',
        },
      );
    }
  }
  final AppConfig config;
  final TokenVault vault;
  final Dio dio, authDio;
  Map<String, dynamic>? _tokens;
  Future<void>? _refreshing;
  int _generation = 0;
  Future<void> _vaultQueue = Future.value();
  Future<void> _serialize(Future<void> Function() action) {
    final result = _vaultQueue.then((_) => action());
    _vaultQueue = result.catchError((Object _) {});
    return result;
  }

  void Function()? onExpired;
  Future<bool> restore() async {
    _tokens = await vault.read();
    return _tokens != null;
  }

  Future<void> authenticate(
    String login,
    String password, {
    bool taskCode = false,
  }) async {
    final generation = _generation;
    final result = await request(
      taskCode ? 'auth/driver-exchanges' : 'auth/login',
      method: 'POST',
      body: {'login': login, taskCode ? 'code' : 'password': password},
      authenticated: false,
    );
    await _save(result, generation);
  }

  Future<void> _save(Map<String, dynamic> pair, int generation) async {
    if (pair['access_token'] is! String || pair['refresh_token'] is! String) {
      throw const ApiFailure('invalid_session');
    }
    await _serialize(() async {
      if (generation != _generation) throw const ApiFailure('session_changed');
      await vault.write(pair);
      if (generation != _generation) throw const ApiFailure('session_changed');
      _tokens = pair;
    });
  }

  Future<void> clear() async {
    _generation++;
    _tokens = null;
    await _serialize(vault.clear);
  }

  Future<void> _refresh() async {
    if (_refreshing != null) return _refreshing;
    final current = _generation;
    final refresh = _tokens?['refresh_token'];
    if (refresh is! String) throw const ApiFailure('unauthorized', status: 401);
    final completer = Completer<void>();
    _refreshing = completer.future;
    try {
      final response = await authDio.post<dynamic>(
        'auth/refresh',
        data: {'refresh_token': refresh},
      );
      final data = _decode(response.data);
      if (current != _generation) throw const ApiFailure('session_changed');
      await _save(data, current);
      completer.complete();
    } catch (error) {
      final failure = _failure(error);
      if (current == _generation && [401, 403, 422].contains(failure.status)) {
        await clear();
        onExpired?.call();
      }
      completer.completeError(failure);
    } finally {
      _refreshing = null;
    }
    return completer.future;
  }

  Map<String, dynamic> _decode(dynamic raw) {
    if (raw is! Map || raw['status'] != 'success') {
      throw ApiFailure(
        raw is Map
            ? (raw['error']?['code'] ?? raw['message'] ?? 'request_failed')
                  .toString()
            : 'invalid_response',
      );
    }
    final data = raw['data'];
    return data is Map ? Map<String, dynamic>.from(data) : <String, dynamic>{};
  }

  ApiFailure _failure(Object error) {
    if (error is ApiFailure) return error;
    if (error is DioException) {
      final raw = error.response?.data;
      final code = raw is Map
          ? (raw['message'] ?? raw['error']?['code'])?.toString()
          : null;
      return ApiFailure(
        code ?? 'connection_unavailable',
        status: error.response?.statusCode,
        uncertain: error.response == null,
      );
    }
    return const ApiFailure('request_failed');
  }

  Future<Response<dynamic>> send(
    String path, {
    String method = 'GET',
    dynamic body,
    Map<String, dynamic>? query,
    String? key,
    bool authenticated = true,
  }) async {
    if (path.startsWith('/') ||
        path.contains('..') ||
        Uri.parse(path).hasScheme) {
      throw const ApiFailure('invalid_resource');
    }
    final generation = _generation, usedToken = _tokens?['access_token'];
    Future<Response<dynamic>> execute() => dio.request<dynamic>(
      path,
      data: body is FormData ? body.clone() : body,
      queryParameters: query,
      options: Options(
        method: method,
        headers: {
          if (authenticated && _tokens != null)
            'Authorization': 'Bearer ${_tokens!['access_token']}',
          'Idempotency-Key': ?key,
        },
      ),
    );
    try {
      final response = await execute();
      if (authenticated && generation != _generation) {
        throw const ApiFailure('session_changed');
      }
      return response;
    } on DioException catch (error) {
      if (authenticated &&
          error.response?.statusCode == 401 &&
          _tokens != null &&
          generation == _generation) {
        if (usedToken == _tokens?['access_token']) await _refresh();
        if (generation != _generation) {
          throw const ApiFailure('session_changed');
        }
        try {
          final response = await execute();
          if (generation != _generation) {
            throw const ApiFailure('session_changed');
          }
          return response;
        } catch (next) {
          throw _failure(next);
        }
      }
      throw _failure(error);
    }
  }

  Future<Map<String, dynamic>> request(
    String path, {
    String method = 'GET',
    dynamic body,
    Map<String, dynamic>? query,
    String? key,
    bool authenticated = true,
  }) async {
    try {
      return _decode(
        (await send(
          path,
          method: method,
          body: body,
          query: query,
          key: key,
          authenticated: authenticated,
        )).data,
      );
    } catch (error) {
      throw _failure(error);
    }
  }

  Future<PageResult> list(
    String path,
    String field, {
    int page = 1,
    Map<String, dynamic>? query,
  }) async {
    final response = await send(
      path,
      query: {...?query, 'page': page, 'per_page': 20},
    );
    final data = _decode(response.data),
        meta = response.data['meta']?['pagination'];
    final rows = (data[field] as List? ?? [])
        .whereType<Map>()
        .map((v) => Map<String, dynamic>.from(v))
        .toList();
    int number(dynamic value, int fallback) =>
        int.tryParse('$value') ?? fallback;
    return PageResult(rows, page, number(meta?['total_pages'], 1));
  }

  Future<void> logout() async {
    final access = _tokens?['access_token'];
    await clear();
    try {
      if (access != null) {
        await authDio.post<dynamic>(
          'auth/logout',
          options: Options(headers: {'Authorization': 'Bearer $access'}),
        );
      }
    } catch (_) {
      /* Local credentials remain erased even when offline; server expiry/revocation still applies. */
    }
  }
}
