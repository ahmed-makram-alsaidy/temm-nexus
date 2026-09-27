/// Typed user helpers (auth:sanctum).
library;

import 'http_client.dart';
import 'types.dart';

class UsersModule {
  final BackendHttp _http;
  const UsersModule(this._http);

  Future<BackendUser> get(Object id) async {
    final res = await _http.request('GET', '/api/v1/users/$id');
    final payload = res.data;
    if (payload is Map<String, dynamic>) {
      final inner = payload['data'];
      if (inner is Map<String, dynamic> && inner.containsKey('email')) {
        return BackendUser.fromJson(inner);
      }
      return BackendUser.fromJson(payload);
    }
    throw ArgumentError('Unexpected user payload: $payload');
  }

  Future<Paginated<BackendUser>> list({
    int? page,
    int? perPage,
    String? search,
    Object? sort,
    Map<String, Object>? filter,
  }) async {
    final res = await _http.request(
      'GET',
      '/api/v1/users',
      query: qs(page: page, perPage: perPage, search: search, sort: sort, filter: filter),
    );
    final body = res.data as Map<String, dynamic>;
    final meta = PaginationMeta.fromJson(body['meta'] as Map<String, dynamic>);
    final data = (body['data'] as List)
        .map((e) => BackendUser.fromJson(e as Map<String, dynamic>))
        .toList();
    return Paginated(data: data, meta: meta);
  }
}
