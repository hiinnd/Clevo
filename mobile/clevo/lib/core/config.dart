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
  factory AppConfig.environment() {
    const base = String.fromEnvironment(
      'CLEVO_API_URL',
      defaultValue: 'https://clevo.invalid/api/v1/',
    );
    if (kReleaseMode) validateDistributionEndpoint(base);
    return AppConfig(
      apiBase: base,
      localDevelopment:
          kDebugMode && const bool.fromEnvironment('CLEVO_LOCAL_HTTP'),
      firebaseEnabled: const bool.fromEnvironment('CLEVO_FIREBASE'),
      mapsEnabled: const bool.fromEnvironment('CLEVO_MAPS'),
    );
  }
}

void validateDistributionEndpoint(String base) {
  final uri = Uri.tryParse(base);
  final host = uri?.host.toLowerCase() ?? '';
  if (uri == null ||
      uri.scheme != 'https' ||
      host.isEmpty ||
      host == 'localhost' ||
      host.endsWith('.localhost') ||
      host.endsWith('.invalid') ||
      host.endsWith('.test') ||
      host.endsWith('.example') ||
      host == 'example.com' ||
      host.endsWith('.example.com') ||
      host.contains(':') ||
      RegExp(r'^\d+(\.\d+){3}$').hasMatch(host) ||
      uri.userInfo.isNotEmpty ||
      uri.hasQuery ||
      uri.hasFragment ||
      !uri.path.endsWith('/api/v1/')) {
    throw ArgumentError('Distribution requires an approved HTTPS API domain');
  }
}
