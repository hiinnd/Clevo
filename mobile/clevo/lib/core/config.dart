import 'package:flutter/foundation.dart';

class AppConfig {
  AppConfig({
    required this.apiBase,
    this.localDevelopment = false,
    this.firebaseEnabled = false,
    this.mapsEnabled = false,
  }) {
    final uri = Uri.tryParse(apiBase);
    final local =
        uri != null &&
        ['10.0.2.2', '127.0.0.1', 'localhost'].contains(uri.host);
    if (uri == null ||
        uri.host.isEmpty ||
        uri.userInfo.isNotEmpty ||
        uri.hasQuery ||
        uri.hasFragment ||
        !uri.path.endsWith('/api/v1/') ||
        (uri.scheme != 'https' &&
            !(localDevelopment && local && uri.scheme == 'http'))) {
      throw ArgumentError('Configure a trusted HTTPS API URL ending /api/v1/');
    }
  }
  final String apiBase;
  final bool localDevelopment, firebaseEnabled, mapsEnabled;
  factory AppConfig.environment() => AppConfig(
    apiBase: const String.fromEnvironment(
      'CLEVO_API_URL',
      defaultValue: 'https://clevo.invalid/api/v1/',
    ),
    localDevelopment:
        kDebugMode && const bool.fromEnvironment('CLEVO_LOCAL_HTTP'),
    firebaseEnabled: const bool.fromEnvironment('CLEVO_FIREBASE'),
    mapsEnabled: const bool.fromEnvironment('CLEVO_MAPS'),
  );
}
