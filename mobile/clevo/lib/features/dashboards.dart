import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../core/state.dart';
import '../ui/common.dart';

class Dashboard extends ConsumerWidget {
  const Dashboard({super.key, required this.role});
  final String role;
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(sessionProvider).asData?.value;
    final tiles = switch (role) {
      'owner' => <(IconData, String, String, String)>[
        (
          Icons.store_outlined,
          'محطاتي وخدماتي',
          'Stations & services',
          '/owner/stations',
        ),
        (
          Icons.receipt_long,
          'الحجوزات والطلبات',
          'Bookings & orders',
          '/bookings',
        ),
        (
          Icons.local_shipping_outlined,
          'إدارة السائقين',
          'Manage drivers',
          '/owner/drivers',
        ),
        (
          Icons.account_balance_outlined,
          'الأرباح والعمولات',
          'Earnings & commission',
          '/owner/earnings',
        ),
        (Icons.star_outline, 'التقييمات', 'Reviews', '/owner/reviews'),
        (
          Icons.workspace_premium_outlined,
          'استخدام الباقات',
          'Subscription usage',
          '/owner/subscriptions',
        ),
        (Icons.support_agent, 'الدعم', 'Support', '/support'),
      ],
      'driver' => <(IconData, String, String, String)>[
        (
          Icons.route_outlined,
          'المهام المسندة',
          'Assigned jobs',
          '/driver/jobs',
        ),
        (Icons.history, 'سجل المهام', 'Job history', '/driver/history'),
        (
          Icons.notifications_outlined,
          'الإشعارات',
          'Notifications',
          '/notifications',
        ),
        (Icons.person_outline, 'الملف الشخصي', 'Profile', '/profile'),
      ],
      _ => <(IconData, String, String, String)>[
        (Icons.search, 'اكتشف المحطات', 'Explore stations', '/stations'),
        (Icons.calendar_month_outlined, 'حجوزاتي', 'My bookings', '/bookings'),
        (
          Icons.account_balance_wallet_outlined,
          'المحفظة والمدفوعات',
          'Wallet & payments',
          '/wallet',
        ),
        (
          Icons.workspace_premium_outlined,
          'باقات العناية',
          'Care plans',
          '/plans',
        ),
        (
          Icons.location_on_outlined,
          'العناوين المحفوظة',
          'Saved addresses',
          '/addresses',
        ),
        (Icons.support_agent, 'الدعم', 'Support', '/support'),
      ],
    };
    return ClevoPage(
      title: 'CLEVO',
      child: ListView(
        padding: const EdgeInsets.all(20),
        children: [
          Text(
            '${tr(context, 'مرحباً', 'Welcome')}, ${user?.name ?? ''}',
            style: Theme.of(context).textTheme.headlineSmall,
          ),
          const SizedBox(height: 8),
          Text(switch (role) {
            'owner' => tr(
              context,
              'إدارة محطاتك وفريقك',
              'Your stations and team',
            ),
            'driver' => tr(
              context,
              'رحلة آمنة، عناية موثوقة',
              'Safe journeys, trusted care',
            ),
            _ => tr(
              context,
              'عناية بسيارتك أينما كنت',
              'Car care, wherever you are',
            ),
          }),
          const SizedBox(height: 24),
          LayoutBuilder(
            builder: (context, constraints) => GridView.count(
              shrinkWrap: true,
              physics: const NeverScrollableScrollPhysics(),
              crossAxisCount: constraints.maxWidth > 700 ? 3 : 2,
              mainAxisSpacing: 8,
              crossAxisSpacing: 8,
              childAspectRatio: 1.25,
              children: [
                for (final tile in tiles)
                  Card(
                    child: InkWell(
                      borderRadius: BorderRadius.circular(20),
                      onTap: () => context.push(tile.$4),
                      child: Padding(
                        padding: const EdgeInsets.all(14),
                        child: Column(
                          mainAxisAlignment: MainAxisAlignment.center,
                          children: [
                            Icon(
                              tile.$1,
                              size: 32,
                              color: Theme.of(context).colorScheme.primary,
                            ),
                            const SizedBox(height: 12),
                            Flexible(
                              child: Text(
                                tr(context, tile.$2, tile.$3),
                                textAlign: TextAlign.center,
                                style: Theme.of(context).textTheme.titleMedium,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ),
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class UnsupportedRoleScreen extends ConsumerWidget {
  const UnsupportedRoleScreen({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) => ClevoPage(
    title: 'CLEVO',
    child: Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              tr(
                context,
                'إدارة النظام متاحة من لوحة إدارة الويب.',
                'System administration is available through the web dashboard.',
              ),
            ),
            TextButton(
              onPressed: () => ref.read(sessionProvider.notifier).logout(),
              child: Text(tr(context, 'تسجيل الخروج', 'Sign out')),
            ),
          ],
        ),
      ),
    ),
  );
}
