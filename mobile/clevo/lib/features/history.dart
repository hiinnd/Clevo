import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../core/state.dart';
import '../ui/common.dart';

class HistoryScreen extends ConsumerWidget {
  const HistoryScreen({super.key, required this.kind, this.id});
  final String kind;
  final String? id;
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final config = switch (kind) {
      'wallet' => (
        'me/wallet/transactions',
        'transactions',
        'حركات المحفظة',
        'Wallet transactions',
      ),
      'orders' => (
        'payment-orders',
        'payment_orders',
        'جلسات الدفع',
        'Payment sessions',
      ),
      'receipts' => ('financial-receipts', 'receipts', 'الإيصالات', 'Receipts'),
      'refunds' => ('payment-refunds', 'refunds', 'الاستردادات', 'Refunds'),
      'subscriptions' => (
        'me/subscriptions',
        'subscriptions',
        'اشتراكاتي',
        'My subscriptions',
      ),
      'events' => (
        'bookings/$id/events',
        'events',
        'سجل الحجز',
        'Booking timeline',
      ),
      'statement' => (
        'owner/financial-statement',
        'transactions',
        'كشف الحساب',
        'Statement',
      ),
      'owner_reviews' => (
        'owner/reviews',
        'reviews',
        'تقييمات محطاتي',
        'Station reviews',
      ),
      'owner_subscriptions' => (
        'owner/subscription-usage',
        'redemptions',
        'استخدام الباقات في حجوزاتي',
        'Plan usage in my bookings',
      ),
      'station_reviews' => (
        'stations/$id/reviews',
        'reviews',
        'التقييمات',
        'Reviews',
      ),
      _ => ('me/reviews', 'reviews', 'تقييماتي', 'My reviews'),
    };
    return ClevoPage(
      title: tr(context, config.$3, config.$4),
      child: ResourceList(
        path: config.$1,
        field: config.$2,
        itemBuilder: (context, row, reload) {
          if (kind == 'owner_subscriptions') {
            return DataCard(
              title: '${text(row['StationName'])} · #${text(row['BookingID'])}',
              subtitle:
                  '${statusText(context, row['State'])}\n${tr(context, 'قيمة الخدمات المغطاة بالفلس', 'Covered service value in fils')}: ${text(row['AmountMinor'])}\n${text(row['CreatedAt'])}',
            );
          }
          if (kind == 'orders') {
            return DataCard(
              title: money(row['amount']),
              subtitle:
                  '${statusText(context, row['State'])} · ${statusText(context, row['Purpose'])}',
              onTap: () => context.push('/payment-order/${row['OrderID']}'),
            );
          }
          if (kind.contains('reviews') || kind == 'reviews') {
            return DataCard(
              title: '${text(row['Rating'])} ★',
              subtitle: text(row['Comment']),
              children: [
                if (row['Owner_Reply'] != null) Text(text(row['Owner_Reply'])),
                if (kind == 'owner_reviews')
                  TextButton(
                    onPressed: () async {
                      final result = await editRecord(
                        context,
                        ref,
                        title: tr(context, 'رد على التقييم', 'Reply to review'),
                        path: 'owner/reviews/${row['ReviewID']}/reply',
                        method: 'PATCH',
                        fields: const [
                          FieldSpec('message', 'الرد', 'Reply', lines: 3),
                        ],
                      );
                      if (result != null) reload();
                    },
                    child: Text(tr(context, 'رد', 'Reply')),
                  ),
              ],
            );
          }
          if (kind == 'events') {
            return DataCard(
              title:
                  '${statusText(context, row['FromStatus'])} → ${statusText(context, row['ToStatus'])}',
              subtitle:
                  '${statusText(context, row['ActorRole'])} · ${text(row['ChangedAt'])}\n${text(row['Reason'])}',
            );
          }
          if (kind == 'wallet') {
            return DataCard(
              title: money(row['Amount']),
              subtitle:
                  '${statusText(context, row['TransactionType'])}\n${text(row[Localizations.localeOf(context).languageCode == 'ar' ? 'Description_ar' : 'Description_en'])}\n${text(row['CreatedAt'])}',
            );
          }
          if (kind == 'subscriptions') {
            return DataCard(
              title: '${tr(context, 'باقة', 'Plan')} #${text(row['plan_id'])}',
              subtitle:
                  '${statusText(context, row['status'])} · ${text(row['remaining_washes'])} ${tr(context, 'غسلات متبقية', 'washes remaining')}\n${text(row['start_date'])} → ${text(row['end_date'])}',
            );
          }
          if (kind == 'statement') {
            return DataCard(
              title: text(row['Kind']),
              subtitle:
                  '${tr(context, 'أرباح بالفلس', 'Earnings in fils')}: ${text(row['OwnerPayableDeltaMinor'])}\n${tr(context, 'عمولة نقدية بالفلس', 'Cash commission in fils')}: ${text(row['CashCommissionDueDeltaMinor'])}\n${text(row['CreatedAt'])}',
            );
          }
          return DataCard(
            title: text(row['ReceiptNumber'] ?? row['State']),
            subtitle:
                '${tr(context, 'المبلغ بالفلس', 'Amount in fils')}: ${text(row['AmountMinor'])}\n${text(row['IssuedAt'] ?? row['CreatedAt'])}\n${text(row['EvidenceKind'] ?? row['Reason'])}',
          );
        },
      ),
    );
  }
}
