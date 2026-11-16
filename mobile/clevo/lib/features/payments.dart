import 'dart:async';
import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:url_launcher/url_launcher.dart';
import '../core/api.dart';
import '../core/state.dart';
import '../ui/common.dart';

bool trustedCheckout(Uri uri) =>
    uri.scheme == 'https' &&
    uri.host == 'secure-jordan.paytabs.com' &&
    uri.userInfo.isEmpty &&
    uri.port == 443 &&
    uri.path.startsWith('/payment/');

class BookingPaymentScreen extends ConsumerWidget {
  const BookingPaymentScreen({super.key, required this.id});
  final String id;
  @override
  Widget build(BuildContext context, WidgetRef ref) => ClevoPage(
    title: tr(context, 'دفع الحجز', 'Booking payment'),
    child: ListView(
      padding: const EdgeInsets.all(20),
      children: [
        Text(
          tr(
            context,
            'تُراجع أهلية الدفع والسعر على الخادم. الدفع النقدي متاح للحجوزات داخل المحطة فقط.',
            'The server checks price and eligibility. Cash is available for at-station bookings only.',
          ),
        ),
        const SizedBox(height: 16),
        FilledButton(
          onPressed: () => command(
            context,
            ref,
            'bookings/$id/payments',
            body: {'method': 'cash'},
          ),
          child: Text(
            tr(context, 'اختيار الدفع النقدي', 'Choose cash payment'),
          ),
        ),
        OutlinedButton(
          onPressed: () => createCheckout(context, ref, {
            'purpose': 'booking',
            'booking_id': int.parse(id),
          }),
          child: Text(
            tr(
              context,
              'فتح الدفع الإلكتروني الآمن',
              'Open secure online checkout',
            ),
          ),
        ),
        Text(
          tr(
            context,
            'لا يُعتبر فتح صفحة الدفع أو الرجوع منها تأكيداً للدفع. تظهر النتيجة بعد تحقق الخادم فقط.',
            'Opening or returning from checkout does not confirm payment. Only server verification changes payment status.',
          ),
        ),
        TextButton(
          onPressed: () => context.push('/payment-orders'),
          child: Text(
            tr(context, 'سجل جلسات الدفع', 'Payment session history'),
          ),
        ),
      ],
    ),
  );
}

Future<void> createCheckout(
  BuildContext context,
  WidgetRef ref,
  Map<String, dynamic> body,
) async {
  final result = await editRecord(
    context,
    ref,
    title: tr(context, 'إنشاء جلسة دفع', 'Create payment session'),
    path: 'payment-orders',
    method: 'POST',
    fields: const [],
    fixed: body,
  );
  if (result == null || !context.mounted) return;
  final order = result['payment_order'] as Map?;
  final id = order?['OrderID'];
  if (id is String && RegExp(r'^[a-f0-9]{48}$').hasMatch(id)) {
    context.push('/payment-order/$id');
  }
}

class PaymentOrderScreen extends ConsumerStatefulWidget {
  const PaymentOrderScreen({super.key, required this.id});
  final String id;
  @override
  ConsumerState<PaymentOrderScreen> createState() => _PaymentOrderScreenState();
}

class _PaymentOrderScreenState extends ConsumerState<PaymentOrderScreen>
    with WidgetsBindingObserver {
  late Future<Map<String, dynamic>> _future;
  Timer? _timer;
  bool _active = true;
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    reload();
  }

  @override
  void dispose() {
    _timer?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    _active = state == AppLifecycleState.resumed;
    _timer?.cancel();
    if (_active) reload();
  }

  void reload() {
    _timer?.cancel();
    setState(
      () => _future = ref
          .read(apiProvider)
          .request('payment-orders/${widget.id}')
          .then((data) {
            final order = data['payment_order'] as Map?;
            if (mounted &&
                _active &&
                [
                  'prepared',
                  'creating',
                  'pending',
                  'unknown',
                ].contains(order?['State'])) {
              _timer = Timer(const Duration(seconds: 10), reload);
            }
            return data;
          }),
    );
  }

  @override
  Widget build(BuildContext context) => ClevoPage(
    title: tr(context, 'حالة الدفع', 'Payment status'),
    actions: [IconButton(onPressed: reload, icon: const Icon(Icons.refresh))],
    child: FutureBuilder(
      future: _future,
      builder: (context, snapshot) {
        if (snapshot.hasError) return ErrorPanel(snapshot.error!, reload);
        if (!snapshot.hasData) {
          return const Center(child: CircularProgressIndicator());
        }
        final order = snapshot.data!['payment_order'] as Map? ?? {};
        final checkout = Uri.tryParse('${order['checkout_url']}');
        return ListView(
          padding: const EdgeInsets.all(20),
          children: [
            DataCard(
              title: money(order['amount']),
              subtitle: '${text(order['State'])} · ${text(order['Purpose'])}',
              children: [
                if (order['sandbox'] == true)
                  Text(
                    tr(
                      context,
                      'بيئة اختبار — ليست دفعة تجارية',
                      'Sandbox — not a commercial payment',
                    ),
                  ),
                if (order['State'] == 'succeeded')
                  Text(
                    tr(
                      context,
                      'تم التحقق من الدفع على الخادم',
                      'Payment verified by server',
                    ),
                  ),
              ],
            ),
            if (checkout != null &&
                trustedCheckout(checkout) &&
                order['State'] == 'pending')
              FilledButton(
                onPressed: () async {
                  try {
                    if (!await launchUrl(
                      checkout,
                      mode: LaunchMode.externalApplication,
                    )) {
                      throw const ApiFailure('checkout_unavailable');
                    }
                  } catch (error) {
                    if (context.mounted) showFailure(context, error);
                  }
                },
                child: Text(
                  tr(
                    context,
                    'متابعة لدى مزود الدفع',
                    'Continue with payment provider',
                  ),
                ),
              ),
            OutlinedButton(
              onPressed: () async {
                if (await command(
                  context,
                  ref,
                  'payment-orders/${widget.id}/reconcile',
                )) {
                  reload();
                }
              },
              child: Text(
                tr(context, 'التحقق لدى مزود الدفع', 'Check with provider'),
              ),
            ),
            if (['prepared', 'pending', 'unknown'].contains(order['State']))
              OutlinedButton(
                onPressed: () async {
                  if (await command(
                    context,
                    ref,
                    'payment-orders/${widget.id}/cancel',
                  )) {
                    reload();
                  }
                },
                child: Text(
                  tr(
                    context,
                    'طلب إلغاء الجلسة',
                    'Request session cancellation',
                  ),
                ),
              ),
            if (order['State'] == 'succeeded')
              OutlinedButton(
                onPressed: () => editRecord(
                  context,
                  ref,
                  title: tr(context, 'طلب استرداد', 'Request refund'),
                  path: 'payment-orders/${widget.id}/refund-requests',
                  method: 'POST',
                  fields: const [
                    FieldSpec('reason', 'السبب', 'Reason', lines: 3),
                  ],
                ),
                child: Text(tr(context, 'طلب استرداد', 'Request refund')),
              ),
          ],
        );
      },
    ),
  );
}

class WalletScreen extends ConsumerWidget {
  const WalletScreen({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) => ClevoPage(
    title: tr(context, 'المحفظة والمدفوعات', 'Wallet & payments'),
    child: ListView(
      padding: const EdgeInsets.all(16),
      children: [
        FutureBuilder(
          future: ref.read(apiProvider).request('me/wallet'),
          builder: (context, snapshot) {
            if (snapshot.hasError) {
              return ErrorPanel(
                snapshot.error!,
                () => context.pushReplacement('/wallet'),
              );
            }
            if (!snapshot.hasData) return const LinearProgressIndicator();
            final wallet = snapshot.data!['wallet'] as Map? ?? {};
            return DataCard(
              title: money(wallet['Balance']),
              subtitle: tr(
                context,
                'سجل الرصيد والحركات متاح. الشحن والدفع بالمحفظة غير متاحين حالياً.',
                'Your recorded balance and history are available. Wallet payments and top-ups are currently unavailable.',
              ),
            );
          },
        ),
        for (final item in <(String, String, String)>[
          ('الحركات المالية', 'Wallet transactions', '/wallet/transactions'),
          ('جلسات الدفع', 'Payment sessions', '/payment-orders'),
          ('الإيصالات', 'Receipts', '/receipts'),
          ('الاستردادات', 'Refunds', '/refunds'),
          ('اشتراكاتي', 'My subscriptions', '/subscriptions'),
        ])
          DataCard(
            title: tr(context, item.$1, item.$2),
            trailing: const Icon(Icons.chevron_right),
            onTap: () => context.push(item.$3),
          ),
      ],
    ),
  );
}

class PlansScreen extends ConsumerWidget {
  const PlansScreen({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) => ClevoPage(
    title: tr(context, 'باقات العناية', 'Care plans'),
    child: ResourceList(
      path: 'subscription-plans',
      field: 'plans',
      itemBuilder: (context, row, reload) => DataCard(
        title: localizedName(context, row),
        subtitle:
            '${money(row['price'])}\n${text(row[Localizations.localeOf(context).languageCode == 'ar' ? 'description_ar' : 'description_en'])}',
        children: [
          Text(
            '${tr(context, 'عدد الغسلات', 'Washes')}: ${text(row['wash_count'])} · ${text(row['duration'])} ${text(row['duration_type'])}',
          ),
          Text(
            text(
              row[Localizations.localeOf(context).languageCode == 'ar'
                  ? 'included_services_ar'
                  : 'included_services_en'],
            ),
          ),
          FilledButton(
            onPressed: () => createCheckout(context, ref, {
              'purpose': 'subscription',
              'plan_id': idOf(row, 'id'),
            }),
            child: Text(
              tr(context, 'متابعة الدفع الآمن', 'Continue to secure payment'),
            ),
          ),
          Text(
            tr(
              context,
              'التفعيل بعد تأكيد الخادم فقط. الدفع التجاري غير مفعّل حالياً.',
              'Activation requires verified server payment. Commercial checkout is currently disabled.',
            ),
          ),
        ],
      ),
    ),
  );
}
