// ignore_for_file: avoid_print
import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:test/test.dart';
import 'package:backend_sdk_dart/backend_sdk_dart.dart';

const _user = {
  'id': 1,
  'name': 'Ada',
  'email': 'ada@example.com',
  'email_verified_at': null,
  'created_at': '2026-09-17T00:00:00Z',
};

http.Response _json(int status, Object body, {Map<String, String>? headers}) =>
    http.Response(jsonEncode(body), status, headers: {
      'content-type': 'application/json',
      ...?headers,
    });

void main() {
  group('auth', () {
    test('login stores the token and me() attaches it', () async {
      String? seenAuth;
      final client = MockClient((req) async {
        if (req.url.path.endsWith('/api/v1/auth/login')) {
          return _json(200, {'user': _user, 'token': '1|plain-token'});
        }
        if (req.url.path.endsWith('/api/v1/user')) {
          seenAuth = req.headers['Authorization'];
          return _json(200, _user);
        }
        return _json(404, {'message': 'Not Found'});
      });
      final backend = BackendClient(baseUrl: 'https://api.example.com', httpClient: client);
      final session = await backend.auth.login(email: 'ada@example.com', password: 'password123');
      expect(session.token, '1|plain-token');
      expect(await backend.auth.getToken(), '1|plain-token');
      final me = await backend.auth.me();
      expect(me.email, 'ada@example.com');
      expect(seenAuth, 'Bearer 1|plain-token');
    });

    test('logout clears the local token even when the server call fails', () async {
      final client = MockClient((_) async => _json(401, {'message': 'Unauthenticated.'}));
      final backend = BackendClient(baseUrl: 'https://api.example.com', httpClient: client);
      await backend.auth.setToken('1|stale');
      await expectLater(backend.auth.logout(), throwsA(isA<BackendException>()));
      expect(await backend.auth.getToken(), isNull);
    });

    test('maps 401/422/429 to stable codes with request ids', () async {
      final client = MockClient((req) async {
        if (req.url.path.endsWith('/api/v1/auth/login')) {
          return _json(422, {
            'message': 'Validation failed',
            'errors': {
              'email': ['required']
            }
          }, headers: {'x-request-id': 'req_1'});
        }
        if (req.url.path.endsWith('/api/v1/user')) {
          return _json(401, {'message': 'Unauthenticated.'}, headers: {'x-request-id': 'req_2'});
        }
        return http.Response('slow', 429, headers: {'retry-after': '2', 'x-request-id': 'req_3'});
      });
      final backend = BackendClient(baseUrl: 'https://api.example.com', httpClient: client);
      try {
        await backend.auth.login(email: 'x', password: 'y');
        fail('expected BackendException');
      } on BackendException catch (e) {
        expect(e.code, BackendErrorCode.validationError);
        expect(e.errors, {'email': ['required']});
        expect(e.requestId, 'req_1');
      }
      try {
        await backend.auth.me();
        fail('expected BackendException');
      } on BackendException catch (e) {
        expect(e.code, BackendErrorCode.unauthenticated);
        expect(e.requestId, 'req_2');
      }
    });
  });

  group('http', () {
    test('sends X-Request-ID and honors the echoed value', () async {
      String? seen;
      final client = MockClient((req) async {
        seen = req.headers['X-Request-ID'];
        return _json(200, {'ok': true}, headers: {'x-request-id': 'srv_123'});
      });
      final backend = BackendClient(baseUrl: 'https://api.example.com/', httpClient: client);
      final res = await backend.http.request('GET', '/api/health');
      expect(seen, isNotEmpty);
      expect(res.requestId, 'srv_123');
    });

    test('redacts secrets from headers', () {
      final out = redactHeaders({
        'Authorization': 'Bearer 1|secret',
        'X-API-Key': 'cp_x.y',
        'Content-Type': 'application/json',
      });
      expect(out['Authorization'], '[REDACTED]');
      expect(out['X-API-Key'], '[REDACTED]');
      expect(out['Content-Type'], 'application/json');
    });

    test('MemoryTokenStore round-trips', () async {
      final s = MemoryTokenStore();
      expect(await s.get(), isNull);
      await s.set('a|b');
      expect(await s.get(), 'a|b');
      await s.clear();
      expect(await s.get(), isNull);
    });

    test('never embeds credential material in BackendException', () async {
      final client = MockClient((_) async => _json(401, {'message': 'Invalid API key.'}));
      final backend = BackendClient(
        baseUrl: 'https://api.example.com',
        apiKey: 'cp_abc.SUPERSECRET',
        httpClient: client,
      );
      try {
        await backend.http.request('GET', '/api/v1/users');
        fail('expected BackendException');
      } on BackendException catch (e) {
        expect(e.toString(), isNot(contains('SUPERSECRET')));
      }
    });
  });

  group('functions', () {
    test('invoke hits /f/{project}/{slug} with the request id', () async {
      Uri? seen;
      final client = MockClient((req) async {
        seen = req.url;
        return _json(200, {'greeting': 'hello'}, headers: {'x-request-id': 'freq_1'});
      });
      final backend = BackendClient(
        baseUrl: 'https://api.example.com',
        functionsBaseUrl: 'https://console.test',
        projectSlug: 'acme',
        httpClient: client,
      );
      final res = await backend.functions.invoke('hello-platform', body: {'name': 'Ada'});
      expect(seen.toString(), 'https://console.test/f/acme/hello-platform');
      expect(res.status, 200);
      expect(res.requestId, 'freq_1');
      expect((res.data as Map)['greeting'], 'hello');
    });

    test('invoke without projectSlug throws a clear error', () {
      final backend = BackendClient(baseUrl: 'https://api.example.com');
      expect(() => backend.functions.invoke('x'), throwsStateError);
    });
  });

  group('realtime', () {
    test('rejects invalid channels and private channels without a key', () async {
      final backend = BackendClient(baseUrl: 'https://api.example.com', realtimeWsUrl: 'ws://r.test/app/k');
      await expectLater(
        backend.realtime.subscribe(channel: 'bad channel!', onEvent: (_) {}),
        throwsA(isA<BackendException>()),
      );
      await expectLater(
        backend.realtime.subscribe(channel: 'private-orders', onEvent: (_) {}),
        throwsA(isA<BackendException>()),
      );
    });

    test('throws a clear error when realtime is not configured', () {
      final backend = BackendClient(baseUrl: 'https://api.example.com');
      expect(
        backend.realtime.subscribe(channel: 'orders', onEvent: (_) {}),
        throwsA(isA<StateError>()),
      );
    });
  });
}
