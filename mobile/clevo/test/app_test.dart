import 'package:clevo/app.dart';
import 'package:clevo/core/state.dart';
import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'api_test.dart' show MemoryVault, client, reply, originalPair;

void main() {
  for (final role in ['customer', 'owner', 'driver']) {
    testWidgets('verified $role profile opens the authorized dashboard', (
      tester,
    ) async {
      SharedPreferences.setMockInitialValues({'language': 'en'});
      final api = client(
        MemoryVault({...originalPair}),
        (request) => reply({
          'user': {'id': 7, 'name': 'Test account', 'role': role},
        }),
      );
      final container = ProviderContainer(
        overrides: [apiProvider.overrideWithValue(api)],
      );
      await tester.pumpWidget(
        UncontrolledProviderScope(
          container: container,
          child: const ClevoApp(),
        ),
      );
      await tester.pumpAndSettle();
      expect(find.text('Welcome, Test account'), findsOneWidget);
      final labels = {
        'customer': 'Explore stations',
        'owner': 'Stations & services',
        'driver': 'Assigned jobs',
      };
      expect(find.text(labels[role]!), findsOneWidget);
      final router = container.read(routerProvider);
      for (final other in [
        'customer',
        'owner',
        'driver',
      ].where((r) => r != role)) {
        router.go('/$other');
        await tester.pumpAndSettle();
        expect(router.routeInformationProvider.value.uri.path, '/$role');
      }
      await tester.pumpWidget(const SizedBox.shrink());
      container.dispose();
      api.dio.close();
      api.authDio.close();
      expect(tester.takeException(), isNull);
    });
  }
  testWidgets('Arabic RTL and dark mode survive settings restoration', (
    tester,
  ) async {
    SharedPreferences.setMockInitialValues({
      'language': 'ar',
      'theme': ThemeMode.dark.index,
    });
    final api = client(MemoryVault(), (_) => reply({}));
    final container = ProviderContainer(
      overrides: [apiProvider.overrideWithValue(api)],
    );
    await tester.pumpWidget(
      UncontrolledProviderScope(container: container, child: const ClevoApp()),
    );
    await tester.pumpAndSettle();
    expect(find.text('عناية تستحقها سيارتك'), findsOneWidget);
    final context = tester.element(find.text('عناية تستحقها سيارتك'));
    expect(Directionality.of(context), TextDirection.rtl);
    expect(Theme.of(context).brightness, Brightness.dark);
    expect(
      container.read(routerProvider).routeInformationProvider.value.uri.path,
      '/login',
    );
    await tester.pumpWidget(const SizedBox.shrink());
    container.dispose();
  });
  testWidgets('admin account remains outside mobile role dashboards', (
    tester,
  ) async {
    SharedPreferences.setMockInitialValues({'language': 'en'});
    final api = client(
      MemoryVault({...originalPair}),
      (_) => reply({
        'user': {'id': 3, 'name': 'Admin', 'role': 'admin'},
      }),
    );
    final container = ProviderContainer(
      overrides: [apiProvider.overrideWithValue(api)],
    );
    await tester.pumpWidget(
      UncontrolledProviderScope(container: container, child: const ClevoApp()),
    );
    await tester.pumpAndSettle();
    expect(
      find.text(
        'System administration is available through the web dashboard.',
      ),
      findsOneWidget,
    );
    expect(find.text('Stations & services'), findsNothing);
    await tester.pumpWidget(const SizedBox.shrink());
    container.dispose();
  });
}
