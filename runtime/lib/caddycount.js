'use strict';
// The web function's invocation count in Laravel direct mode: Caddy writes one JSON access log line per response to
// its arco_count logger (lib/caddyfile.js), which sends it here over TCP. Each '\n' is one response; the lines are
// never parsed. Nothing here throws into the runtime: a counter that fails to listen only loses the count.
// The port is ephemeral and known before the Caddyfile is written: if Caddy's net output cannot connect, it prints each
// entry (URI, headers, IP) to stderr, and a fixed port could be another process's. Caddy 2.11's net output has no
// option to drop entries instead, so this side never closes a connection on purpose (no timeouts, errors ignored).
const net = require('node:net');

const HOST = '127.0.0.1';
const PORT = 0;
const NEWLINE = 10;

/** Starts the counter; onLine() is called once per line received. Returns the net.Server. */
function startCaddyCount(onLine, { host = HOST, port = PORT, onError = () => {} } = {}) {
  const server = net.createServer((socket) => {
    socket.on('error', () => {});
    socket.on('data', (chunk) => {
      for (let at = chunk.indexOf(NEWLINE); at !== -1; at = chunk.indexOf(NEWLINE, at + 1)) {
        try {
          onLine();
        } catch {
          // Telemetry never fails a request.
        }
      }
    });
  });
  server.on('error', (error) => {
    try {
      onError(error);
    } catch {
      // Same.
    }
  });
  server.listen(port, host);
  return server;
}

/** Resolves { server, port } once the counter listens, or null when it cannot (telemetry then stays out of Caddy). */
function listenCaddyCount(onLine, { host = HOST, port = PORT } = {}) {
  return new Promise((resolve) => {
    let server;
    try {
      server = startCaddyCount(onLine, { host, port, onError: () => resolve(null) });
    } catch {
      resolve(null);
      return;
    }
    server.once('listening', () => {
      const address = server.address();
      resolve(address && typeof address === 'object' ? { server, port: address.port } : null);
    });
  });
}

module.exports = { HOST, PORT, startCaddyCount, listenCaddyCount };
