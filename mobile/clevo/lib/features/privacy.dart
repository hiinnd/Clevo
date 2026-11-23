import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:url_launcher/url_launcher.dart';
import '../core/state.dart';
import '../ui/common.dart';

class PrivacyScreen extends ConsumerStatefulWidget {
  const PrivacyScreen({super.key});
  @override
  ConsumerState<PrivacyScreen> createState() => _PrivacyScreenState();
}

class _PrivacyScreenState extends ConsumerState<PrivacyScreen> {
  late Future<Map<String, dynamic>> _data;
  @override
  void initState() {
    super.initState();
    _data = load();
  }

  Future<Map<String, dynamic>> load() async {
    final api = ref.read(apiProvider);
    final legal = (await api.request('legal'))['legal'] as Map;
    final status = legal['deletion_enabled'] == true
        ? (await api.request('me/account-deletion-request'))['request']
        : null;
    return {'legal': legal, 'request': status};
  }

  Future<void> open(String file) async {
    try {
      final uri = Uri.parse(ref.read(apiProvider).config.apiBase)
          .resolve('../../general/$file')
          .replace(
            queryParameters: {
              'lang': ref.read(preferencesProvider).locale.languageCode,
            },
          );
      if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) {
        throw StateError('Link unavailable');
      }
    } catch (error) {
      if (mounted) showFailure(context, error);
    }
  }

  Future<void> request({bool cancel = false}) async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: Text(tr(context, 'تأكيد الطلب', 'Confirm request')),
        content: Text(
          tr(
            context,
            'طلب الحذف يخضع لمراجعة الالتزامات والحجوزات. قد تُحتفظ بالسجلات المطلوبة قانونياً وفق سياسة الخصوصية. الطلب لا يعني اكتمال الحذف.',
            'Deletion requires review of outstanding work and obligations. Legally required records may be retained under the privacy policy. A request does not mean deletion is complete.',
          ),
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(context, false),
            child: Text(tr(context, 'رجوع', 'Back')),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: Text(tr(context, 'متابعة', 'Continue')),
          ),
        ],
      ),
    );
    if (confirmed != true || !mounted) return;
    final result = await editRecord(
      context,
      ref,
      title: tr(
        context,
        cancel ? 'إلغاء طلب الحذف' : 'طلب حذف الحساب',
        cancel ? 'Cancel deletion request' : 'Request account deletion',
      ),
      path: 'me/account-deletion-request${cancel ? '/cancel' : ''}',
      method: 'POST',
      fields: const [
        FieldSpec(
          'password',
          'كلمة المرور الحالية',
          'Current password',
          secret: true,
        ),
      ],
      fixed: const {'confirm': true},
    );
    if (result != null && mounted) {
      setState(() {
        _data = load();
      });
    }
  }

  @override
  Widget build(BuildContext context) => ClevoPage(
    title: tr(context, 'الخصوصية والحساب', 'Privacy and account'),
    child: FutureBuilder<Map<String, dynamic>>(
      future: _data,
      builder: (context, snapshot) {
        if (snapshot.hasError) {
          return Center(child: Text(errorText(context, snapshot.error!)));
        }
        if (!snapshot.hasData) {
          return const Center(child: CircularProgressIndicator());
        }
        final legal = snapshot.data!['legal'] as Map;
        final current = snapshot.data!['request'] as Map?;
        return ListView(
          padding: const EdgeInsets.all(16),
          children: [
            ListTile(
              title: Text(tr(context, 'سياسة الخصوصية', 'Privacy policy')),
              onTap: () => open('privacy.php'),
            ),
            ListTile(
              title: Text(tr(context, 'الشروط والأحكام', 'Terms of service')),
              onTap: () => open('terms.php'),
            ),
            if (legal['approved'] != true)
              Text(
                tr(
                  context,
                  'السياسة قيد الاعتماد.',
                  'Policy approval is pending.',
                ),
              ),
            if (current != null)
              DataCard(
                title: tr(
                  context,
                  'طلب حذف الحساب',
                  'Account deletion request',
                ),
                subtitle:
                    '${statusText(context, current['State'])} · #${text(current['RequestID'])}',
              ),
            if (legal['deletion_enabled'] == true &&
                (current == null || current['State'] == 'cancelled'))
              OutlinedButton(
                onPressed: () => request(),
                child: Text(
                  tr(context, 'طلب حذف الحساب', 'Request account deletion'),
                ),
              ),
            if (current?['State'] == 'requested')
              OutlinedButton(
                onPressed: () => request(cancel: true),
                child: Text(tr(context, 'إلغاء الطلب', 'Cancel request')),
              ),
          ],
        );
      },
    ),
  );
}
