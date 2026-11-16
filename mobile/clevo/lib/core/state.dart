import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'api.dart';
import 'config.dart';

final configProvider = Provider<AppConfig>((ref) => AppConfig.environment());
final vaultProvider = Provider<TokenVault>((ref) => const SecureTokenVault());
final apiProvider = Provider<ClevoApi>((ref) {
  final api = ClevoApi(ref.watch(configProvider), ref.watch(vaultProvider));
  api.onExpired = () => ref.read(sessionProvider.notifier).expire();
  ref.onDispose(() {
    api.dio.close(force: true);
    api.authDio.close(force: true);
  });
  return api;
});

class ClevoUser {
  ClevoUser(this.id, this.name, this.role, this.raw);
  factory ClevoUser.fromJson(Map<String, dynamic> value) => ClevoUser(
    int.parse('${value['id']}'),
    '${value['name']}',
    '${value['role']}',
    value,
  );
  final int id;
  final String name, role;
  final Map<String, dynamic> raw;
  bool get mobileRole => ['customer', 'owner', 'driver'].contains(role);
  // Current PHP schema authorizes exactly one role. Never invent local roles.
  List<String> get authorizedRoles => [role];
}

final sessionProvider = AsyncNotifierProvider<SessionController, ClevoUser?>(
  SessionController.new,
);

class SessionController extends AsyncNotifier<ClevoUser?> {
  int _epoch = 0;
  @override
  Future<ClevoUser?> build() async {
    final api = ref.read(apiProvider);
    if (!await api.restore()) return null;
    try {
      return await _profile();
    } on ApiFailure catch (error) {
      if (error.status == 401) {
        await api.clear();
        return null;
      }
      rethrow;
    }
  }

  Future<ClevoUser> _profile() async {
    final data = await ref.read(apiProvider).request('me');
    return ClevoUser.fromJson(Map<String, dynamic>.from(data['user'] as Map));
  }

  Future<void> login(
    String login,
    String password, {
    bool taskCode = false,
  }) async {
    final epoch = ++_epoch;
    try {
      await ref
          .read(apiProvider)
          .authenticate(login, password, taskCode: taskCode);
      final user = await _profile();
      if (epoch != _epoch || !ref.mounted) {
        throw const ApiFailure('session_changed');
      }
      state = AsyncData(user);
    } catch (_) {
      rethrow;
    }
  }

  Future<void> reloadProfile() async {
    final epoch = _epoch;
    final user = await _profile();
    if (epoch == _epoch && ref.mounted) state = AsyncData(user);
  }

  void expire() {
    _epoch++;
    state = const AsyncData(null);
  }

  Future<void> logout() async {
    _epoch++;
    state = const AsyncData(null);
    await ref.read(apiProvider).logout();
  }
}

class Preferences {
  const Preferences({
    this.locale = const Locale('ar'),
    this.theme = ThemeMode.system,
  });
  final Locale locale;
  final ThemeMode theme;
}

final preferencesProvider =
    NotifierProvider<PreferencesController, Preferences>(
      PreferencesController.new,
    );

class PreferencesController extends Notifier<Preferences> {
  @override
  Preferences build() {
    _restore();
    return const Preferences();
  }

  Future<void> _restore() async {
    final store = await SharedPreferences.getInstance();
    if (!ref.mounted) return;
    state = Preferences(
      locale: Locale(store.getString('language') == 'en' ? 'en' : 'ar'),
      theme:
          ThemeMode.values[(store.getInt('theme') ?? ThemeMode.system.index)
              .clamp(0, 2)],
    );
  }

  Future<void> language(String language) async {
    state = Preferences(locale: Locale(language), theme: state.theme);
    await (await SharedPreferences.getInstance()).setString(
      'language',
      language,
    );
  }

  Future<void> theme(ThemeMode mode) async {
    state = Preferences(locale: state.locale, theme: mode);
    await (await SharedPreferences.getInstance()).setInt('theme', mode.index);
  }
}

String tr(BuildContext context, String ar, String en) =>
    Localizations.localeOf(context).languageCode == 'ar' ? ar : en;
