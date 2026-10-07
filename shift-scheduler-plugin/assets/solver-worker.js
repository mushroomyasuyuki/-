/* 自動作成を、画面を止めずに別スレッドで実行する。 */
importScripts('solver.js');
self.onmessage = function (ev) {
  var msg = ev.data;
  try {
    var res = self.SSSolver.solve(msg.input, {
      timeMs: msg.timeMs, proposals: msg.proposals, seed: msg.seed,
      onTick: function (f, cost) { self.postMessage({ type: 'tick', fraction: f, cost: cost }); }
    });
    self.postMessage({ type: 'done', proposals: res });
  } catch (e) {
    self.postMessage({ type: 'error', message: String(e && e.message ? e.message : e) });
  }
};
