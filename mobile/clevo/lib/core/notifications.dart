import 'dart:async';
import 'dart:io';
import 'package:firebase_core/firebase_core.dart';
import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'api.dart';
import 'state.dart';

/// Treat notification data as untrusted hints. The destination API reauthorizes all objects.
String? notificationTarget(Map<String, dynamic> data, String? role) {
  if (!['customer', 'owner', 'driver'].contains(role)) return null;
  final screen = data['screen'], id = '${data['entity_id'] ?? ''}';
  final validId = RegExp(r'^[1-9][0-9]{0,9}$').hasMatch(id);
  return switch (screen) {
    'booking' when role == 'customer' && validId => '/booking/$id',
    'owner_booking' when role == 'owner' && validId => '/booking/$id',
    'driver_job' when role == 'driver' && validId => '/driver/job/$id',
    'support' when validId && role != 'driver' => '/support/$id',
    'subscriptions' when role == 'customer' => '/subscriptions',
    'notifications' => '/notifications',
    _ => null,
  };
}

final messagingProvider = Provider<MobileMessaging>((ref) {
  final service = MobileMessaging(
    ref.read(apiProvider),
    enabled: ref.read(configProvider).firebaseEnabled,
  );
  ref.onDispose(service.dispose);
  return service;
});

class MobileMessaging {
  MobileMessaging(this.api, {required this.enabled});
  final ClevoApi api;
  final bool enabled;
  String? _device;
  int _generation = 0;
  bool _initialized = false;
  StreamSubscription<String>? _refresh;
  StreamSubscription<RemoteMessage>? _opened;
  StreamSubscription<RemoteMessage>? _foreground;
  void Function(Map<String, dynamic>)? onOpen;
  void Function()? onForeground;
  Future<void> initialize() async {
    if (!enabled || _initialized) return;
    await Firebase.initializeApp();
    await FirebaseMessaging.instance.setAutoInitEnabled(false);
    _opened = FirebaseMessaging.onMessageOpenedApp.listen(
      (message) => onOpen?.call(message.data),
    );
    _foreground = FirebaseMessaging.onMessage.listen(
      (_) => onForeground?.call(),
    );
    final initial = await FirebaseMessaging.instance.getInitialMessage();
    if (initial != null) onOpen?.call(initial.data);
    _initialized = true;
  }

  Future<void> resumeAuthorized() async {
    if (!enabled) return;
    await initialize();
    final permission = await FirebaseMessaging.instance
        .getNotificationSettings();
    if (![
      AuthorizationStatus.authorized,
      AuthorizationStatus.provisional,
    ].contains(permission.authorizationStatus)) {
      return;
    }
    final data = await api.request('me/notification-preferences');
    final preferences = data['preferences'] as Map?;
    if ('${preferences?['PushEnabled']}' == '1') await enable();
  }

  Future<void> enable() async {
    if (!enabled) throw const ApiFailure('messaging_not_configured');
    await initialize();
    final generation = _generation;
    final permission = await FirebaseMessaging.instance.requestPermission();
    if (![
      AuthorizationStatus.authorized,
      AuthorizationStatus.provisional,
    ].contains(permission.authorizationStatus)) {
      throw const ApiFailure('notification_permission_denied');
    }
    if (Platform.isIOS &&
        await FirebaseMessaging.instance.getAPNSToken() == null) {
      throw const ApiFailure('apns_not_ready');
    }
    await FirebaseMessaging.instance.setAutoInitEnabled(true);
    // PHP currently uses the FCM HTTP v1 registration-token contract, not FID direct-send.
    // ignore: deprecated_member_use
    final token = await FirebaseMessaging.instance.getToken();
    if (token == null) throw const ApiFailure('messaging_not_ready');
    await _register(token, generation);
    await _refresh?.cancel();
    // ignore: deprecated_member_use
    _refresh = FirebaseMessaging.instance.onTokenRefresh.listen((token) async {
      try {
        await _register(token, generation);
      } catch (_) {
        /* No secrets or provider payloads in logs; user can retry registration. */
      }
    });
  }

  Future<void> _register(String token, int generation) async {
    if (generation != _generation) return;
    final data = await api.request(
      'me/notification-devices',
      method: 'POST',
      key: operationKey(),
      body: {'token': token, 'platform': Platform.isIOS ? 'ios' : 'android'},
    );
    if (generation == _generation) _device = data['device_id'] as String?;
  }

  Future<void> detach() async {
    _generation++;
    await _refresh?.cancel();
    _refresh = null;
    final device = _device;
    _device = null;
    try {
      if (device != null) {
        await api.request(
          'me/notification-devices/revoke',
          method: 'POST',
          key: operationKey(),
          body: {'device_id': device},
        );
      }
    } catch (_) {
      /* PHP logout revokes session-bound devices as well. */
    }
    if (_initialized) {
      try {
        // ignore: deprecated_member_use
        await FirebaseMessaging.instance.deleteToken();
        await FirebaseMessaging.instance.setAutoInitEnabled(false);
      } catch (_) {}
    }
  }

  void dispose() {
    _generation++;
    _refresh?.cancel();
    _opened?.cancel();
    _foreground?.cancel();
  }
}
