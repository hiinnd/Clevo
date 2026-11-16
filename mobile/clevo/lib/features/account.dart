import 'package:dio/dio.dart';
import 'package:material_ui/material_ui.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';
import '../core/api.dart';
import '../core/state.dart';
import '../core/notifications.dart';
import '../ui/common.dart';

class ProfileScreen extends ConsumerWidget {
  const ProfileScreen({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final user = ref.watch(sessionProvider).asData?.value;
    return ClevoPage(
      title: tr(context, 'حسابي', 'My account'),
      child: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          DataCard(
            title: user?.name ?? '',
            subtitle:
                '${text(user?.raw['email'])}\n${text(user?.raw['phone'])}',
            children: [
              Text(
                '${tr(context, 'الصلاحية المعتمدة', 'Authorized role')}: ${user?.role}',
              ),
              OutlinedButton(
                onPressed: () async {
                  final result = await editRecord(
                    context,
                    ref,
                    title: tr(context, 'تعديل الملف', 'Edit profile'),
                    path: 'me',
                    method: 'PATCH',
                    fields: [
                      FieldSpec(
                        'full_name',
                        'الاسم',
                        'Full name',
                        initial: user?.name ?? '',
                      ),
                      FieldSpec(
                        'phone',
                        'الهاتف',
                        'Phone',
                        initial: text(user?.raw['phone'], ''),
                      ),
                      FieldSpec(
                        'email',
                        'البريد',
                        'Email',
                        initial: text(user?.raw['email'], ''),
                      ),
                      FieldSpec(
                        'car_name',
                        'السيارة',
                        'Car',
                        required: false,
                        initial: text(user?.raw['car_name'], ''),
                      ),
                    ],
                  );
                  if (result != null) {
                    await ref.read(sessionProvider.notifier).reloadProfile();
                  }
                },
                child: Text(tr(context, 'تعديل الملف', 'Edit profile')),
              ),
              OutlinedButton(
                onPressed: () async {
                  try {
                    final image = await ImagePicker().pickImage(
                      source: ImageSource.gallery,
                      maxWidth: 1600,
                      imageQuality: 85,
                    );
                    if (image == null) return;
                    if (await image.length() > 5 * 1024 * 1024) {
                      throw const ApiFailure('file_too_large');
                    }
                    await ref
                        .read(apiProvider)
                        .request(
                          'me/avatar',
                          method: 'POST',
                          key: operationKey(),
                          body: FormData.fromMap({
                            'avatar': await MultipartFile.fromFile(
                              image.path,
                              filename: image.name,
                            ),
                          }),
                        );
                    await ref.read(sessionProvider.notifier).reloadProfile();
                  } catch (error) {
                    if (context.mounted) showFailure(context, error);
                  }
                },
                child: Text(tr(context, 'تحديث الصورة', 'Update photo')),
              ),
            ],
          ),
          DataCard(
            title: tr(context, 'إعدادات الإشعارات', 'Notification settings'),
            onTap: () => context.push('/notification-preferences'),
          ),
          DataCard(
            title: tr(context, 'أمان الحساب', 'Account security'),
            onTap: () => context.push('/recovery'),
          ),
          if (user?.role == 'customer')
            DataCard(
              title: tr(context, 'تقييماتي', 'My reviews'),
              onTap: () => context.push('/reviews'),
            ),
          DataCard(
            title: tr(context, 'الدعم', 'Support'),
            onTap: () => context.push('/support'),
          ),
          FilledButton.tonal(
            onPressed: () async {
              final cleanup = ref.read(messagingProvider).detach();
              await ref.read(sessionProvider.notifier).logout();
              await cleanup;
            },
            child: Text(tr(context, 'تسجيل الخروج', 'Sign out')),
          ),
        ],
      ),
    );
  }
}

class AddressesScreen extends ConsumerWidget {
  const AddressesScreen({super.key});
  Future<void> edit(
    BuildContext context,
    WidgetRef ref, [
    Map<String, dynamic>? row,
  ]) async {
    final result = await editRecord(
      context,
      ref,
      title: tr(context, 'العنوان', 'Address'),
      path: row == null ? 'me/addresses' : 'me/addresses/${row['AddressID']}',
      method: row == null ? 'POST' : 'PUT',
      fields: [
        FieldSpec(
          'label',
          'اسم العنوان',
          'Label',
          initial: text(row?['Label'], ''),
        ),
        FieldSpec(
          'address',
          'التفاصيل',
          'Details',
          lines: 3,
          initial: text(row?['AddressText'], ''),
        ),
        FieldSpec(
          'latitude',
          'خط العرض',
          'Latitude',
          number: true,
          initial: text(row?['Latitude'], ''),
        ),
        FieldSpec(
          'longitude',
          'خط الطول',
          'Longitude',
          number: true,
          initial: text(row?['Longitude'], ''),
        ),
      ],
      fixed: {'is_default': '${row?['IsDefault']}' == '1'},
    );
    if (result != null && context.mounted) {
      context.pushReplacement('/addresses');
    }
  }

  @override
  Widget build(BuildContext context, WidgetRef ref) => ClevoPage(
    title: tr(context, 'عناويني', 'My addresses'),
    actions: [
      IconButton(
        onPressed: () => edit(context, ref),
        icon: const Icon(Icons.add_location_alt_outlined),
      ),
    ],
    child: ResourceList(
      path: 'me/addresses',
      field: 'addresses',
      itemBuilder: (context, row, reload) => DataCard(
        title: text(row['Label']),
        subtitle: text(row['AddressText']),
        children: [
          Wrap(
            spacing: 12,
            children: [
              TextButton(
                onPressed: () => edit(context, ref, row),
                child: Text(tr(context, 'تعديل', 'Edit')),
              ),
              TextButton(
                onPressed: () async {
                  if (await command(
                    context,
                    ref,
                    'me/addresses/${row['AddressID']}',
                    method: 'DELETE',
                  )) {
                    reload();
                  }
                },
                child: Text(tr(context, 'حذف', 'Delete')),
              ),
            ],
          ),
        ],
      ),
    ),
  );
}

class SupportScreen extends ConsumerWidget {
  const SupportScreen({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) => ClevoPage(
    title: tr(context, 'الدعم', 'Support'),
    actions: [
      IconButton(
        icon: const Icon(Icons.add_comment_outlined),
        onPressed: () async {
          final result = await editRecord(
            context,
            ref,
            title: tr(context, 'طلب دعم', 'Support request'),
            path: 'support-requests',
            method: 'POST',
            fixed: const {'category': 'support'},
            fields: const [
              FieldSpec('subject', 'الموضوع', 'Subject'),
              FieldSpec('message', 'الرسالة', 'Message', lines: 5),
              FieldSpec(
                'booking_id',
                'رقم الحجز اختياري',
                'Booking ID optional',
                integer: true,
                required: false,
              ),
            ],
          );
          if (result != null && context.mounted) {
            context.push('/support/${result['ticket_id']}');
          }
        },
      ),
    ],
    child: ResourceList(
      path: 'support-requests',
      field: 'support_requests',
      itemBuilder: (context, row, reload) => DataCard(
        title: text(row['subject']),
        subtitle: '${text(row['status'])} · ${text(row['created_at'])}',
        onTap: () => context.push('/support/${row['id']}'),
      ),
    ),
  );
}

class SupportThreadScreen extends ConsumerWidget {
  const SupportThreadScreen({super.key, required this.id});
  final String id;
  @override
  Widget build(BuildContext context, WidgetRef ref) => ClevoPage(
    title: '${tr(context, 'طلب الدعم', 'Support request')} #$id',
    actions: [
      IconButton(
        icon: const Icon(Icons.reply),
        onPressed: () async {
          final result = await editRecord(
            context,
            ref,
            title: tr(context, 'رد', 'Reply'),
            path: 'support-requests/$id/messages',
            method: 'POST',
            fields: const [
              FieldSpec('message', 'الرسالة', 'Message', lines: 5),
            ],
          );
          if (result != null && context.mounted) {
            context.pushReplacement('/support/$id');
          }
        },
      ),
    ],
    child: ResourceList(
      path: 'support-requests/$id/messages',
      field: 'messages',
      itemBuilder: (context, row, reload) => DataCard(
        title: text(row['SenderRole']),
        subtitle: '${text(row['Message'])}\n${text(row['CreatedAt'])}',
      ),
    ),
  );
}

class NotificationsScreen extends ConsumerWidget {
  const NotificationsScreen({super.key});
  @override
  Widget build(BuildContext context, WidgetRef ref) => ClevoPage(
    title: tr(context, 'الإشعارات', 'Notifications'),
    actions: [
      IconButton(
        icon: const Icon(Icons.settings_outlined),
        onPressed: () => context.push('/notification-preferences'),
      ),
    ],
    child: ResourceList(
      path: 'notifications',
      field: 'notifications',
      itemBuilder: (context, row, reload) {
        final en = Localizations.localeOf(context).languageCode == 'en';
        return DataCard(
          title: text(en ? row['title_en'] ?? row['title'] : row['title']),
          subtitle:
              '${text(en ? row['body_en'] ?? row['body'] : row['body'])}\n${text(row['created_at'])}',
          trailing: '${row['is_read']}' == '1'
              ? null
              : const Icon(Icons.circle, size: 10),
          onTap: () async {
            try {
              await ref
                  .read(apiProvider)
                  .request(
                    'notifications/${row['id']}/read',
                    method: 'PATCH',
                    key: operationKey(),
                  );
              reload();
            } catch (error) {
              if (context.mounted) showFailure(context, error);
              return;
            }
            if (!context.mounted) return;
            final route = row['route'];
            if (route is Map) {
              final target = notificationTarget(
                Map<String, dynamic>.from(route),
                ref.read(sessionProvider).asData?.value?.role,
              );
              if (target != null && target != '/notifications') {
                context.push(target);
              }
            }
          },
        );
      },
    ),
  );
}

class NotificationPreferencesScreen extends ConsumerStatefulWidget {
  const NotificationPreferencesScreen({super.key});
  @override
  ConsumerState<NotificationPreferencesScreen> createState() =>
      _NotificationPreferencesScreenState();
}

class _NotificationPreferencesScreenState
    extends ConsumerState<NotificationPreferencesScreen> {
  late Future<Map<String, dynamic>> _future;
  @override
  void initState() {
    super.initState();
    reload();
  }

  void reload() {
    setState(
      () => _future = ref
          .read(apiProvider)
          .request('me/notification-preferences'),
    );
  }

  Future<void> save(Map value, String key, dynamic selected) async {
    try {
      await ref
          .read(apiProvider)
          .request(
            'me/notification-preferences',
            method: 'PUT',
            key: operationKey(),
            body: {
              'language': key == 'language'
                  ? selected
                  : value['Language'] ?? 'ar',
              'push_enabled': key == 'push_enabled'
                  ? selected
                  : '${value['PushEnabled']}' == '1',
              'email_enabled': key == 'email_enabled'
                  ? selected
                  : '${value['EmailEnabled']}' == '1',
            },
          );
      reload();
    } catch (error) {
      if (mounted) showFailure(context, error);
    }
  }

  @override
  Widget build(BuildContext context) => ClevoPage(
    title: tr(context, 'تفضيلات الإشعارات', 'Notification preferences'),
    child: FutureBuilder(
      future: _future,
      builder: (context, snapshot) {
        if (snapshot.hasError) return ErrorPanel(snapshot.error!, reload);
        if (!snapshot.hasData) {
          return const Center(child: CircularProgressIndicator());
        }
        final prefs = snapshot.data!['preferences'] as Map? ?? {};
        return ListView(
          padding: const EdgeInsets.all(20),
          children: [
            SwitchListTile(
              title: Text(tr(context, 'إشعارات الجوال', 'Push notifications')),
              value: '${prefs['PushEnabled']}' == '1',
              onChanged: (value) => save(prefs, 'push_enabled', value),
            ),
            SwitchListTile(
              title: Text(
                tr(context, 'البريد الإلكتروني', 'Email notifications'),
              ),
              value: '${prefs['EmailEnabled']}' == '1',
              onChanged: (value) => save(prefs, 'email_enabled', value),
            ),
            DropdownButtonFormField<String>(
              initialValue: prefs['Language'] == 'en' ? 'en' : 'ar',
              items: const [
                DropdownMenuItem(value: 'ar', child: Text('العربية')),
                DropdownMenuItem(value: 'en', child: Text('English')),
              ],
              onChanged: (value) => save(prefs, 'language', value),
            ),
            const SizedBox(height: 20),
            FilledButton(
              onPressed: () async {
                try {
                  await ref.read(messagingProvider).enable();
                  if (context.mounted) {
                    ScaffoldMessenger.of(context).showSnackBar(
                      SnackBar(
                        content: Text(
                          tr(
                            context,
                            'تم تسجيل هذا الجهاز',
                            'This device was registered',
                          ),
                        ),
                      ),
                    );
                  }
                } catch (error) {
                  if (context.mounted) showFailure(context, error);
                }
              },
              child: Text(
                tr(
                  context,
                  'السماح بالإشعارات على هذا الجهاز',
                  'Enable notifications on this device',
                ),
              ),
            ),
          ],
        );
      },
    ),
  );
}
