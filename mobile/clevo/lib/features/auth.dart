import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import '../core/state.dart';
import '../ui/common.dart';

class LoginScreen extends ConsumerStatefulWidget {
  const LoginScreen({super.key});
  @override
  ConsumerState<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends ConsumerState<LoginScreen> {
  final _login = TextEditingController(), _password = TextEditingController();
  bool _busy = false, _task = false;
  @override
  void dispose() {
    _login.dispose();
    _password.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) => ClevoPage(
    title: 'CLEVO',
    child: Center(
      child: SingleChildScrollView(
        padding: const EdgeInsets.all(24),
        child: SizedBox(
          width: 440,
          child: Column(
            children: [
              Image.asset(
                'assets/brand/logo.png',
                height: 150,
                semanticLabel: 'CLEVO',
              ),
              Text(
                tr(context, 'عناية تستحقها سيارتك', 'Care your car deserves'),
                style: Theme.of(context).textTheme.headlineSmall,
              ),
              const SizedBox(height: 28),
              TextField(
                controller: _login,
                autofillHints: const [AutofillHints.username],
                decoration: InputDecoration(
                  labelText: tr(
                    context,
                    'البريد أو رقم الهاتف',
                    'Email or phone',
                  ),
                ),
              ),
              const SizedBox(height: 16),
              TextField(
                controller: _password,
                obscureText: true,
                autofillHints: const [AutofillHints.password],
                decoration: InputDecoration(
                  labelText: tr(
                    context,
                    _task ? 'رمز المهمة' : 'كلمة المرور',
                    _task ? 'Assignment code' : 'Password',
                  ),
                ),
              ),
              SwitchListTile(
                value: _task,
                title: Text(
                  tr(
                    context,
                    'الدخول برمز مهمة السائق',
                    'Use a driver assignment code',
                  ),
                ),
                onChanged: _busy
                    ? null
                    : (value) => setState(() => _task = value),
              ),
              FilledButton(
                onPressed: _busy
                    ? null
                    : () async {
                        setState(() => _busy = true);
                        try {
                          await ref
                              .read(sessionProvider.notifier)
                              .login(
                                _login.text.trim(),
                                _password.text,
                                taskCode: _task,
                              );
                        } catch (error) {
                          if (context.mounted) showFailure(context, error);
                        } finally {
                          if (mounted) setState(() => _busy = false);
                        }
                      },
                child: Text(tr(context, 'تسجيل الدخول', 'Sign in')),
              ),
              TextButton(
                onPressed: () => context.go('/register'),
                child: Text(tr(context, 'إنشاء حساب', 'Create account')),
              ),
              TextButton(
                onPressed: () => context.go('/recovery'),
                child: Text(
                  tr(
                    context,
                    'استعادة الحساب والتحقق',
                    'Recovery and verification',
                  ),
                ),
              ),
              TextButton(
                onPressed: () => context.go('/browse'),
                child: Text(tr(context, 'استكشف المحطات', 'Explore stations')),
              ),
            ],
          ),
        ),
      ),
    ),
  );
}

class RegisterScreen extends ConsumerWidget {
  const RegisterScreen({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) => ClevoPage(
    title: tr(context, 'إنشاء حساب', 'Create account'),
    child: ListView(
      padding: const EdgeInsets.all(24),
      children: [
        Text(
          tr(
            context,
            'أنشئ حساب زبون ثم أكّد بريدك للدخول.',
            'Create a customer account, then verify your email to sign in.',
          ),
        ),
        const SizedBox(height: 20),
        FilledButton(
          onPressed: () async {
            final result = await editRecord(
              context,
              ref,
              title: tr(context, 'بيانات الحساب', 'Account details'),
              path: 'auth/register',
              method: 'POST',
              idempotent: false,
              fixed: const {'role': 'customer'},
              fields: const [
                FieldSpec('full_name', 'الاسم', 'Full name'),
                FieldSpec('email', 'البريد', 'Email'),
                FieldSpec('phone', 'الهاتف الأردني', 'Jordan phone'),
                FieldSpec('password', 'كلمة المرور', 'Password', secret: true),
              ],
            );
            if (result != null && context.mounted) {
              ScaffoldMessenger.of(context).showSnackBar(
                SnackBar(
                  content: Text(
                    tr(
                      context,
                      'راجع بريدك لتأكيد الحساب',
                      'Check your email to verify your account',
                    ),
                  ),
                ),
              );
            }
          },
          child: Text(tr(context, 'تسجيل زبون', 'Register customer')),
        ),
        const SizedBox(height: 20),
        Text(
          tr(
            context,
            'تسجيل المالك يتطلب مستند التحقق واعتماد الإدارة. يمكن تقديمه عبر موقع CLEVO الحالي؛ حساب السائق ينشئه مالك المحطة.',
            'Owner onboarding requires document verification and approval through the existing CLEVO website. Drivers are provisioned by station owners.',
          ),
        ),
        TextButton(
          onPressed: () => context.go('/login'),
          child: Text(tr(context, 'الدخول', 'Sign in')),
        ),
      ],
    ),
  );
}

class RecoveryScreen extends ConsumerWidget {
  const RecoveryScreen({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) => ClevoPage(
    title: tr(context, 'أمان الحساب', 'Account security'),
    child: ListView(
      padding: const EdgeInsets.all(24),
      children: [
        DataCard(
          title: tr(context, 'استعادة كلمة المرور', 'Reset password'),
          children: [
            FilledButton(
              onPressed: () async {
                final challenge = await editRecord(
                  context,
                  ref,
                  title: tr(
                    context,
                    'إرسال رمز الاستعادة',
                    'Request recovery code',
                  ),
                  path: 'auth/password/forgot',
                  method: 'POST',
                  fields: const [FieldSpec('email', 'البريد', 'Email')],
                  idempotent: false,
                );
                if (challenge == null || !context.mounted) return;
                final grant = await editRecord(
                  context,
                  ref,
                  title: tr(context, 'رمز التحقق', 'Verification code'),
                  path: 'auth/password/verify',
                  method: 'POST',
                  fixed: {'challenge_id': challenge['challenge_id']},
                  fields: const [
                    FieldSpec('otp', 'الرمز', 'Code', secret: true),
                  ],
                  idempotent: false,
                );
                if (grant == null || !context.mounted) return;
                final result = await editRecord(
                  context,
                  ref,
                  title: tr(context, 'كلمة المرور الجديدة', 'New password'),
                  path: 'auth/password/reset',
                  method: 'POST',
                  fixed: {'reset_grant': grant['reset_grant']},
                  fields: const [
                    FieldSpec(
                      'password',
                      'كلمة المرور',
                      'Password',
                      secret: true,
                    ),
                  ],
                  idempotent: false,
                );
                if (result != null && context.mounted) context.go('/login');
              },
              child: Text(tr(context, 'بدء الاستعادة', 'Start recovery')),
            ),
          ],
        ),
        DataCard(
          title: tr(context, 'تأكيد البريد', 'Verify email'),
          children: [
            OutlinedButton(
              onPressed: () => editRecord(
                context,
                ref,
                title: tr(context, 'إعادة إرسال الرابط', 'Resend link'),
                path: 'auth/email/resend',
                method: 'POST',
                fields: const [FieldSpec('email', 'البريد', 'Email')],
                idempotent: false,
              ),
              child: Text(
                tr(
                  context,
                  'إعادة إرسال رسالة التحقق',
                  'Resend verification email',
                ),
              ),
            ),
            OutlinedButton(
              onPressed: () => editRecord(
                context,
                ref,
                title: tr(
                  context,
                  'رمز رابط التحقق',
                  'Verification link token',
                ),
                path: 'auth/email/verify',
                method: 'POST',
                fields: const [
                  FieldSpec(
                    'token',
                    'رمز الرابط الخاص',
                    'Private link token',
                    secret: true,
                  ),
                ],
                idempotent: false,
              ),
              child: Text(
                tr(context, 'تأكيد رابط التحقق', 'Verify link token'),
              ),
            ),
          ],
        ),
        TextButton(
          onPressed: () => context.go('/login'),
          child: Text(tr(context, 'العودة للدخول', 'Back to sign in')),
        ),
      ],
    ),
  );
}
