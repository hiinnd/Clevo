import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../core/api.dart';
import '../core/state.dart';

String errorText(BuildContext context, Object error) {
  final code = error is ApiFailure ? error.code : '';
  if (error is ApiFailure && error.uncertain) {
    return tr(
      context,
      'تعذر تأكيد النتيجة. أعد المحاولة بنفس الطلب أو راجع السجل.',
      'The result is unconfirmed. Retry the same request or check history.',
    );
  }
  if (error is ApiFailure && error.status == 503) {
    return tr(
      context,
      'هذه الخدمة غير متاحة حالياً. لم يُؤكّد أي دفع أو تحويل.',
      'This service is unavailable. No payment or transfer has been confirmed.',
    );
  }
  return switch (code) {
    'invalid_credentials' => tr(
      context,
      'بيانات الدخول غير صحيحة أو الحساب غير مفعّل',
      'Invalid credentials or inactive account',
    ),
    'not_found' || 'resource_not_found' => tr(
      context,
      'الطلب غير موجود أو غير متاح لك',
      'Resource unavailable or inaccessible',
    ),
    'invalid_or_expired_confirmation' => tr(
      context,
      'رمز التأكيد غير صحيح أو منتهي',
      'Invalid or expired confirmation code',
    ),
    'station_capacity_not_configured' || 'booking_capacity_exceeded' => tr(
      context,
      'الموعد غير متاح حالياً',
      'The appointment is unavailable',
    ),
    'payment_required' => tr(
      context,
      'يلزم تأكيد طريقة الدفع قبل بدء المهمة',
      'Payment confirmation is required before dispatch',
    ),
    'forbidden' => tr(
      context,
      'لا يملك حسابك صلاحية هذا الإجراء',
      'Your account cannot perform this action',
    ),
    'rate_limited' => tr(
      context,
      'طلبات كثيرة. انتظر ثم أعد المحاولة',
      'Too many requests. Wait before retrying',
    ),
    _ => tr(
      context,
      'تعذر تنفيذ الطلب. تحقق من الحقول والاتصال وحالة الطلب.',
      'Request unsuccessful. Check fields, connection and current status.',
    ),
  };
}

void showFailure(BuildContext context, Object error) => ScaffoldMessenger.of(
  context,
).showSnackBar(SnackBar(content: Text(errorText(context, error))));
String text(dynamic value, [String fallback = '—']) =>
    value == null || '$value'.isEmpty ? fallback : '$value';
int idOf(Map<String, dynamic> row, String field) =>
    int.tryParse('${row[field]}') ?? 0;
String money(dynamic value) => value == null ? '—' : '$value JOD';
String localizedName(BuildContext context, Map<String, dynamic> row) => text(
  row[Localizations.localeOf(context).languageCode == 'ar'
          ? 'Name_ar'
          : 'Name_en'] ??
      row[Localizations.localeOf(context).languageCode == 'ar'
          ? 'name_ar'
          : 'name_en'] ??
      row['Name'] ??
      row['ServiceName'] ??
      row['name'],
);

class ClevoPage extends ConsumerWidget {
  const ClevoPage({
    super.key,
    required this.title,
    required this.child,
    this.actions = const [],
  });
  final String title;
  final Widget child;
  final List<Widget> actions;
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(sessionProvider).asData?.value;
    return Scaffold(
      appBar: AppBar(
        title: Text(title),
        actions: [
          ...actions,
          IconButton(
            tooltip: tr(context, 'اللغة', 'Language'),
            icon: const Icon(Icons.language),
            onPressed: () {
              ref
                  .read(preferencesProvider.notifier)
                  .language(
                    Localizations.localeOf(context).languageCode == 'ar'
                        ? 'en'
                        : 'ar',
                  );
            },
          ),
          IconButton(
            tooltip: tr(context, 'المظهر', 'Appearance'),
            icon: const Icon(Icons.brightness_6_outlined),
            onPressed: () {
              ref
                  .read(preferencesProvider.notifier)
                  .theme(
                    Theme.of(context).brightness == Brightness.dark
                        ? ThemeMode.light
                        : ThemeMode.dark,
                  );
            },
          ),
        ],
      ),
      body: SafeArea(
        child: Align(
          alignment: Alignment.topCenter,
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 1050),
            child: child,
          ),
        ),
      ),
      bottomNavigationBar: user == null || !user.mobileRole
          ? null
          : NavigationBar(
              selectedIndex:
                  GoRouter.of(context).routeInformationProvider.value.uri.path
                      .startsWith('/notifications')
                  ? 1
                  : GoRouter.of(context).routeInformationProvider.value.uri.path
                        .startsWith('/profile')
                  ? 2
                  : 0,
              onDestinationSelected: (index) => context.go(switch (index) {
                1 => '/notifications',
                2 => '/profile',
                _ => '/${user.role}',
              }),
              destinations: [
                NavigationDestination(
                  icon: const Icon(Icons.home_outlined),
                  label: tr(context, 'الرئيسية', 'Home'),
                ),
                NavigationDestination(
                  icon: const Icon(Icons.notifications_outlined),
                  label: tr(context, 'الإشعارات', 'Inbox'),
                ),
                NavigationDestination(
                  icon: const Icon(Icons.person_outline),
                  label: tr(context, 'حسابي', 'Account'),
                ),
              ],
            ),
    );
  }
}

class ErrorPanel extends StatelessWidget {
  const ErrorPanel(this.error, this.retry, {super.key});
  final Object error;
  final VoidCallback retry;
  @override
  Widget build(BuildContext context) => Center(
    child: Padding(
      padding: const EdgeInsets.all(24),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          const Icon(Icons.cloud_off_outlined, size: 42),
          const SizedBox(height: 16),
          Text(errorText(context, error), textAlign: TextAlign.center),
          const SizedBox(height: 16),
          OutlinedButton.icon(
            onPressed: retry,
            icon: const Icon(Icons.refresh),
            label: Text(tr(context, 'إعادة المحاولة', 'Retry')),
          ),
        ],
      ),
    ),
  );
}

class DataCard extends StatelessWidget {
  const DataCard({
    super.key,
    required this.title,
    this.subtitle,
    this.trailing,
    this.onTap,
    this.children = const [],
  });
  final String title;
  final String? subtitle;
  final Widget? trailing;
  final VoidCallback? onTap;
  final List<Widget> children;
  @override
  Widget build(BuildContext context) => Card(
    child: InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(20),
      child: Padding(
        padding: const EdgeInsets.all(18),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: Text(
                    title,
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                ),
                ?trailing,
              ],
            ),
            if (subtitle != null) ...[
              const SizedBox(height: 8),
              Text(subtitle!, style: Theme.of(context).textTheme.bodyMedium),
            ],
            ...children,
          ],
        ),
      ),
    ),
  );
}

typedef RowBuilder =
    Widget Function(
      BuildContext context,
      Map<String, dynamic> row,
      VoidCallback reload,
    );

class ResourceList extends ConsumerStatefulWidget {
  const ResourceList({
    super.key,
    required this.path,
    required this.field,
    required this.itemBuilder,
    this.query,
    this.header,
  });
  final String path, field;
  final RowBuilder itemBuilder;
  final Map<String, dynamic>? query;
  final Widget? header;
  @override
  ConsumerState<ResourceList> createState() => _ResourceListState();
}

class _ResourceListState extends ConsumerState<ResourceList> {
  final _rows = <Map<String, dynamic>>[];
  Object? _error;
  bool _busy = true;
  int _page = 0, _total = 1, _request = 0;
  @override
  void initState() {
    super.initState();
    load(reset: true);
  }

  @override
  void didUpdateWidget(covariant ResourceList oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.path != widget.path ||
        oldWidget.query.toString() != widget.query.toString()) {
      load(reset: true);
    }
  }

  Future<void> load({bool reset = false}) async {
    final request = ++_request;
    setState(() {
      _busy = true;
      _error = null;
      if (reset) {
        _rows.clear();
        _page = 0;
      }
    });
    try {
      final result = await ref
          .read(apiProvider)
          .list(
            widget.path,
            widget.field,
            page: _page + 1,
            query: widget.query,
          );
      if (!mounted || request != _request) return;
      setState(() {
        _rows.addAll(result.rows);
        _page = result.page;
        _total = result.totalPages;
      });
    } catch (error) {
      if (mounted && request == _request) setState(() => _error = error);
    } finally {
      if (mounted && request == _request) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => RefreshIndicator(
    onRefresh: () => load(reset: true),
    child: ListView(
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.all(16),
      children: [
        if (widget.header != null) widget.header!,
        for (final row in _rows)
          widget.itemBuilder(context, row, () => load(reset: true)),
        if (_busy)
          const Padding(
            padding: EdgeInsets.all(24),
            child: Center(child: CircularProgressIndicator()),
          ),
        if (_error != null)
          ErrorPanel(_error!, () => load(reset: _rows.isEmpty)),
        if (!_busy && _error == null && _rows.isEmpty)
          Padding(
            padding: const EdgeInsets.all(40),
            child: Text(
              tr(context, 'لا توجد نتائج بعد', 'Nothing here yet'),
              textAlign: TextAlign.center,
            ),
          ),
        if (!_busy && _page < _total)
          OutlinedButton(
            onPressed: load,
            child: Text(tr(context, 'عرض المزيد', 'Load more')),
          ),
      ],
    ),
  );
}

class FieldSpec {
  const FieldSpec(
    this.key,
    this.ar,
    this.en, {
    this.required = true,
    this.number = false,
    this.integer = false,
    this.secret = false,
    this.initial = '',
    this.lines = 1,
  });
  final String key, ar, en, initial;
  final bool required, number, integer, secret;
  final int lines;
}

Future<Map<String, dynamic>?> editRecord(
  BuildContext context,
  WidgetRef ref, {
  required String title,
  required String path,
  required String method,
  required List<FieldSpec> fields,
  Map<String, dynamic> fixed = const {},
  bool idempotent = true,
}) => showDialog<Map<String, dynamic>>(
  context: context,
  barrierDismissible: false,
  builder: (_) => RecordDialog(
    title: title,
    path: path,
    method: method,
    fields: fields,
    fixed: fixed,
    idempotent: idempotent,
  ),
);

class RecordDialog extends ConsumerStatefulWidget {
  const RecordDialog({
    super.key,
    required this.title,
    required this.path,
    required this.method,
    required this.fields,
    required this.fixed,
    required this.idempotent,
  });
  final String title, path, method;
  final List<FieldSpec> fields;
  final Map<String, dynamic> fixed;
  final bool idempotent;
  @override
  ConsumerState<RecordDialog> createState() => _RecordDialogState();
}

class _RecordDialogState extends ConsumerState<RecordDialog> {
  final _form = GlobalKey<FormState>();
  final _controllers = <String, TextEditingController>{};
  final _key = operationKey();
  bool _busy = false, _uncertain = false;
  Map<String, dynamic>? _submitted;
  @override
  void initState() {
    super.initState();
    for (final f in widget.fields) {
      _controllers[f.key] = TextEditingController(text: f.initial);
    }
  }

  @override
  void dispose() {
    for (final controller in _controllers.values) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> save() async {
    if (!_form.currentState!.validate()) return;
    final values = <String, dynamic>{...widget.fixed};
    for (final field in widget.fields) {
      final value = field.secret
          ? _controllers[field.key]!.text
          : _controllers[field.key]!.text.trim();
      if (value.isEmpty && !field.required) continue;
      values[field.key] = field.integer
          ? int.parse(value)
          : field.number
          ? double.parse(value)
          : value;
    }
    _submitted ??= values;
    setState(() => _busy = true);
    try {
      final result = await ref
          .read(apiProvider)
          .request(
            widget.path,
            method: widget.method,
            body: _submitted,
            key: widget.idempotent ? _key : null,
          );
      if (mounted) Navigator.pop(context, result);
    } catch (error) {
      if (mounted) {
        showFailure(context, error);
        setState(() {
          _uncertain = error is ApiFailure && error.uncertain;
          if (!_uncertain) _submitted = null;
        });
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => AlertDialog(
    title: Text(widget.title),
    content: SizedBox(
      width: 440,
      child: SingleChildScrollView(
        child: Form(
          key: _form,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (_uncertain)
                Text(
                  tr(
                    context,
                    'النتيجة غير مؤكدة؛ ستعيد المحاولة الطلب نفسه.',
                    'Outcome unconfirmed; retry sends the identical request.',
                  ),
                ),
              for (final field in widget.fields)
                Padding(
                  padding: const EdgeInsets.only(bottom: 14),
                  child: TextFormField(
                    controller: _controllers[field.key],
                    enabled: !_busy && !_uncertain,
                    obscureText: field.secret,
                    maxLines: field.secret ? 1 : field.lines,
                    keyboardType: field.number || field.integer
                        ? const TextInputType.numberWithOptions(decimal: true)
                        : TextInputType.text,
                    decoration: InputDecoration(
                      labelText: tr(context, field.ar, field.en),
                    ),
                    validator: (value) {
                      if (field.required && (value ?? '').trim().isEmpty) {
                        return tr(context, 'حقل مطلوب', 'Required');
                      }
                      if ((value ?? '').isNotEmpty &&
                          (field.number || field.integer) &&
                          (field.integer
                              ? int.tryParse(value!) == null
                              : double.tryParse(value!) == null)) {
                        return tr(context, 'رقم غير صحيح', 'Invalid number');
                      }
                      return null;
                    },
                  ),
                ),
            ],
          ),
        ),
      ),
    ),
    actions: [
      TextButton(
        onPressed: _busy ? null : () => Navigator.pop(context),
        child: Text(tr(context, 'إغلاق', 'Close')),
      ),
      FilledButton(
        onPressed: _busy ? null : save,
        child: _busy
            ? const SizedBox(
                width: 20,
                height: 20,
                child: CircularProgressIndicator(strokeWidth: 2),
              )
            : Text(tr(context, 'تأكيد', 'Confirm')),
      ),
    ],
  );
}

Future<bool> command(
  BuildContext context,
  WidgetRef ref,
  String path, {
  Map<String, dynamic> body = const {},
  String method = 'POST',
}) async {
  final result = await editRecord(
    context,
    ref,
    title: tr(context, 'تأكيد الإجراء', 'Confirm action'),
    path: path,
    method: method,
    fields: const [],
    fixed: body,
  );
  return result != null;
}
