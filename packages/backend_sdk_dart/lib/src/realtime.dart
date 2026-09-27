/// Minimal Pusher-protocol client for Reverb over `web_socket_channel`.
library;

import 'dart:async';
import 'dart:convert';
import 'dart:math';

import 'package:web_socket_channel/web_socket_channel.dart';

import 'api_error.dart';
import 'types.dart';

final _channelRe = RegExp(r'^[A-Za-z0-9_.:\-]{1,160}$');

typedef ChannelFactory = WebSocketChannel Function(Uri uri);

WebSocketChannel _defaultFactory(Uri uri) => WebSocketChannel.connect(uri);

class Subscription {
  final String channel;
  final Future<void> Function() _cancel;
  bool _active = true;

  Subscription(this.channel, this._cancel);

  bool get active => _active;

  Future<void> unsubscribe() async {
    _active = false;
    await _cancel();
  }
}

/// Realtime facade: connect / subscribe / unsubscribe / reconnect.
/// No duplicate subscriptions: subscribing to an active channel returns the
/// existing handle.
class RealtimeModule {
  String? _wsUrl;
  ChannelFactory _factory;
  final int maxReconnectAttempts;

  WebSocketChannel? _channel;
  String? _socketId;
  Future<void>? _connecting;
  int _reconnectAttempts = 0;
  final _subs = <String, void Function(RealtimeEvent<dynamic>)>{};
  final _filters = <String, String?>{};
  final _keys = <String, String?>{};

  RealtimeModule({String? wsUrl, ChannelFactory? factory, this.maxReconnectAttempts = 5})
      : _wsUrl = wsUrl,
        _factory = factory ?? _defaultFactory;

  void configure({String? wsUrl, ChannelFactory? factory}) {
    if (wsUrl != null) _wsUrl = wsUrl;
    if (factory != null) _factory = factory;
  }

  List<String> activeChannels() => _subs.keys.toList();

  Future<Subscription> subscribe<T>({
    required String channel,
    String? event,
    required void Function(RealtimeEvent<T>) onEvent,
    String? apiKey,
  }) async {
    final ch = channel.trim();
    if (!_channelRe.hasMatch(ch)) {
      throw const BackendException(message: 'Invalid channel name.', code: BackendErrorCode.badRequest, status: 400);
    }
    if (_subs.containsKey(ch)) return Subscription(ch, () => unsubscribe(ch));
    if ((ch.startsWith('private-') || ch.startsWith('presence-')) && apiKey == null) {
      throw const BackendException(
        message: 'Private channels require a project API key.',
        code: BackendErrorCode.unauthenticated,
        status: 401,
      );
    }
    _subs[ch] = (e) => onEvent(RealtimeEvent<T>(channel: e.channel, event: e.event, data: e.data as T));
    _filters[ch] = event;
    _keys[ch] = apiKey;
    final wasOpen = _channel != null;
    try {
      await connect();
    } catch (_) {
      _subs.remove(ch);
      _filters.remove(ch);
      _keys.remove(ch);
      rethrow;
    }
    if (wasOpen) _sendSubscribe(ch);
    return Subscription(ch, () => unsubscribe(ch));
  }

  Future<void> unsubscribe(String channel) async {
    if (!_subs.containsKey(channel)) return;
    _subs.remove(channel);
    _filters.remove(channel);
    _keys.remove(channel);
    _channel?.sink.add(jsonEncode({
      'event': 'pusher:unsubscribe',
      'data': {'channel': channel},
    }));
    if (_subs.isEmpty) disconnect();
  }

  Future<void> unsubscribeAll() async {
    for (final c in _subs.keys.toList()) {
      await unsubscribe(c);
    }
  }

  Future<void> connect() {
    if (_channel != null) return Future.value();
    final pending = _connecting;
    if (pending != null) return pending;
    final url = _wsUrl;
    if (url == null || url.isEmpty) {
      return Future.error(StateError('Realtime not configured: pass wsUrl to BackendClient.'));
    }
    final completer = Completer<void>();
    _connecting = completer.future;
    try {
      final ch = _factory(Uri.parse(url));
      _channel = ch;
      ch.stream.listen(
        (raw) => _onMessage(raw, completer),
        onError: (_) => _onDrop(completer),
        onDone: () => _onDrop(completer),
        cancelOnError: false,
      );
      Future.delayed(const Duration(seconds: 10), () {
        if (!completer.isCompleted) {
          completer.completeError(const BackendException(
            message: 'Realtime connection timed out',
            code: BackendErrorCode.timeout,
            status: 0,
          ));
        }
      });
    } catch (e) {
      _connecting = null;
      completer.completeError(e);
    }
    return completer.future;
  }

  void disconnect() {
    try {
      _channel?.sink.close();
    } catch (_) {
      // ignore
    }
    _channel = null;
    _socketId = null;
    _connecting = null;
  }

  void _onMessage(dynamic raw, Completer<void> completer) {
    Map<String, dynamic> frame;
    try {
      frame = jsonDecode(raw as String) as Map<String, dynamic>;
    } catch (_) {
      return;
    }
    final data = frame['data'] is String
        ? _safeJson(frame['data'] as String)
        : frame['data'];
    if (frame['event'] == 'pusher:connection_established') {
      _socketId = (data is Map ? data['socket_id'] : null)?.toString();
      _reconnectAttempts = 0;
      _connecting = null;
      for (final ch in _subs.keys) {
        _sendSubscribe(ch);
      }
      if (!completer.isCompleted) completer.complete();
      return;
    }
    if (frame['event'] == 'pusher:error') {
      _connecting = null;
      if (!completer.isCompleted) {
        completer.completeError(const BackendException(
          message: 'Realtime connection refused',
          code: BackendErrorCode.unauthenticated,
          status: 401,
        ));
      }
      return;
    }
    // Protocol-internal frames (e.g. pusher_internal:subscription_succeeded)
    // are transport bookkeeping, never application events.
    final String evt = (frame['event'] ?? '').toString();
    if (evt.startsWith('pusher:') || evt.startsWith('pusher_internal:')) return;
    final String? channel = frame['channel'] as String?;
    final targets = channel != null
        ? (_subs.containsKey(channel) ? [channel] : const <String>[])
        : _subs.keys.toList();
    for (final ch in targets) {
      final want = _filters[ch];
      if (want != null && want != evt) continue;
      final payload = (data is Map && data.containsKey('message')) ? data['message'] : data;
      _subs[ch]?.call(RealtimeEvent<dynamic>(channel: ch, event: evt, data: payload));
    }
  }

  void _onDrop(Completer<void> completer) {
    _channel = null;
    _socketId = null;
    _connecting = null;
    if (!completer.isCompleted) {
      completer.completeError(const BackendException(
        message: 'Realtime connection closed',
        code: BackendErrorCode.networkError,
        status: 0,
      ));
    }
    unawaited(_maybeReconnect());
  }

  void _sendSubscribe(String channel) {
    final ch = _channel;
    if (ch == null) return;
    final auth = _keys[channel];
    ch.sink.add(jsonEncode({
      'event': 'pusher:subscribe',
      'data': auth != null
          ? {'channel': channel, 'auth': 'key:${auth.substring(0, auth.length > 12 ? 12 : auth.length)}…'}
          : {'channel': channel},
    }));
  }

  Future<void> _maybeReconnect() async {
    if (_subs.isEmpty || _reconnectAttempts >= maxReconnectAttempts) return;
    _reconnectAttempts += 1;
    final delayMs = min(1000 * (1 << (_reconnectAttempts - 1)), 15000) + Random().nextInt(250);
    await Future<void>.delayed(Duration(milliseconds: delayMs));
    if (_subs.isEmpty) return;
    try {
      await connect();
    } catch (_) {
      // Backoff continues on the next drop.
    }
  }

  Object? _safeJson(String s) {
    try {
      return jsonDecode(s);
    } catch (_) {
      return s;
    }
  }
}
