/// Official Dart/Flutter client for the Laravel Backend Platform (API v1).
library;

import 'package:http/http.dart' as http;

import 'src/auth.dart';
import 'src/functions.dart';
import 'src/http_client.dart';
import 'src/realtime.dart' show ChannelFactory;
import 'src/realtime.dart' as rt;
import 'src/storage.dart';
import 'src/token_store.dart';
import 'src/users.dart';

export 'src/api_error.dart' show BackendException, BackendErrorCode, toBackendException, redactHeaders;
export 'src/auth.dart' show AuthModule;
export 'src/functions.dart' show FunctionsModule;
export 'src/http_client.dart' show BackendHttp, qs;
export 'src/realtime.dart' show RealtimeModule, Subscription, ChannelFactory;
export 'src/storage.dart' show StorageModule;
export 'src/token_store.dart' show TokenStore, MemoryTokenStore, SecureTokenStoreAdapter;
export 'src/types.dart'
    show BackendUser, AuthSession, PaginationMeta, Paginated, StorageObject, FunctionResponse, RealtimeEvent;
export 'src/users.dart' show UsersModule;

/// ```dart
/// final backend = BackendClient(baseUrl: 'https://api.example.com', apiKey: 'cp_…');
/// await backend.auth.login(email: ..., password: ...);
/// final me = await backend.auth.me();
/// ```
///
/// Thin by design: every method maps to one documented HTTP/WebSocket call
/// (see docs/CLIENT_API_CONTRACT.md), so failures are debuggable with the
/// request ID on the thrown [BackendException].
class BackendClient {
  final BackendHttp http;
  final BackendHttp functionsHttp;
  late final AuthModule auth;
  late final UsersModule users;
  late final StorageModule storage;
  late final FunctionsModule functions;
  late final rt.RealtimeModule realtime;
  final String? projectSlug;

  BackendClient._({
    required this.http,
    required this.functionsHttp,
    required this.projectSlug,
    String? realtimeWsUrl,
    ChannelFactory? channelFactory,
  }) {
    auth = AuthModule(http);
    users = UsersModule(http);
    storage = StorageModule(http);
    functions = FunctionsModule(functionsHttp, projectSlug);
    realtime = rt.RealtimeModule(wsUrl: realtimeWsUrl, factory: channelFactory);
  }

  /// One shared [TokenStore] backs both the API and functions transports,
  /// so `auth.login()` credentials apply to `functions.invoke()` too.
  factory BackendClient({
    required String baseUrl,
    String? apiKey,
    bool apiKeyAsHeader = false,
    TokenStore? tokenStore,
    Duration timeout = const Duration(seconds: 15),
    http.Client? httpClient,
    Map<String, String>? headers,
    String? functionsBaseUrl,
    String? projectSlug,
    String? realtimeWsUrl,
    ChannelFactory? channelFactory,
  }) {
    final store = tokenStore ?? MemoryTokenStore();
    return BackendClient._(
      http: BackendHttp(
        baseUrl: baseUrl,
        apiKey: apiKey,
        apiKeyAsHeader: apiKeyAsHeader,
        timeout: timeout,
        inner: httpClient,
        tokenStore: store,
        defaultHeaders: headers,
      ),
      functionsHttp: BackendHttp(
        baseUrl: functionsBaseUrl ?? baseUrl,
        apiKey: apiKey,
        apiKeyAsHeader: apiKeyAsHeader,
        timeout: timeout,
        inner: httpClient,
        tokenStore: store,
        defaultHeaders: headers,
      ),
      projectSlug: projectSlug,
      realtimeWsUrl: realtimeWsUrl,
      channelFactory: channelFactory,
    );
  }

  /// Health probe (`GET /api/health`). Never throws auth errors.
  Future<Map<String, dynamic>> health() async {
    final res = await http.request('GET', '/api/health');
    return (res.data as Map).cast<String, dynamic>();
  }

  void setApiKey(String? key) {
    http.apiKey = key;
    functionsHttp.apiKey = key;
  }
}
