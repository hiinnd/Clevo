import 'package:dio/dio.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';
import 'package:material_ui/material_ui.dart';
import '../core/api.dart';
import '../core/state.dart';
import '../ui/common.dart';

final ownerDocumentPickerProvider = Provider<Future<XFile?> Function()>(
  (ref) =>
      () => ImagePicker().pickImage(source: ImageSource.gallery),
);

/// Uses the existing owner registration contract. Approval remains a server decision.
class OwnerOnboardingScreen extends ConsumerStatefulWidget {
  const OwnerOnboardingScreen({super.key});
  @override
  ConsumerState<OwnerOnboardingScreen> createState() => _OwnerOnboardingState();
}

class _OwnerOnboardingState extends ConsumerState<OwnerOnboardingScreen> {
  final _form = GlobalKey<FormState>();
  final _fields = <String, TextEditingController>{
    for (final key in [
      'full_name',
      'email',
      'phone',
      'station_name',
      'password',
    ])
      key: TextEditingController(),
  };
  XFile? _document;
  bool _busy = false, _submitted = false, _uncertain = false;
  @override
  void dispose() {
    for (final controller in _fields.values) {
      controller.dispose();
    }
    super.dispose();
  }

  Future<void> submit() async {
    if (!_form.currentState!.validate() ||
        _document == null ||
        _busy ||
        _uncertain) {
      return;
    }
    setState(() => _busy = true);
    try {
      final document = _document!;
      if (await document.length() > 5 * 1024 * 1024) {
        throw const ApiFailure('upload_size');
      }
      final data = <String, dynamic>{'role': 'owner'};
      for (final entry in _fields.entries) {
        data[entry.key] = entry.key == 'password'
            ? entry.value.text
            : entry.value.text.trim();
      }
      final bytes = await document.readAsBytes();
      if (bytes.length > 5 * 1024 * 1024) throw const ApiFailure('upload_size');
      data['verification_file'] = MultipartFile.fromBytes(
        bytes,
        filename: document.name,
      );
      await ref
          .read(apiProvider)
          .request(
            'auth/register',
            method: 'POST',
            body: FormData.fromMap(data),
            authenticated: false,
          );
      if (mounted) {
        setState(() => _submitted = true);
        _fields['password']!.clear();
      }
    } catch (error) {
      if (mounted) {
        showFailure(context, error);
        setState(() => _uncertain = error is ApiFailure && error.uncertain);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) => ClevoPage(
    title: tr(context, 'تسجيل صاحب محطة', 'Register station owner'),
    child: ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Text(
          tr(
            context,
            'أرسل بياناتك ومستند التحقق. يجب تأكيد البريد وموافقة الإدارة قبل الدخول؛ لا ينشئ الطلب حساباً مفعّلاً أو اشتراكاً مدفوعاً.',
            'Submit your details and verification document. Email verification and Admin approval are required before login. Submission does not activate an account or a paid subscription.',
          ),
        ),
        if (_submitted) ...[
          const SizedBox(height: 16),
          Text(
            tr(
              context,
              'تم تقديم الطلب. راجع بريدك للتحقق وانتظر موافقة الإدارة.',
              'Application submitted. Check your email and await Admin approval.',
            ),
            key: const Key('owner-application-submitted'),
          ),
        ] else
          Form(
            key: _form,
            child: Column(
              children: [
                for (final field in [
                  ('full_name', 'الاسم الكامل', 'Full name'),
                  ('email', 'البريد الإلكتروني', 'Email'),
                  ('phone', 'الهاتف الأردني', 'Jordan phone'),
                  ('station_name', 'اسم المحطة', 'Station name'),
                  ('password', 'كلمة المرور', 'Password'),
                ])
                  Padding(
                    padding: const EdgeInsets.symmetric(vertical: 8),
                    child: TextFormField(
                      key: Key('owner-${field.$1}'),
                      controller: _fields[field.$1],
                      enabled: !_busy && !_uncertain,
                      decoration: InputDecoration(
                        labelText: tr(context, field.$2, field.$3),
                      ),
                      obscureText: field.$1 == 'password',
                      autocorrect: field.$1 != 'password',
                      enableSuggestions: field.$1 != 'password',
                      maxLength: field.$1 == 'password'
                          ? 72
                          : field.$1 == 'phone'
                          ? 10
                          : 100,
                      keyboardType: field.$1 == 'email'
                          ? TextInputType.emailAddress
                          : field.$1 == 'phone'
                          ? TextInputType.phone
                          : TextInputType.text,
                      validator: (value) => (value ?? '').trim().isEmpty
                          ? tr(context, 'هذا الحقل مطلوب', 'Required')
                          : null,
                    ),
                  ),
                OutlinedButton(
                  onPressed: _busy || _uncertain
                      ? null
                      : () async {
                          try {
                            final file = await ref.read(
                              ownerDocumentPickerProvider,
                            )();
                            if (file == null) return;
                            if (await file.length() > 5 * 1024 * 1024) {
                              throw const ApiFailure('upload_size');
                            }
                            if (mounted) setState(() => _document = file);
                          } catch (error) {
                            if (context.mounted) showFailure(context, error);
                          }
                        },
                  child: Text(
                    tr(
                      context,
                      'اختيار صورة مستند التحقق (حتى 5 MB)',
                      'Choose verification document image (up to 5 MB)',
                    ),
                  ),
                ),
                if (_document != null)
                  Text(tr(context, 'تم اختيار المستند', 'Document selected')),
                if (_uncertain)
                  Text(
                    tr(
                      context,
                      'تعذر تأكيد النتيجة. راجع بريدك أو استعادة الحساب قبل تقديم طلب جديد.',
                      'Result unconfirmed. Check your email or account recovery before submitting another application.',
                    ),
                  ),
                FilledButton(
                  onPressed: _busy || _uncertain || _document == null
                      ? null
                      : submit,
                  child: Text(
                    tr(context, 'تقديم طلب التسجيل', 'Submit application'),
                  ),
                ),
              ],
            ),
          ),
        TextButton(
          onPressed: _busy ? null : () => context.go('/recovery'),
          child: Text(
            tr(context, 'التحقق واستعادة الحساب', 'Verification and recovery'),
          ),
        ),
      ],
    ),
  );
}
