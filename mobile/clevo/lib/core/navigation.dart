bool publicRoute(String path) =>
    [
      '/login',
      '/register',
      '/register-owner',
      '/recovery',
      '/browse',
      '/startup',
    ].contains(path) ||
    path.startsWith('/station/');
bool roleAllows(String role, String path) {
  if (path == '/unsupported') {
    return !['customer', 'owner', 'driver'].contains(role);
  }
  if (path.startsWith('/owner')) return role == 'owner';
  if (path.startsWith('/driver')) return role == 'driver';
  if (path == '/customer' ||
      path.startsWith('/wallet') ||
      path.startsWith('/payment') ||
      path.startsWith('/plans') ||
      path.startsWith('/subscriptions') ||
      path.startsWith('/addresses') ||
      ['/reviews', '/receipts', '/refunds', '/stations'].contains(path)) {
    return role == 'customer';
  }
  if (path.startsWith('/booking')) return role == 'customer' || role == 'owner';
  return ['customer', 'owner', 'driver'].contains(role);
}
