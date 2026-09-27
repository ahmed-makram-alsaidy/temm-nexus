/// Public types for `backend_sdk_dart` (Platform API v1).
library;

class BackendUser {
  final int id;
  final String name;
  final String email;
  final String? emailVerifiedAt;
  final String createdAt;

  const BackendUser({
    required this.id,
    required this.name,
    required this.email,
    required this.emailVerifiedAt,
    required this.createdAt,
  });

  factory BackendUser.fromJson(Map<String, dynamic> json) => BackendUser(
        id: (json['id'] as num).toInt(),
        name: json['name'] as String,
        email: json['email'] as String,
        emailVerifiedAt: json['email_verified_at'] as String?,
        createdAt: json['created_at'] as String,
      );
}

class AuthSession {
  /// Sanctum token in `id|plain` form.
  final BackendUser user;
  final String token;

  const AuthSession({required this.user, required this.token});
}

class PaginationMeta {
  final int currentPage;
  final int lastPage;
  final int perPage;
  final int total;

  const PaginationMeta({
    required this.currentPage,
    required this.lastPage,
    required this.perPage,
    required this.total,
  });

  factory PaginationMeta.fromJson(Map<String, dynamic> json) => PaginationMeta(
        currentPage: (json['current_page'] as num).toInt(),
        lastPage: (json['last_page'] as num).toInt(),
        perPage: (json['per_page'] as num).toInt(),
        total: (json['total'] as num).toInt(),
      );
}

class Paginated<T> {
  final List<T> data;
  final PaginationMeta meta;

  const Paginated({required this.data, required this.meta});
}

class StorageObject {
  final String bucket;
  final String key;
  final int? size;
  final String? mime;
  final String? url;
  final String? temporaryUrl;

  const StorageObject({
    required this.bucket,
    required this.key,
    this.size,
    this.mime,
    this.url,
    this.temporaryUrl,
  });

  factory StorageObject.fromJson(Map<String, dynamic> json) => StorageObject(
        bucket: json['bucket'] as String,
        key: json['key'] as String,
        size: (json['size'] as num?)?.toInt(),
        mime: json['mime'] as String?,
        url: json['url'] as String?,
        temporaryUrl: json['temporary_url'] as String?,
      );
}

class FunctionResponse<T> {
  final T data;
  final int status;
  final String requestId;
  final int? durationMs;
  final int? version;

  const FunctionResponse({
    required this.data,
    required this.status,
    required this.requestId,
    this.durationMs,
    this.version,
  });
}

class RealtimeEvent<T> {
  final String channel;
  final String event;
  final T data;

  const RealtimeEvent({required this.channel, required this.event, required this.data});
}
