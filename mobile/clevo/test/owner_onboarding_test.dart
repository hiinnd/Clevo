import 'dart:convert';
import 'package:clevo/app.dart';
import 'package:clevo/core/state.dart';
import 'package:clevo/features/owner_onboarding.dart';
import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image_picker/image_picker.dart';
import 'package:material_ui/material_ui.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'api_test.dart' show MemoryVault, client, reply;

void main() {
  for (final language in ['en', 'ar']) {
    for (final theme in [ThemeMode.light, ThemeMode.dark]) {
      testWidgets(
        'Owner application $language ${theme.name} uses existing multipart API without granting access',
        (tester) async {
          SharedPreferences.setMockInitialValues({
            'language': language,
            'theme': theme.index,
          });
          final bytes = base64Decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a9a0AAAAASUVORK5CYII=',
          );
          var registrations = 0;
          final vault = MemoryVault();
          final api = client(vault, (request) {
            expect(request.path, 'auth/register');
            expect(request.method, 'POST');
            expect(request.headers['Authorization'], isNull);
            final form = request.data as FormData;
            expect(Map.fromEntries(form.fields)['role'], 'owner');
            expect(form.files.single.key, 'verification_file');
            registrations++;
            return reply({}, 201);
          });
          final container = ProviderContainer(
            overrides: [
              apiProvider.overrideWithValue(api),
              ownerDocumentPickerProvider.overrideWithValue(
                () async => XFile.fromData(bytes, name: 'document.png'),
              ),
            ],
          );
          try {
            await tester.pumpWidget(
              UncontrolledProviderScope(
                container: container,
                child: const ClevoApp(),
              ),
            );
            await tester.pumpAndSettle();
            container.read(routerProvider).go('/register-owner');
            await tester.pumpAndSettle();
            for (final field in {
              'full_name': 'Owner Test',
              'email': 'owner@example.test',
              'phone': '0791234567',
              'station_name': 'Station Test',
              'password': 'SyntheticPassword!1',
            }.entries) {
              await tester.enterText(
                find.byKey(Key('owner-${field.key}')),
                field.value,
              );
            }
            final picker = find.byType(OutlinedButton).first;
            await tester.ensureVisible(picker);
            await tester.pumpAndSettle();
            await tester.tap(picker);
            await tester.pumpAndSettle();
            final submit = find.byType(FilledButton).last;
            await tester.ensureVisible(submit);
            await tester.pumpAndSettle();
            await tester.tap(submit);
            await tester.pumpAndSettle();
            expect(registrations, 1);
            expect(
              find.byKey(const Key('owner-application-submitted')),
              findsOneWidget,
            );
            expect(vault.pair, isNull);
            expect(container.read(sessionProvider).asData?.value, isNull);
            expect(tester.takeException(), isNull);
          } finally {
            await tester.pumpWidget(const SizedBox.shrink());
            container.dispose();
            api.dio.close();
            api.authDio.close();
          }
        },
      );
    }
  }
}
