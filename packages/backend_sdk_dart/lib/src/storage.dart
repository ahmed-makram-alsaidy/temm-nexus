/// File storage. Auth: user token or API key with `storage:read/write`.
library;

import 'http_client.dart';
import 'types.dart';

class StorageModule {
  final BackendHttp _http;
  const StorageModule(this._http);

  Future<StorageObject> upload({
    required String bucket,
    required List<int> bytes,
    required String fileName,
    String? key,
    String? mime,
    String visibility = 'private',
  }) async {
    final res = await _http.uploadMultipart(
      path: '/api/v1/storage/${Uri.encodeComponent(bucket)}/upload',
      bytes: bytes,
      fileName: fileName,
      mime: mime,
      fields: {
        if (key != null) 'key': key,
        'visibility': visibility,
      },
    );
    return StorageObject.fromJson(res.data as Map<String, dynamic>);
  }

  /// Full signed-URL download link (signature is the bearer; URL expires).
  Future<String> signedUrl(String bucket, String key, {int expiresInSeconds = 3600}) async {
    final res = await _http.request(
      'POST',
      '/api/v1/storage/${Uri.encodeComponent(bucket)}/signed-url',
      body: {'key': key, 'expires_in': expiresInSeconds},
    );
    return (res.data as Map<String, dynamic>)['url'] as String;
  }

  Future<void> remove(String bucket, String key) async {
    final encoded = key.split('/').map(Uri.encodeComponent).join('/');
    await _http.request('DELETE', '/api/v1/storage/${Uri.encodeComponent(bucket)}/$encoded');
  }
}
