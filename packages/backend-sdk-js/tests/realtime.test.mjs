import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { BackendClient, ApiError } from '../dist/index.js';

/** Minimal fake WebSocket speaking just enough Pusher framing for the tests. */
function fakeWsFactory(events) {
  const sockets = [];
  const factory = (url) => {
    const sock = {
      url,
      readyState: 0,
      sent: [],
      onopen: null, onmessage: null, onerror: null, onclose: null,
      send(d) { this.sent.push(JSON.parse(d)); },
      close() { this.readyState = 3; },
      serverEmit(obj) { this.onmessage?.({ data: JSON.stringify(obj) }); },
      serverOpen(socketId = '1.1') {
        this.readyState = 1;
        this.serverEmit({ event: 'pusher:connection_established', data: JSON.stringify({ socket_id: socketId }) });
      },
    };
    sockets.push(sock);
    queueMicrotask(() => {
      if (events?.autoOpen !== false) sock.serverOpen();
    });
    return sock;
  };
  return { factory, sockets };
}

describe('realtime', () => {
  it('connects, subscribes, and routes events', async () => {
    const { factory, sockets } = fakeWsFactory();
    const backend = new BackendClient({
      baseUrl: 'https://api.example.com',
      realtime: { wsUrl: 'ws://r.test:8080/app/key?protocol=7', wsFactory: factory },
    });
    const got = [];
    await backend.realtime.subscribe({ channel: 'orders', onEvent: (e) => got.push(e) });
    assert.equal(sockets.length, 1);
    assert.deepEqual(sockets[0].sent, [{ event: 'pusher:subscribe', data: { channel: 'orders' } }]);
    sockets[0].serverEmit({ event: 'order.created', channel: 'orders', data: JSON.stringify({ message: { id: 2 } }) });
    assert.equal(got.length, 1);
    assert.equal(got[0].channel, 'orders');
    assert.deepEqual(got[0].data, { id: 2 });
  });

  it('ignores protocol-internal frames', async () => {
    const { factory, sockets } = fakeWsFactory();
    const backend = new BackendClient({
      baseUrl: 'https://api.example.com',
      realtime: { wsUrl: 'ws://r.test/app/k?protocol=7', wsFactory: factory },
    });
    const got = [];
    await backend.realtime.subscribe({ channel: 'orders', onEvent: (e) => got.push(e) });
    sockets[0].serverEmit({ event: 'pusher_internal:subscription_succeeded', channel: 'orders', data: '{}' });
    sockets[0].serverEmit({ event: 'order.created', channel: 'orders', data: JSON.stringify({ message: { id: 1 } }) });
    assert.equal(got.length, 1);
    assert.equal(got[0].event, 'order.created');
  });

  it('does not create duplicate subscriptions', async () => {
    const { factory, sockets } = fakeWsFactory();
    const backend = new BackendClient({
      baseUrl: 'https://api.example.com',
      realtime: { wsUrl: 'ws://r.test/app/k?protocol=7', wsFactory: factory },
    });
    const noop = () => {};
    await backend.realtime.subscribe({ channel: 'orders', onEvent: noop });
    await backend.realtime.subscribe({ channel: 'orders', onEvent: noop });
    assert.equal(sockets.length, 1);
    assert.deepEqual(backend.realtime.activeChannels(), ['orders']);
  });

  it('rejects invalid channels and private channels without a key', async () => {
    const { factory } = fakeWsFactory();
    const backend = new BackendClient({
      baseUrl: 'https://api.example.com',
      realtime: { wsUrl: 'ws://r.test/app/k?protocol=7', wsFactory: factory },
    });
    await assert.rejects(
      () => backend.realtime.subscribe({ channel: 'bad channel!', onEvent: () => {} }),
      (e) => e instanceof ApiError && e.code === 'BAD_REQUEST',
    );
    await assert.rejects(
      () => backend.realtime.subscribe({ channel: 'private-orders', onEvent: () => {} }),
      (e) => e instanceof ApiError && e.code === 'UNAUTHENTICATED',
    );
  });

  it('unsubscribe sends pusher:unsubscribe', async () => {
    const { factory, sockets } = fakeWsFactory();
    const backend = new BackendClient({
      baseUrl: 'https://api.example.com',
      realtime: { wsUrl: 'ws://r.test/app/k?protocol=7', wsFactory: factory },
    });
    const sub = await backend.realtime.subscribe({ channel: 'orders', onEvent: () => {} });
    await sub.unsubscribe();
    assert.ok(sockets[0].sent.some((s) => s.event === 'pusher:unsubscribe'));
    assert.deepEqual(backend.realtime.activeChannels(), []);
  });

  it('throws a clear error when realtime is not configured', async () => {
    const backend = new BackendClient({ baseUrl: 'https://api.example.com' });
    await assert.rejects(() => backend.realtime.subscribe({ channel: 'orders', onEvent: () => {} }), /wsUrl/);
  });
});
