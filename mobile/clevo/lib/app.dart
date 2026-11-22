import 'dart:async';
import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'core/state.dart';
import 'core/navigation.dart';
import 'core/notifications.dart';
import 'core/router_events.dart';
import 'features/auth.dart';
import 'features/account.dart';
import 'features/privacy.dart';
import 'features/bookings.dart';
import 'features/customer.dart';
import 'features/dashboards.dart';
import 'features/driver.dart';
import 'features/history.dart';
import 'features/owner.dart';
import 'features/payments.dart';
import 'features/tracking.dart';
import 'ui/common.dart';

class _RouterRefresh extends ChangeNotifier {
  void refresh() => notifyListeners();
}

final _messengerKey = GlobalKey<ScaffoldMessengerState>();

final routerProvider = Provider<GoRouter>((ref) {
  final refresh = _RouterRefresh();
  ref.listen(sessionProvider, (_, next) {
    refresh.refresh();
    if (next.asData?.value?.mobileRole == true) {
      unawaited(
        ref
            .read(messagingProvider)
            .resumeAuthorized()
            .catchError((Object _) {}),
      );
    }
  });
  String? pending;
  final router = GoRouter(
    initialLocation: '/startup',
    refreshListenable: refresh,
    observers: [clevoRouteObserver],
    redirect: (context, state) {
      final session = ref.read(sessionProvider), path = state.uri.path;
      if (session.isLoading || session.hasError) {
        if (path != '/startup') pending = publicRoute(path) ? null : path;
        return path == '/startup' ? null : '/startup';
      }
      final user = session.asData?.value;
      if (user == null) {
        return publicRoute(path) && path != '/startup' ? null : '/login';
      }
      final home = user.mobileRole ? '/${user.role}' : '/unsupported';
      if (['/startup', '/login', '/register'].contains(path)) {
        final target = pending;
        pending = null;
        return target != null && roleAllows(user.role, target) ? target : home;
      }
      if (!publicRoute(path) && !roleAllows(user.role, path)) return home;
      return null;
    },
    errorBuilder: (context, state) => ClevoPage(
      title: 'CLEVO',
      child: Center(
        child: TextButton(
          onPressed: () => context.go('/startup'),
          child: Text(tr(context, 'العودة للرئيسية', 'Return home')),
        ),
      ),
    ),
    routes: [
      GoRoute(path: '/startup', builder: (_, state) => const StartupScreen()),
      GoRoute(path: '/login', builder: (_, state) => const LoginScreen()),
      GoRoute(path: '/register', builder: (_, state) => const RegisterScreen()),
      GoRoute(path: '/recovery', builder: (_, state) => const RecoveryScreen()),
      GoRoute(
        path: '/unsupported',
        builder: (_, state) => const UnsupportedRoleScreen(),
      ),
      for (final role in ['customer', 'owner', 'driver'])
        GoRoute(
          path: '/$role',
          builder: (_, state) => Dashboard(role: role),
        ),
      GoRoute(path: '/browse', builder: (_, state) => const StationsScreen()),
      GoRoute(path: '/stations', builder: (_, state) => const StationsScreen()),
      GoRoute(
        path: '/station/:id',
        builder: (_, state) =>
            StationScreen(int.tryParse(state.pathParameters['id']!) ?? 0),
      ),
      GoRoute(
        path: '/station/:id/reviews',
        builder: (_, state) => HistoryScreen(
          kind: 'station_reviews',
          id: state.pathParameters['id'],
        ),
      ),
      GoRoute(path: '/bookings', builder: (_, state) => const BookingsScreen()),
      GoRoute(
        path: '/bookings/history',
        builder: (_, state) => const BookingsScreen(history: true),
      ),
      GoRoute(
        path: '/booking/:id',
        builder: (_, state) => BookingScreen(id: state.pathParameters['id']!),
      ),
      GoRoute(
        path: '/booking/:id/events',
        builder: (_, state) =>
            HistoryScreen(kind: 'events', id: state.pathParameters['id']),
      ),
      GoRoute(
        path: '/booking/:id/tracking',
        redirect: (context, state) =>
            ref.read(sessionProvider).asData?.value?.role == 'customer'
            ? null
            : '/owner',
        builder: (_, state) => TrackingScreen(id: state.pathParameters['id']!),
      ),
      GoRoute(
        path: '/booking/:id/payment',
        redirect: (context, state) =>
            ref.read(sessionProvider).asData?.value?.role == 'customer'
            ? null
            : '/owner',
        builder: (_, state) =>
            BookingPaymentScreen(id: state.pathParameters['id']!),
      ),
      GoRoute(path: '/wallet', builder: (_, state) => const WalletScreen()),
      GoRoute(
        path: '/wallet/transactions',
        builder: (_, state) => const HistoryScreen(kind: 'wallet'),
      ),
      GoRoute(
        path: '/payment-orders',
        builder: (_, state) => const HistoryScreen(kind: 'orders'),
      ),
      GoRoute(
        path: '/payment-order/:id',
        builder: (_, state) =>
            PaymentOrderScreen(id: state.pathParameters['id']!),
      ),
      GoRoute(path: '/plans', builder: (_, state) => const PlansScreen()),
      GoRoute(
        path: '/subscriptions',
        builder: (_, state) => const HistoryScreen(kind: 'subscriptions'),
      ),
      GoRoute(
        path: '/receipts',
        builder: (_, state) => const HistoryScreen(kind: 'receipts'),
      ),
      GoRoute(
        path: '/refunds',
        builder: (_, state) => const HistoryScreen(kind: 'refunds'),
      ),
      GoRoute(
        path: '/reviews',
        builder: (_, state) => const HistoryScreen(kind: 'reviews'),
      ),
      GoRoute(
        path: '/owner/reviews',
        builder: (_, state) => const HistoryScreen(kind: 'owner_reviews'),
      ),
      GoRoute(
        path: '/owner/statement',
        builder: (_, state) => const HistoryScreen(kind: 'statement'),
      ),
      GoRoute(
        path: '/owner/stations',
        builder: (_, state) => const OwnerStationsScreen(),
      ),
      GoRoute(
        path: '/owner/station/:id/services',
        builder: (_, state) =>
            OwnerServicesScreen(id: state.pathParameters['id']!),
      ),
      GoRoute(
        path: '/owner/station/:id/availability',
        builder: (_, state) =>
            AvailabilityScreen(id: state.pathParameters['id']!),
      ),
      GoRoute(
        path: '/owner/drivers',
        builder: (_, state) => const OwnerDriversScreen(),
      ),
      GoRoute(
        path: '/owner/earnings',
        builder: (_, state) => const EarningsScreen(),
      ),
      GoRoute(
        path: '/driver/jobs',
        builder: (_, state) => const DriverJobsScreen(),
      ),
      GoRoute(
        path: '/driver/history',
        builder: (_, state) => const DriverJobsScreen(history: true),
      ),
      GoRoute(
        path: '/driver/job/:id',
        builder: (_, state) => DriverJobScreen(id: state.pathParameters['id']!),
      ),
      GoRoute(
        path: '/driver/job/:id/tracking',
        builder: (_, state) =>
            TrackingScreen(id: state.pathParameters['id']!, driver: true),
      ),
      GoRoute(path: '/profile', builder: (_, state) => const ProfileScreen()),
      GoRoute(path: '/privacy', builder: (_, state) => const PrivacyScreen()),
      GoRoute(
        path: '/addresses',
        builder: (_, state) => const AddressesScreen(),
      ),
      GoRoute(
        path: '/notifications',
        builder: (_, state) => const NotificationsScreen(),
      ),
      GoRoute(
        path: '/notification-preferences',
        builder: (_, state) => const NotificationPreferencesScreen(),
      ),
      GoRoute(path: '/support', builder: (_, state) => const SupportScreen()),
      GoRoute(
        path: '/support/:id',
        builder: (_, state) =>
            SupportThreadScreen(id: state.pathParameters['id']!),
      ),
    ],
  );
  final messaging = ref.read(messagingProvider);
  messaging.onForeground = () {
    if (ref.read(sessionProvider).asData?.value == null) return;
    final context = _messengerKey.currentContext;
    if (context == null) return;
    _messengerKey.currentState?.showSnackBar(
      SnackBar(
        content: Text(
          tr(
            context,
            'لديك تحديث جديد في CLEVO',
            'A new CLEVO update is available',
          ),
        ),
        action: SnackBarAction(
          label: tr(context, 'عرض', 'View'),
          onPressed: () => router.push('/notifications'),
        ),
      ),
    );
  };
  messaging.onOpen = (data) {
    final target = notificationTarget(
      data,
      ref.read(sessionProvider).asData?.value?.role,
    );
    if (target != null) router.push(target);
  };
  ref.onDispose(() {
    messaging.onOpen = null;
    messaging.onForeground = null;
    router.dispose();
    refresh.dispose();
  });
  return router;
});

class ClevoApp extends ConsumerWidget {
  const ClevoApp({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final preferences = ref.watch(preferencesProvider);
    return MaterialApp.router(
      scaffoldMessengerKey: _messengerKey,
      title: 'CLEVO',
      debugShowCheckedModeBanner: false,
      locale: preferences.locale,
      supportedLocales: const [Locale('ar'), Locale('en')],
      localizationsDelegates: GlobalMaterialLocalizations.delegates,
      theme: clevoTheme(Brightness.light),
      darkTheme: clevoTheme(Brightness.dark),
      themeMode: preferences.theme,
      routerConfig: ref.watch(routerProvider),
    );
  }
}

ThemeData clevoTheme(Brightness brightness) {
  final colors = ColorScheme.fromSeed(
    seedColor: const Color(0xff3aa2d2),
    brightness: brightness,
  );
  return ThemeData(
    useMaterial3: true,
    colorScheme: colors,
    scaffoldBackgroundColor: brightness == Brightness.dark
        ? const Color(0xff101a24)
        : const Color(0xfff5f9fc),
    cardTheme: CardThemeData(
      elevation: 0,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(20)),
    ),
    inputDecorationTheme: InputDecorationTheme(
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(14)),
      filled: true,
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(minimumSize: const Size(48, 48)),
    ),
    appBarTheme: const AppBarTheme(centerTitle: false),
  );
}

class StartupScreen extends ConsumerWidget {
  const StartupScreen({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final session = ref.watch(sessionProvider);
    return ClevoPage(
      title: 'CLEVO',
      child: session.hasError
          ? ErrorPanel(session.error!, () => ref.invalidate(sessionProvider))
          : const Center(child: CircularProgressIndicator()),
    );
  }
}
